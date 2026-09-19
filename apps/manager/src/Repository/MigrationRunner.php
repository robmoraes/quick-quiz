<?php

namespace App\Repository;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private const ADVISORY_LOCK_ID = 7148572910319441;

    public function __construct(
        private readonly ManagerDatabase $database,
        private readonly string $migrationDirectory,
    ) {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $connection = $this->database->connection();
        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('Manager database migrations require PostgreSQL.');
        }

        $files = $this->migrationFiles();
        $this->lock($connection);
        try {
            $this->ensureLedger($connection);
            $applied = $this->appliedMigrations($connection);
            $completed = [];

            foreach ($files as $name => $path) {
                $checksum = hash_file('sha256', $path);
                if (!is_string($checksum)) {
                    throw new RuntimeException(sprintf('Could not checksum migration "%s".', $name));
                }

                if (isset($applied[$name])) {
                    if (!hash_equals($applied[$name], $checksum)) {
                        throw new RuntimeException(sprintf('Applied migration "%s" has changed checksum.', $name));
                    }
                    continue;
                }

                $sql = file_get_contents($path);
                if (!is_string($sql) || trim($sql) === '') {
                    throw new RuntimeException(sprintf('Migration "%s" is empty or unreadable.', $name));
                }

                $connection->beginTransaction();
                try {
                    $connection->exec($sql);
                    $statement = $connection->prepare(
                        'INSERT INTO manager_schema_migrations (migration_name, checksum)
                         VALUES (:migration_name, :checksum)',
                    );
                    $statement->execute([
                        'migration_name' => $name,
                        'checksum' => $checksum,
                    ]);
                    $connection->commit();
                    $completed[] = $name;
                } catch (Throwable $error) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    throw $error;
                }
            }

            return $completed;
        } finally {
            $this->unlock($connection);
        }
    }

    /** @return array<string,string> */
    private function migrationFiles(): array
    {
        if (!is_dir($this->migrationDirectory)) {
            throw new RuntimeException(sprintf('Migration directory "%s" does not exist.', $this->migrationDirectory));
        }

        $paths = glob(rtrim($this->migrationDirectory, '/').'/*.sql');
        if ($paths === false) {
            throw new RuntimeException('Could not list Manager database migrations.');
        }

        $files = [];
        foreach ($paths as $path) {
            $name = basename($path);
            if (preg_match('/^\d{4}_[a-z0-9_]+\.sql$/', $name) !== 1) {
                throw new RuntimeException(sprintf('Invalid migration filename "%s".', $name));
            }
            $files[$name] = $path;
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function lock(PDO $connection): void
    {
        $statement = $connection->prepare('SELECT pg_advisory_lock(:lock_id)');
        $statement->execute(['lock_id' => self::ADVISORY_LOCK_ID]);
    }

    private function unlock(PDO $connection): void
    {
        $statement = $connection->prepare('SELECT pg_advisory_unlock(:lock_id)');
        $statement->execute(['lock_id' => self::ADVISORY_LOCK_ID]);
    }

    private function ensureLedger(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS manager_schema_migrations (
                migration_name TEXT PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT manager_schema_migrations_checksum_length CHECK (char_length(checksum) = 64)
            )',
        );
    }

    /** @return array<string,string> */
    private function appliedMigrations(PDO $connection): array
    {
        $rows = $connection
            ->query('SELECT migration_name, checksum FROM manager_schema_migrations ORDER BY migration_name')
            ->fetchAll(PDO::FETCH_ASSOC);
        $applied = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $applied[(string) $row['migration_name']] = (string) $row['checksum'];
            }
        }

        return $applied;
    }
}
