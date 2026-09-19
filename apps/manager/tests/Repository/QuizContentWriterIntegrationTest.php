<?php

namespace App\Tests\Repository;

use App\Repository\ManagerDatabase;
use App\Repository\MigrationRunner;
use App\Repository\QuizContentRepository;
use App\Repository\QuizContentWriter;
use App\Service\QuizContentRules;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QuizContentWriterIntegrationTest extends TestCase
{
    private ManagerDatabase $database;
    private QuizContentWriter $writer;
    private QuizContentRepository $reader;
    private string $theme;

    protected function setUp(): void
    {
        $url = (string) getenv('MANAGER_DATABASE_URL');
        if (!str_starts_with($url, 'postgres://') && !str_starts_with($url, 'postgresql://')) {
            self::markTestSkipped('MANAGER_DATABASE_URL is not PostgreSQL.');
        }
        $this->theme = 'writer-test-'.bin2hex(random_bytes(5));
        $this->database = new ManagerDatabase($url);
        (new MigrationRunner($this->database, dirname(__DIR__, 2).'/migrations'))->migrate();
        $rules = new QuizContentRules('en-US', 'en-US,pt-BR');
        $this->writer = new QuizContentWriter($this->database, $rules);
        $this->reader = new QuizContentRepository($this->database);
    }

    protected function tearDown(): void
    {
        if (!isset($this->database)) {
            return;
        }
        $db = $this->database->connection();
        $db->prepare('DELETE FROM quiz_themes WHERE id=:id')->execute(['id' => $this->theme]);
        $db->prepare('DELETE FROM quiz_publications WHERE affected_theme_id=:id')->execute(['id' => $this->theme]);
    }

    public function testThemeAndTopicMutationsRecordRevisionsAndGuardDeletes(): void
    {
        $before = $this->currentRevision();
        $themeRevision = $this->writer->saveTheme([
            'id' => $this->theme, 'name' => 'Study', 'description' => '', 'active' => true,
            'weight' => 10, 'createdAt' => '2026-09-18T12:00:00Z',
        ]);
        self::assertSame($before + 1, $themeRevision);
        self::assertSame('2026-09-18T12:00:00+00:00', $this->reader->theme($this->theme)['createdAt']);

        $topicRevision = $this->writer->saveTopicSet($this->theme, [
            'key' => 'php', 'name' => 'PHP', 'description' => 'Concepts',
            'weight' => 20, 'active' => true, 'created_at' => '2026-09-18T13:00:00Z',
        ], ['pt-BR' => ['name' => 'PHP em Português', 'description' => 'Conceitos']]);
        self::assertSame($themeRevision + 1, $topicRevision);
        self::assertSame('PHP em Português', $this->reader->topics($this->theme, 'pt-BR', 'en-US')[0]['localizedName']);
        self::assertSame('2026-09-18T13:00:00+00:00', $this->reader->topics($this->theme, 'pt-BR', 'en-US')[0]['created_at']);

        try {
            $this->writer->deleteTheme($this->theme);
            self::fail('Expected recursive deletion guard.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('recursive deletion is required', $error->getMessage());
        }
        self::assertSame($topicRevision, $this->currentRevision());
        self::assertNotNull($this->reader->theme($this->theme));

        $deleted = $this->writer->deleteTheme($this->theme, true);
        self::assertSame($topicRevision + 1, $deleted['revision']);
        self::assertNull($this->reader->theme($this->theme));
        self::assertSame(['pending', 'pending', 'pending'], $this->publicationStatuses());
    }

    public function testQuestionBatchIsAtomicAndKeepsOrderedTranslations(): void
    {
        $this->seedTopic();
        $created = $this->writer->createLocalizedQuestionSets($this->theme, 'php', 1, [
            ['translations' => $this->translations('First?', ['A'], ['B', 'C'])],
            ['id' => 'custom', 'translations' => $this->translations('Second?', ['D'], ['E', 'F'])],
        ]);
        self::assertSame(['php-1-001', 'custom'], $created['questionIds']);
        self::assertSame(['A'], $this->reader->localizedQuestionSet($this->theme, 'php', 1, 'php-1-001')['en-US']['correctOptions']);
        self::assertSame(['B', 'C'], $this->reader->localizedQuestionSet($this->theme, 'php', 1, 'php-1-001')['en-US']['wrongOptions']);

        $before = $this->currentRevision();
        try {
            $this->writer->createLocalizedQuestionSets($this->theme, 'php', 1, [
                ['translations' => $this->translations('Third?', ['G'], ['H', 'I'])],
                ['translations' => ['en-US' => ['prompt' => 'Incomplete', 'correctOptions' => ['G'], 'wrongOptions' => ['H', 'I']]]],
            ]);
            self::fail('Expected locale-parity validation.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('translations must contain exactly', $error->getMessage());
        }
        self::assertSame($before, $this->currentRevision());
        self::assertCount(2, $this->reader->questions($this->theme, 'php', 'en-US'));

        $newTranslations = $this->translations('Changed?', ['X'], ['Y', 'Z']);
        $this->writer->replaceLocalizedQuestionSet($this->theme, 'php', 1, 'custom', $newTranslations);
        self::assertSame('Changed?', $this->reader->localizedQuestionSet($this->theme, 'php', 1, 'custom')['en-US']['prompt']);
        self::assertSame(['Y', 'Z'], $this->reader->localizedQuestionSet($this->theme, 'php', 1, 'custom')['pt-BR']['wrongOptions']);
        $deleted = $this->writer->deleteLocalizedQuestionSet($this->theme, 'php', 1, 'custom');
        self::assertSame(['en-US', 'pt-BR'], $deleted['deletedLocales']);
        self::assertSame([], $deleted['missingLocales']);
        self::assertSame([], $this->reader->localizedQuestionSet($this->theme, 'php', 1, 'custom'));
    }

    public function testInvalidReplacementAndGuardedTopicDeleteDoNotChangeDatabase(): void
    {
        $this->seedTopic();
        $this->writer->createLocalizedQuestionSets($this->theme, 'php', 1, [
            ['id' => 'one', 'translations' => $this->translations('Original?', ['A'], ['B', 'C'])],
        ]);
        $before = $this->currentRevision();
        try {
            $this->writer->replaceLocalizedQuestionSet($this->theme, 'php', 1, 'one', [
                'en-US' => ['prompt' => 'Changed?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C']],
                'pt-BR' => ['prompt' => 'Alterada?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C', 'D']],
            ]);
            self::fail('Expected answer-count validation.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('number of answers', $error->getMessage());
        }
        self::assertSame('Original?', $this->reader->localizedQuestionSet($this->theme, 'php', 1, 'one')['en-US']['prompt']);
        try {
            $this->writer->deleteTopicPackage($this->theme, 'php');
            self::fail('Expected recursive deletion guard.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('recursive deletion is required', $error->getMessage());
        }
        self::assertSame($before, $this->currentRevision());
        self::assertSame(2, $this->writer->deleteTopicPackage($this->theme, 'php', true)['deletedQuestionFiles']);
        self::assertSame([], $this->reader->topics($this->theme, 'en-US', 'en-US'));
    }

    public function testConcurrentAutomaticIdAllocationIsUnique(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable.');
        }
        $this->seedTopic();
        $barrier = sys_get_temp_dir().'/quiz-id-barrier-'.bin2hex(random_bytes(6));
        $command = [PHP_BINARY, dirname(__DIR__).'/Support/create_question_concurrently.php',
            $this->theme, 'php', $barrier];
        $descriptors = [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ];
        $first = proc_open($command, $descriptors, $firstPipes);
        $second = proc_open($command, $descriptors, $secondPipes);
        self::assertIsResource($first);
        self::assertIsResource($second);
        file_put_contents($barrier, 'go');
        try {
            $firstId = trim((string) stream_get_contents($firstPipes[1]));
            $secondId = trim((string) stream_get_contents($secondPipes[1]));
            $firstError = stream_get_contents($firstPipes[2]);
            $secondError = stream_get_contents($secondPipes[2]);
            foreach ([$firstPipes, $secondPipes] as $pipes) {
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
            }
            self::assertSame(0, proc_close($first), $firstError);
            self::assertSame(0, proc_close($second), $secondError);
            $ids = [$firstId, $secondId];
            sort($ids);
            self::assertSame(['php-1-001', 'php-1-002'], $ids);
            self::assertCount(2, $this->reader->questions($this->theme, 'php', 'en-US'));
        } finally {
            unlink($barrier);
        }
    }

    private function seedTopic(): void
    {
        $this->writer->saveTheme(['id' => $this->theme, 'name' => 'Study', 'active' => true]);
        $this->writer->saveTopicSet($this->theme, ['key' => 'php', 'name' => 'PHP', 'active' => true]);
    }

    /** @param list<string> $correct @param list<string> $wrong @return array<string,array<string,mixed>> */
    private function translations(string $prompt, array $correct, array $wrong): array
    {
        return [
            'en-US' => ['prompt' => $prompt, 'correctOptions' => $correct, 'wrongOptions' => $wrong],
            'pt-BR' => ['prompt' => $prompt, 'correctOptions' => $correct, 'wrongOptions' => $wrong],
        ];
    }

    private function currentRevision(): int
    {
        return (int) $this->database->connection()->query('SELECT current_revision FROM quiz_catalog_state WHERE singleton=1')->fetchColumn();
    }

    /** @return list<string> */
    private function publicationStatuses(): array
    {
        $statement = $this->database->connection()->prepare('SELECT status FROM quiz_publications WHERE affected_theme_id=:theme ORDER BY revision');
        $statement->execute(['theme' => $this->theme]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }
}
