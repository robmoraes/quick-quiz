<?php

namespace App\Tests\Repository;

use App\Repository\ManagerDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ManagerDatabaseTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir().'/quickquiz-manager-database-'.bin2hex(random_bytes(6)).'.sqlite';
    }

    protected function tearDown(): void
    {
        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    public function testReusesOneConfiguredConnection(): void
    {
        $database = $this->database();

        self::assertSame($database->connection(), $database->connection());
        self::assertSame('sqlite', $database->connection()->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function testCommitsSuccessfulTransaction(): void
    {
        $database = $this->database();
        $database->connection()->exec('CREATE TABLE values_table (value TEXT NOT NULL)');

        $result = $database->transactional(function (PDO $connection): string {
            $connection->exec("INSERT INTO values_table (value) VALUES ('saved')");

            return 'completed';
        });

        self::assertSame('completed', $result);
        self::assertSame('saved', $database->connection()->query('SELECT value FROM values_table')->fetchColumn());
    }

    public function testRollsBackFailedTransaction(): void
    {
        $database = $this->database();
        $database->connection()->exec('CREATE TABLE values_table (value TEXT NOT NULL)');

        try {
            $database->transactional(function (PDO $connection): void {
                $connection->exec("INSERT INTO values_table (value) VALUES ('discarded')");
                throw new RuntimeException('stop');
            });
            self::fail('Expected the transaction callback to fail.');
        } catch (RuntimeException $error) {
            self::assertSame('stop', $error->getMessage());
        }

        self::assertSame(0, (int) $database->connection()->query('SELECT COUNT(*) FROM values_table')->fetchColumn());
    }

    private function database(): ManagerDatabase
    {
        return new ManagerDatabase('sqlite:///'.$this->databasePath);
    }
}
