<?php

namespace App\Tests\Service;

use App\Controller\QuizAdminController;
use App\Exception\QuizDatabaseException;
use App\Exception\QuizPublicationException;
use App\Repository\ManagerDatabase;
use App\Repository\MigrationRunner;
use App\Repository\QuizContentRepository;
use App\Repository\QuizContentWriter;
use App\Service\PostgresQuizAuthoringService;
use App\Service\QuizAdministrationService;
use App\Service\QuizContentComparator;
use App\Service\QuizContentRules;
use App\Service\QuizContentStatistics;
use App\Service\QuizProjectionPublisher;
use App\Service\QuizProjectionRenderer;
use App\Service\QuizPublicationService;
use App\Tests\Support\FaultInjectingContentStorage;
use App\Tests\Support\RecordingContentStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class TopicTagsIntegrationTest extends TestCase
{
    private ManagerDatabase $database;
    private QuizContentWriter $writer;
    private QuizContentRepository $reader;
    private QuizProjectionRenderer $renderer;
    private QuizPublicationService $publication;
    private PostgresQuizAuthoringService $packs;
    private QuizAdministrationService $admin;
    private RecordingContentStorage $storage;
    private FaultInjectingContentStorage $objects;
    private string $theme;

    protected function setUp(): void
    {
        $url = (string) getenv('MANAGER_DATABASE_URL');
        if (!str_starts_with($url, 'postgres://') && !str_starts_with($url, 'postgresql://')) {
            self::markTestSkipped('MANAGER_DATABASE_URL is not PostgreSQL.');
        }
        $this->database = new ManagerDatabase($url);
        (new MigrationRunner($this->database, dirname(__DIR__, 2).'/migrations'))->migrate();
        $this->theme = 'tags-'.bin2hex(random_bytes(5));
        $rules = new QuizContentRules('en-US', 'en-US,pt-BR');
        $this->writer = new QuizContentWriter($this->database, $rules);
        $this->reader = new QuizContentRepository($this->database);
        $this->renderer = new QuizProjectionRenderer($this->database, $rules);
        $this->objects = new FaultInjectingContentStorage();
        $this->storage = new RecordingContentStorage($this->objects);
        $this->publication = new QuizPublicationService($this->database, $this->renderer,
            new QuizProjectionPublisher($this->storage, new QuizContentComparator()));
        $this->packs = new PostgresQuizAuthoringService($this->reader, $this->writer,
            new QuizContentStatistics($this->reader, $rules, 10), $rules, $this->publication, fixedTheme: $this->theme);
        $this->admin = new QuizAdministrationService($this->packs);
        $this->admin->createTheme(['id' => $this->theme, 'name' => 'Tag tests']);
        $this->admin->createTopic($this->theme, ['key' => 'aws', 'name' => 'AWS', 'description' => 'Cloud',
            'tags' => [' AWS ', 'redes', 'aws'],
            'localizations' => ['pt-BR' => ['name' => 'Nuvem', 'description' => 'Cloud']],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $db = $this->database->connection();
            $db->prepare('DELETE FROM quiz_themes WHERE id IN (:theme,:other)')->execute(['theme' => $this->theme, 'other' => $this->theme.'-other']);
            $db->prepare('DELETE FROM quiz_publications WHERE affected_theme_id IN (:theme,:other)')->execute(['theme' => $this->theme, 'other' => $this->theme.'-other']);
        }
    }

    public function testTagsOnlySaveHasNoStorageCallsOrPublicationChanges(): void
    {
        $before = $this->publicationSnapshot();
        $projection = $this->renderer->render();
        $bytes = $this->objects->snapshot();
        $this->storage->calls = 0;
        $this->storage->unavailable = true;
        foreach ([['seguranca', 'AWS', '123'], ['123', 'aws', 'seguranca'], []] as $tags) {
            $result = $this->admin->replaceTopic($this->theme, 'aws', $this->topicInput(['tags' => $tags]));
            self::assertSame(['apiReloadRequired' => false, 'reason' => 'topic_tags_only'], $result['publication']);
            self::assertSame($before, $this->publicationSnapshot());
        }
        self::assertSame([], $this->admin->topic($this->theme, 'aws')['tags']);
        self::assertSame(0, $this->storage->calls);
        self::assertSame($projection, $this->renderer->render());
        self::assertSame($bytes, $this->objects->snapshot());
    }

    public function testAllTopicReadSurfacesReturnTagsAndPublicationExcludesThem(): void
    {
        foreach (['en-US', 'pt-BR'] as $locale) {
            self::assertSame(['aws', 'redes'], $this->admin->topic($this->theme, 'aws', $locale)['tags']);
            self::assertSame(['aws', 'redes'], $this->admin->topics($this->theme, $locale)[0]['tags']);
            $themes = array_column($this->admin->catalog($locale)['themes'], null, 'id');
            self::assertSame(['aws', 'redes'], $themes[$this->theme]['topics'][0]['tags']);
        }
        $fresh = new QuizContentRepository(new ManagerDatabase((string) getenv('MANAGER_DATABASE_URL')));
        self::assertSame(['aws', 'redes'], $fresh->topics($this->theme, 'pt-BR', 'en-US')[0]['tags']);
        self::assertArrayNotHasKey('tags', $this->packs->readCentralCatalog()['topics'][0]);
        $this->publication->publishCurrent();
        foreach ($this->objects->snapshot() as $contents) {
            self::assertStringNotContainsString('"tags"', $contents);
        }
    }

    public function testOmissionAndLocalizationSavesPreserveTags(): void
    {
        $before = $this->publication->status()['currentRevision'];
        $result = $this->admin->replaceTopic($this->theme, 'aws', $this->topicInput(['name' => 'Cloud services']));
        self::assertTrue($result['publication']['apiReloadRequired']);
        self::assertSame($before + 1, $result['publication']['revision']);
        self::assertSame(['aws', 'redes'], $result['topic']['tags']);
        $this->packs->saveLocalizedTopic('pt-BR', ['key' => 'aws', 'name' => 'Serviços']);
        self::assertSame(['aws', 'redes'], $this->admin->topic($this->theme, 'aws', 'pt-BR')['tags']);
        self::assertSame('Serviços', $this->admin->topic($this->theme, 'aws', 'pt-BR')['displayName']);
        $this->admin->createTopic($this->theme, ['key' => 'empty', 'name' => 'Empty', 'description' => '']);
        self::assertSame([], $this->admin->topic($this->theme, 'empty')['tags']);
    }

    public function testTagsAndTranslationsShareTheExistingPublicationFlow(): void
    {
        $result = $this->admin->replaceTopic($this->theme, 'aws', $this->topicInput([
            'tags' => ['seguranca'], 'localizations' => ['pt-BR' => ['name' => 'Segurança']],
        ]));
        self::assertTrue($result['publication']['apiReloadRequired']);
        self::assertSame(['seguranca'], $result['topic']['tags']);
        self::assertSame('Segurança', $this->admin->topic($this->theme, 'aws', 'pt-BR')['displayName']);
        $this->storage->calls = 0;
        $same = $this->admin->replaceTopic($this->theme, 'aws', $this->topicInput([
            'tags' => ['seguranca'], 'localizations' => ['pt-BR' => ['name' => 'Segurança']],
        ]));
        self::assertFalse($same['publication']['apiReloadRequired']);
        self::assertSame(0, $this->storage->calls);
    }

    public function testFailedPublicationIsNotRetriedOrClearedByTagSave(): void
    {
        $this->objects->failOnMutation(1);
        try {
            $this->admin->replaceTopic($this->theme, 'aws', $this->topicInput(['name' => 'Changed', 'tags' => ['durable']]));
            self::fail('Expected publication failure.');
        } catch (QuizPublicationException) {
            self::assertSame(['durable'], $this->admin->topic($this->theme, 'aws')['tags']);
        }
        $before = $this->publicationSnapshot();
        self::assertSame('failed', $this->publication->status()['status']);
        $this->storage->calls = 0;
        $this->storage->unavailable = true;
        $result = $this->admin->replaceTopic($this->theme, 'aws', $this->topicInput(['tags' => ['later']]));
        self::assertFalse($result['publication']['apiReloadRequired']);
        self::assertSame($before, $this->publicationSnapshot());
        self::assertSame(0, $this->storage->calls);
    }

    public function testMalformedTagPayloadCannotChangeTopicOrPublication(): void
    {
        $controller = new QuizAdminController($this->admin, new NullLogger());
        $before = $this->publicationSnapshot();
        foreach ([null, 'aws', (object) [], (object) ['0' => 'aws'], [42], ['aws redes'], array_fill(0, 21, 'aws')] as $tags) {
            $request = Request::create('/', 'PUT', content: json_encode($this->topicInput(['name' => 'Changed', 'tags' => $tags]), JSON_THROW_ON_ERROR));
            $response = $controller->replaceTopic($request, $this->theme, 'aws');
            self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame('validation_failed', json_decode((string) $response->getContent(), true)['error']['code']);
        }
        self::assertSame('AWS', $this->admin->topic($this->theme, 'aws')['name']);
        self::assertSame(['aws', 'redes'], $this->admin->topic($this->theme, 'aws')['tags']);
        self::assertSame($before, $this->publicationSnapshot());
    }

    public function testDatabaseFailureRollsBackMetadataTagsAndDictionary(): void
    {
        $db = $this->database->connection();
        $trigger = 'tags_failure_'.bin2hex(random_bytes(5));
        $rejectedTag = 'rejected-'.bin2hex(random_bytes(5));
        $before = $this->publicationSnapshot();
        $db->exec("CREATE FUNCTION $trigger() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN IF NEW.tag_slug = '$rejectedTag' THEN RAISE EXCEPTION 'injected tag failure'; END IF; RETURN NEW; END $$");
        $db->exec("CREATE TRIGGER $trigger BEFORE INSERT ON quiz_topic_tags FOR EACH ROW EXECUTE FUNCTION $trigger()");
        try {
            $this->admin->replaceTopic($this->theme, 'aws', $this->topicInput([
                'description' => 'Changed', 'tags' => [$rejectedTag],
                'localizations' => ['pt-BR' => ['name' => 'Changed']],
            ]));
            self::fail('Expected database failure.');
        } catch (QuizDatabaseException) {
            self::assertSame('Cloud', $this->admin->topic($this->theme, 'aws')['description']);
            self::assertSame('Nuvem', $this->admin->topic($this->theme, 'aws', 'pt-BR')['displayName']);
            self::assertSame(['aws', 'redes'], $this->admin->topic($this->theme, 'aws')['tags']);
            $query = $db->prepare('SELECT COUNT(*) FROM quiz_tags WHERE slug=:slug');
            $query->execute(['slug' => $rejectedTag]);
            self::assertSame(0, (int) $query->fetchColumn());
            self::assertSame($before, $this->publicationSnapshot());
        } finally {
            $db->exec("DROP TRIGGER $trigger ON quiz_topic_tags");
            $db->exec("DROP FUNCTION $trigger()");
        }
    }

    public function testSharedIdentityDeletionAndThemeIsolation(): void
    {
        $other = $this->theme.'-other';
        $this->admin->createTheme(['id' => $other, 'name' => 'Other']);
        $this->admin->createTopic($other, ['key' => 'aws', 'name' => 'Other AWS', 'description' => '', 'tags' => ['aws']]);
        self::assertSame(1, (int) $this->database->connection()->query("SELECT COUNT(*) FROM quiz_tags WHERE slug='aws'")->fetchColumn());
        $this->admin->replaceTopic($this->theme, 'aws', $this->topicInput(['tags' => []]));
        self::assertSame(['aws'], $this->admin->topic($other, 'aws')['tags']);
        $this->admin->deleteTopic($this->theme, 'aws', false);
        self::assertSame(['aws'], $this->admin->topic($other, 'aws')['tags']);
        $this->admin->deleteTheme($other, true);
        $query = $this->database->connection()->prepare('SELECT COUNT(*) FROM quiz_topic_tags WHERE theme_id IN (:theme,:other)');
        $query->execute(['theme' => $this->theme, 'other' => $other]);
        self::assertSame(0, (int) $query->fetchColumn());
    }

    private function topicInput(array $changes = []): array
    {
        $topic = $this->admin->topic($this->theme, 'aws');
        unset($topic['tags']);
        return array_replace($topic, $changes);
    }

    private function publicationSnapshot(): array
    {
        $db = $this->database->connection();
        return [
            $db->query('SELECT * FROM quiz_catalog_state')->fetchAll(),
            $db->query('SELECT * FROM quiz_publications ORDER BY id')->fetchAll(),
        ];
    }
}
