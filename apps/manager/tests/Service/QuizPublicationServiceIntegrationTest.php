<?php

namespace App\Tests\Service;

use App\Controller\QuizAdminController;
use App\Exception\QuizPublicationException;
use App\Repository\ManagerDatabase;
use App\Repository\MigrationRunner;
use App\Repository\QuizContentWriter;
use App\Service\QuizAdministrationService;
use App\Service\QuizContentComparator;
use App\Service\QuizContentStatistics;
use App\Service\PostgresQuizAuthoringService;
use App\Repository\QuizContentRepository;
use App\Service\QuizContentRules;
use App\Service\QuizProjectionPublisher;
use App\Service\QuizProjectionRenderer;
use App\Service\QuizPublicationService;
use App\Tests\Support\FaultInjectingContentStorage;
use App\Storage\ContentStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class QuizPublicationServiceIntegrationTest extends TestCase
{
    private ManagerDatabase $database;
    private QuizContentWriter $writer;
    private QuizPublicationService $publication;
    private FaultInjectingContentStorage $storage;
    private string $theme;

    protected function setUp(): void
    {
        $url = (string) getenv('MANAGER_DATABASE_URL');
        if (!str_starts_with($url, 'postgres://') && !str_starts_with($url, 'postgresql://')) {
            self::markTestSkipped('MANAGER_DATABASE_URL is not PostgreSQL.');
        }
        $this->database = new ManagerDatabase($url);
        (new MigrationRunner($this->database, dirname(__DIR__, 2).'/migrations'))->migrate();
        $this->theme = 'publication-test-'.bin2hex(random_bytes(5));
        $rules = new QuizContentRules('en-US', 'en-US,pt-BR');
        $this->writer = new QuizContentWriter($this->database, $rules);
        $this->storage = new FaultInjectingContentStorage();
        $this->storage->write('ads/ads.json', '{"ads":[]}');
        $this->publication = new QuizPublicationService(
            $this->database,
            new QuizProjectionRenderer($this->database, $rules),
            new QuizProjectionPublisher($this->storage, new QuizContentComparator()),
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->database)) {
            return;
        }
        $db = $this->database->connection();
        $db->prepare('DELETE FROM quiz_themes WHERE id=:theme')->execute(['theme' => $this->theme]);
        $db->prepare('DELETE FROM quiz_publications WHERE affected_theme_id=:theme')->execute(['theme' => $this->theme]);
    }

    public function testAdministrativeApiReturns503WhenDatabaseCommitCannotBePublished(): void
    {
        $rules = new QuizContentRules('en-US', 'en-US,pt-BR');
        $repository = new QuizContentRepository($this->database);
        $packs = new PostgresQuizAuthoringService($repository, $this->writer,
            new QuizContentStatistics($repository, $rules, 10), $rules, $this->publication);
        $controller = new QuizAdminController(new QuizAdministrationService($packs), new NullLogger());
        $this->storage->failOnMutation(1);
        $request = Request::create('/api/admin/quiz/themes', 'POST', content: json_encode([
            'id' => $this->theme, 'name' => 'Study', 'description' => '',
        ], JSON_THROW_ON_ERROR));
        $response = $controller->createTheme($request);
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('publication_failed', $body['error']['code']);
        self::assertSame('failed', $body['publication']['status']);
        self::assertFalse($body['publication']['apiReloadRequired']);
        self::assertGreaterThan(0, $body['publication']['revision']);
        self::assertSame('failed', json_decode((string) $controller->publication()->getContent(), true)['publication']['status']);
        $this->storage->failOnMutation(1000);
        $retry = $controller->retryPublication(Request::create('/api/admin/quiz/publication', 'POST'));
        self::assertSame(200, $retry->getStatusCode());
        self::assertSame('published', json_decode((string) $retry->getContent(), true)['publication']['status']);
    }

    public function testAdministrationCrudPublishesExpectedQuestionObjects(): void
    {
        $rules = new QuizContentRules('en-US', 'en-US,pt-BR');
        $repository = new QuizContentRepository($this->database);
        $packs = new PostgresQuizAuthoringService($repository, $this->writer,
            new QuizContentStatistics($repository, $rules, 10), $rules, $this->publication);
        $admin = new QuizAdministrationService($packs);
        $theme = $admin->createTheme(['id' => $this->theme, 'name' => 'Study', 'description' => '']);
        self::assertSame('published', $theme['publication']['status']);
        $topic = $admin->createTopic($this->theme, [
            'key' => 'php', 'name' => 'PHP', 'description' => '',
            'localizations' => ['pt-BR' => ['name' => 'PHP em Português', 'description' => '']],
        ]);
        self::assertSame('published', $topic['publication']['status']);
        $created = $admin->createQuestions($this->theme, 'php', [
            'difficulty' => 1,
            'questions' => [[
                'translations' => [
                    'en-US' => ['prompt' => 'Question?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C']],
                    'pt-BR' => ['prompt' => 'Pergunta?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C']],
                ],
            ]],
        ]);
        self::assertSame(['php-1-001'], $created['questionIds']);
        self::assertSame('published', $created['publication']['status']);
        $path = $this->theme.'/pt-BR/php/1/php-1-001.json';
        self::assertTrue($this->storage->exists($path));
        self::assertSame('Pergunta?', json_decode($this->storage->read($path), true)['prompt']);
        $updated = $admin->replaceQuestion($this->theme, 'php', 1, 'php-1-001', [
            'translations' => [
                'en-US' => ['prompt' => 'Updated?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C']],
                'pt-BR' => ['prompt' => 'Atualizada?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C']],
            ],
        ]);
        self::assertSame('published', $updated['publication']['status']);
        self::assertSame('Atualizada?', json_decode($this->storage->read($path), true)['prompt']);
        $deleted = $admin->deleteQuestion($this->theme, 'php', 1, 'php-1-001');
        self::assertSame('published', $deleted['publication']['status']);
        self::assertFalse($this->storage->exists($path));
        self::assertSame($this->publication->status()['currentRevision'], $this->publication->status()['publishedRevision']);
    }

    public function testRevisionChangeDuringPublicationRestoresPreviousObjects(): void
    {
        $inner = new FaultInjectingContentStorage();
        $storage = new class($inner) implements ContentStorage {
            public ?\Closure $afterWrite = null;
            public function __construct(private readonly FaultInjectingContentStorage $inner) {}
            public function description(): string { return $this->inner->description(); }
            public function location(string $key): string { return $this->inner->location($key); }
            public function exists(string $key): bool { return $this->inner->exists($key); }
            public function read(string $key): string { return $this->inner->read($key); }
            public function write(string $key, string $contents): void
            {
                $this->inner->write($key, $contents);
                if ($this->afterWrite !== null) {
                    $callback = $this->afterWrite;
                    $this->afterWrite = null;
                    $callback();
                }
            }
            public function delete(string $key): bool { return $this->inner->delete($key); }
            public function list(string $prefix): array { return $this->inner->list($prefix); }
        };
        $rules = new QuizContentRules('en-US', 'en-US,pt-BR');
        $publication = new QuizPublicationService($this->database,
            new QuizProjectionRenderer($this->database, $rules),
            new QuizProjectionPublisher($storage, new QuizContentComparator()));
        $publication->mutate(
            fn (): int => $this->writer->saveTheme(['id' => $this->theme, 'name' => 'Study', 'active' => true]),
            static fn (): array => ['keys' => ['themes.json']],
        );
        $before = $inner->snapshot();
        $storage->afterWrite = fn (): int => $this->writer->saveTheme([
            'id' => $this->theme, 'name' => 'Study', 'active' => true,
        ]);
        try {
            $publication->mutate(
                fn (): array => $this->writer->saveTopicSet($this->theme, ['key' => 'php', 'name' => 'PHP', 'active' => true]),
                fn (): array => ['keys' => [$this->theme.'/index.json']],
            );
            self::fail('Expected publication revision to change.');
        } catch (QuizPublicationException) {
            self::assertSame($before, $inner->snapshot());
        }
    }

    public function testSuccessfulMutationPublishesRevisionAndKeepsAds(): void
    {
        $result = $this->publication->mutate(
            fn (): int => $this->writer->saveTheme(['id' => $this->theme, 'name' => 'Study', 'active' => true]),
            static fn (): array => ['keys' => ['themes.json']],
        );
        self::assertSame('published', $result['publication']['status']);
        self::assertTrue($result['publication']['apiReloadRequired']);
        self::assertSame($result['result'], $this->publication->status()['publishedRevision']);
        self::assertTrue($this->storage->exists('themes.json'));
        self::assertSame('{"ads":[]}', $this->storage->read('ads/ads.json'));
    }

    public function testFullRepublishRestoresDeletedQuizObjectsWithoutChangingRevision(): void
    {
        $this->publication->mutate(
            fn (): int => $this->writer->saveTheme(['id' => $this->theme, 'name' => 'Study', 'active' => true]),
            static fn (): array => ['keys' => ['themes.json']],
        );
        $this->storage->delete('themes.json');
        $revision = $this->publication->status()['currentRevision'];

        $result = $this->publication->publishCurrent();

        self::assertSame('published', $result['status']);
        self::assertSame($revision, $result['revision']);
        self::assertTrue($this->storage->exists('themes.json'));
        self::assertSame($revision, $this->publication->status()['publishedRevision']);
        self::assertSame('{"ads":[]}', $this->storage->read('ads/ads.json'));
    }

    public function testThemeScopedPublicationRejectsUnrelatedPendingRevision(): void
    {
        $this->writer->saveTheme(['id' => $this->theme, 'name' => 'Study', 'active' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('run full publication');
        $this->publication->publishCurrent('another-theme');
    }

    public function testFailedPublicationLeavesDurablePendingContentAndRetryPublishesIt(): void
    {
        $this->publication->mutate(
            fn (): int => $this->writer->saveTheme(['id' => $this->theme, 'name' => 'Study', 'active' => true]),
            static fn (): array => ['keys' => ['themes.json']],
        );
        $before = $this->storage->snapshot();
        $this->storage->failOnMutation(1);
        try {
            $this->publication->mutate(
                fn (): array => $this->writer->saveTopicSet($this->theme, ['key' => 'php', 'name' => 'PHP', 'active' => true]),
                fn (): array => ['keys' => [$this->theme.'/index.json']],
            );
            self::fail('Expected publication failure.');
        } catch (QuizPublicationException $error) {
            self::assertGreaterThan(0, $error->revision);
        }
        self::assertSame($before, $this->storage->snapshot());
        $status = $this->publication->status();
        self::assertSame('failed', $status['status']);
        self::assertSame($status['publishedRevision'] + 1, $status['currentRevision']);
        self::assertSame('Quiz storage publication failed.', $status['failureSummary']);
        self::assertSame(1, (int) $this->database->connection()->query(
            "SELECT COUNT(*) FROM quiz_topics WHERE theme_id='".$this->theme."'",
        )->fetchColumn());

        $this->storage->failOnMutation(1000);
        $retry = $this->publication->publishPending();
        self::assertSame('published', $retry['status']);
        self::assertSame($status['currentRevision'], $this->publication->status()['publishedRevision']);
        self::assertTrue($this->storage->exists($this->theme.'/index.json'));
        self::assertSame('{"ads":[]}', $this->storage->read('ads/ads.json'));
    }
}
