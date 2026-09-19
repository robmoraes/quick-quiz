<?php

namespace App\Tests\Service;

use App\Repository\ManagerDatabase;
use App\Repository\MigrationRunner;
use App\Service\QuizCatalogImporter;
use App\Service\QuizContentComparator;
use App\Service\QuizContentRules;
use App\Service\QuizProjectionRenderer;
use App\Service\QuizSourceCatalog;
use App\Storage\LocalContentStorage;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QuizCatalogImporterIntegrationTest extends TestCase
{
    private ManagerDatabase $database;
    private LocalContentStorage $storage;
    private QuizSourceCatalog $source;
    private QuizProjectionRenderer $renderer;
    private QuizCatalogImporter $importer;
    private string $root;
    private string $theme;

    protected function setUp(): void
    {
        $url = (string) getenv('MANAGER_DATABASE_URL');
        if (!str_starts_with($url, 'postgres://') && !str_starts_with($url, 'postgresql://')) {
            self::markTestSkipped('MANAGER_DATABASE_URL is not PostgreSQL.');
        }
        $this->theme = 'import-test-'.bin2hex(random_bytes(5));
        $this->root = sys_get_temp_dir().'/quiz-import-'.bin2hex(random_bytes(5));
        mkdir($this->root, 0700, true);
        $this->storage = new LocalContentStorage($this->root);
        $rules = new QuizContentRules('en-US', 'en-US,pt-BR');
        $this->source = new QuizSourceCatalog($this->storage, $rules);
        $this->database = new ManagerDatabase($url);
        (new MigrationRunner($this->database, dirname(__DIR__, 2).'/migrations'))->migrate();
        $this->database->connection()->beginTransaction();
        $this->database->connection()->exec('DELETE FROM quiz_themes');
        $this->renderer = new QuizProjectionRenderer($this->database, $rules);
        $this->importer = new QuizCatalogImporter($this->source, $this->renderer, new QuizContentComparator(), $this->database);
        $this->fixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->connection()->inTransaction()) {
            $this->database->connection()->rollBack();
        }
        if (!isset($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testDryRunApplyRoundTripAndIdempotency(): void
    {
        $before = $this->revision();
        $dryRun = $this->importer->run();
        self::assertFalse($dryRun['applied']);
        self::assertSame(1, $dryRun['counts']['questions']);
        self::assertSame($before, $this->revision());

        $applied = $this->importer->run(true);
        self::assertTrue($applied['applied']);
        self::assertTrue($applied['comparison']['equal']);
        self::assertSame($before + 1, $applied['revision']);
        self::assertSame($this->source->load()['objects'], $this->renderer->render());
        self::assertSame(['B', 'C'], $this->renderer->render()[$this->theme.'/en-US/php/1/php-1-001.json']['wrongOptions']);

        $again = $this->importer->run(true);
        self::assertFalse($again['applied']);
        self::assertSame($before + 1, $this->revision());
    }

    public function testPartialTopicLocalizationsRoundTripWithoutInventingTranslations(): void
    {
        $path = $this->theme.'/pt-BR/index.json';
        $this->write($path, ['topics' => []]);

        $report = $this->importer->run(true);

        self::assertTrue($report['comparison']['equal']);
        self::assertSame(1, $report['counts']['topicTranslations']);
        self::assertSame(['topics' => []], $this->renderer->render()[$path]);
        self::assertSame($this->source->load()['objects'], $this->renderer->render());
        $repository = new \App\Repository\QuizContentRepository($this->database);
        $topic = $repository->topics($this->theme, 'pt-BR', 'en-US')[0];
        self::assertSame('PHP', $topic['name']);
        self::assertSame('', $topic['localizedName']);
        self::assertFalse($this->importer->run(true)['applied']);
    }

    public function testConflictingImportRequiresExplicitReplace(): void
    {
        $this->importer->run(true);
        $path = $this->theme.'/en-US/php/1/php-1-001.json';
        $this->write($path, ['prompt' => 'Updated?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C']]);
        $before = $this->revision();
        try {
            $this->importer->run(true);
            self::fail('Expected a conflict.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('use --replace explicitly', $error->getMessage());
        }
        self::assertSame($before, $this->revision());
        self::assertSame('Question?', $this->renderer->render()[$path]['prompt']);
        $report = $this->importer->run(true, true);
        self::assertTrue($report['comparison']['equal']);
        self::assertSame('Updated?', $this->renderer->render()[$path]['prompt']);
    }

    public function testDatabaseWriteFailureLeavesPriorCatalogAndRevisionUnchanged(): void
    {
        $this->importer->run(true);
        $before = $this->renderer->render();
        $revision = $this->revision();
        $path = $this->theme.'/pt-BR/php/1/php-1-001.json';
        $this->write($path, ['prompt' => 'Question?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'BLOCK']]);
        $db = $this->database->connection();
        $db->exec('SAVEPOINT import_failure');
        $function = 'reject_answer_'.bin2hex(random_bytes(5));
        $db->exec("CREATE FUNCTION $function() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN IF NEW.answer_text = 'BLOCK' THEN RAISE EXCEPTION 'injected failure'; END IF;
            RETURN NEW; END $$");
        $db->exec("CREATE TRIGGER $function BEFORE INSERT ON quiz_answers
            FOR EACH ROW EXECUTE FUNCTION $function()");
        try {
            $this->importer->run(true, true);
            self::fail('Expected injected PostgreSQL failure.');
        } catch (PDOException) {
            $db->exec('ROLLBACK TO SAVEPOINT import_failure');
        }
        self::assertSame($revision, $this->revision());
        self::assertSame($before, $this->renderer->render());
    }

    public function testInvalidSourceFailsBeforeAnyDatabaseWrite(): void
    {
        $path = $this->theme.'/pt-BR/php/1/php-1-001.json';
        $this->storage->write($path, '{invalid');
        $before = $this->revision();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($path.': invalid JSON');
        try {
            $this->importer->run(true);
        } finally {
            self::assertSame($before, $this->revision());
            self::assertSame([], $this->database->connection()->query('SELECT id FROM quiz_themes')->fetchAll());
        }
    }

    private function fixture(): void
    {
        $this->write('themes.json', ['themes' => [[
            'id' => $this->theme, 'name' => 'Import test', 'description' => '',
            'weight' => 10, 'createdAt' => '2026-09-18T12:00:00Z', 'active' => true,
        ]]]);
        $this->write($this->theme.'/index.json', ['topics' => [[
            'key' => 'php', 'name' => 'PHP', 'description' => '', 'weight' => 20,
            'created_at' => '2026-09-18T12:00:00Z', 'active' => true,
        ]]]);
        foreach (['en-US', 'pt-BR'] as $locale) {
            $this->write($this->theme.'/'.$locale.'/index.json', ['topics' => [[
                'key' => 'php', 'name' => $locale === 'pt-BR' ? 'PHP em Português' : 'PHP', 'description' => '',
            ]]]);
            $this->write($this->theme.'/'.$locale.'/php/1/php-1-001.json', [
                'prompt' => 'Question?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C'],
            ]);
        }
    }

    /** @param array<string,mixed> $value */
    private function write(string $key, array $value): void
    {
        $this->storage->write($key, json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function revision(): int
    {
        return (int) $this->database->connection()->query('SELECT current_revision FROM quiz_catalog_state WHERE singleton=1')->fetchColumn();
    }
}
