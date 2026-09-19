<?php

namespace App\Tests\Service;

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
use App\Storage\ContentStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PostgresQuizAuthoringServiceIntegrationTest extends TestCase
{
    private ManagerDatabase $database;
    private string $theme;
    private PostgresQuizAuthoringService $packs;

    protected function setUp(): void
    {
        $url = (string) getenv('MANAGER_DATABASE_URL');
        if (!str_starts_with($url, 'postgres://') && !str_starts_with($url, 'postgresql://')) {
            self::markTestSkipped('MANAGER_DATABASE_URL is not PostgreSQL.');
        }
        $this->theme = 'read-test-'.bin2hex(random_bytes(5));
        $this->database = new ManagerDatabase($url);
        (new MigrationRunner($this->database, dirname(__DIR__, 2).'/migrations'))->migrate();
        $this->database->connection()->beginTransaction();
        $rules = new QuizContentRules('en-US', 'en-US,pt-BR');
        $repository = new QuizContentRepository($this->database);
        $writer = new QuizContentWriter($this->database, $rules);
        $writer->saveTheme(['id' => $this->theme, 'name' => 'Read test', 'active' => true]);
        $writer->saveTopicSet($this->theme, ['key' => 'php', 'name' => 'PHP', 'active' => true], [
            'pt-BR' => ['name' => 'PHP em Português', 'description' => 'Conceitos'],
        ]);
        $writer->createLocalizedQuestionSets($this->theme, 'php', 1, [[
            'id' => 'php-1-001',
            'translations' => [
                'en-US' => ['prompt' => 'Question?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C']],
                'pt-BR' => ['prompt' => 'Pergunta?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C']],
            ],
        ]]);
        $storage = new class implements ContentStorage {
            public function description(): string { return 'forbidden://quiz'; }
            public function location(string $key): string { throw new RuntimeException('ContentStorage was used.'); }
            public function exists(string $key): bool { throw new RuntimeException('ContentStorage was used.'); }
            public function read(string $key): string { throw new RuntimeException('ContentStorage was used.'); }
            public function write(string $key, string $contents): void { throw new RuntimeException('ContentStorage was used.'); }
            public function delete(string $key): bool { throw new RuntimeException('ContentStorage was used.'); }
            public function list(string $prefix): array { throw new RuntimeException('ContentStorage was used.'); }
        };
        $publication = new QuizPublicationService($this->database,
            new QuizProjectionRenderer($this->database, $rules),
            new QuizProjectionPublisher($storage, new QuizContentComparator()));
        $this->packs = new PostgresQuizAuthoringService($repository, $writer,
            new QuizContentStatistics($repository, $rules, 10), $rules, $publication, fixedTheme: $this->theme);
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->connection()->inTransaction()) {
            $this->database->connection()->rollBack();
        }
    }

    public function testNavigationAndAdministrationReadsNeverCallContentStorage(): void
    {
        $admin = new QuizAdministrationService($this->packs);
        $catalog = $admin->catalog('pt-BR');
        $theme = array_values(array_filter($catalog['themes'], fn (array $item): bool => $item['id'] === $this->theme))[0];
        self::assertSame('PHP em Português', $theme['topics'][0]['displayName']);
        self::assertSame(['1' => 1, '2' => 0, '3' => 0, '4' => 0], $theme['topics'][0]['questionCounts']);
        self::assertSame('PHP', $this->packs->listTopics()[0]['name']);
        self::assertSame('Pergunta?', $this->packs->listQuestions('pt-BR', 'php', 1)[0]['prompt']);
        self::assertSame(1, $this->packs->contentStats()['totals']['canonicalQuestions']);
        self::assertSame([], $this->packs->validateAll());
        self::assertSame('php-1-002', $this->packs->nextQuestionId('php', 1));
        self::assertSame('A', $admin->question($this->theme, 'php', 1, 'php-1-001')['translations']['en-US']['correctOptions'][0]);
    }
}
