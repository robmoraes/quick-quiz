<?php

namespace App\Tests\Repository;

use App\Repository\ManagerDatabase;
use App\Repository\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PostgresMigrationIntegrationTest extends TestCase
{
    public function testAppliesMigrationsIdempotentlyAndCreatesQuizSchema(): void
    {
        $database = $this->postgresDatabase();
        $runner = new MigrationRunner($database, $this->migrationDirectory());

        $runner->migrate();

        self::assertSame([], $runner->migrate());
        self::assertSame(
            [
                'quiz_answers',
                'quiz_catalog_state',
                'quiz_publications',
                'quiz_question_translations',
                'quiz_questions',
                'quiz_tags',
                'quiz_themes',
                'quiz_topic_tags',
                'quiz_topic_translations',
                'quiz_topics',
            ],
            $this->quizTables($database->connection()),
        );
        self::assertSame(1, (int) $database->connection()->query('SELECT COUNT(*) FROM quiz_catalog_state')->fetchColumn());
    }

    public function testRejectsChangedChecksumForAppliedMigration(): void
    {
        $database = $this->postgresDatabase();
        $runner = new MigrationRunner($database, $this->migrationDirectory());
        $runner->migrate();

        $temporaryDirectory = sys_get_temp_dir().'/quickquiz-migrations-'.bin2hex(random_bytes(6));
        mkdir($temporaryDirectory, 0775, true);
        $name = '0001_quiz_authoring_schema.sql';
        $source = file_get_contents($this->migrationDirectory().'/'.$name);
        self::assertIsString($source);
        file_put_contents($temporaryDirectory.'/'.$name, $source."\n-- changed after application\n");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('has changed checksum');
            (new MigrationRunner($database, $temporaryDirectory))->migrate();
        } finally {
            unlink($temporaryDirectory.'/'.$name);
            rmdir($temporaryDirectory);
        }
    }

    public function testTagConstraintsRejectInvalidSlugsAndDanglingAssociations(): void
    {
        $database = $this->postgresDatabase();
        (new MigrationRunner($database, $this->migrationDirectory()))->migrate();
        $db = $database->connection();
        $db->beginTransaction();
        try {
            foreach (['', 'UPPER', 'two words', '-aws', "aws\n", str_repeat('a', 51)] as $slug) {
                $db->exec('SAVEPOINT tag_constraint');
                try {
                    $db->prepare('INSERT INTO quiz_tags (slug) VALUES (?)')->execute([$slug]);
                    self::fail('Expected a slug constraint violation.');
                } catch (\PDOException $error) {
                    self::assertSame('23514', $error->getCode());
                    $db->exec('ROLLBACK TO SAVEPOINT tag_constraint');
                }
            }
            $db->exec('SAVEPOINT tag_constraint');
            try {
                $db->exec("INSERT INTO quiz_topic_tags (theme_id,topic_key,tag_slug) VALUES ('missing','missing','missing')");
                self::fail('Expected a foreign-key violation.');
            } catch (\PDOException $error) {
                self::assertSame('23503', $error->getCode());
                $db->exec('ROLLBACK TO SAVEPOINT tag_constraint');
            }
        } finally {
            $db->rollBack();
        }
    }

    private function postgresDatabase(): ManagerDatabase
    {
        $databaseUrl = (string) getenv('MANAGER_DATABASE_URL');
        if (!str_starts_with($databaseUrl, 'postgres://') && !str_starts_with($databaseUrl, 'postgresql://')) {
            self::markTestSkipped('MANAGER_DATABASE_URL is not PostgreSQL.');
        }

        return new ManagerDatabase($databaseUrl);
    }

    private function migrationDirectory(): string
    {
        return dirname(__DIR__, 2).'/migrations';
    }

    /** @return list<string> */
    private function quizTables(PDO $connection): array
    {
        $rows = $connection->query(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = current_schema()
               AND table_name LIKE 'quiz_%'
             ORDER BY table_name",
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $rows);
    }
}
