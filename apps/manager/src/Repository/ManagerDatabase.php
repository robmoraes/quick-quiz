<?php

namespace App\Repository;

use PDO;
use Throwable;

final class ManagerDatabase
{
    private ?PDO $connection = null;

    public function __construct(private readonly string $databaseUrl)
    {
    }

    public function connection(): PDO
    {
        if (!$this->connection instanceof PDO) {
            $this->connection = DatabaseConnectionFactory::connect($this->databaseUrl);
        }

        return $this->connection;
    }

    public function transactional(callable $operation): mixed
    {
        $connection = $this->connection();
        if ($connection->inTransaction()) {
            return $operation($connection);
        }

        $connection->beginTransaction();
        try {
            $result = $operation($connection);
            $connection->commit();

            return $result;
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $error;
        }
    }
}
