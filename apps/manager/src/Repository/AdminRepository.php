<?php

namespace App\Repository;

use PDO;
use RuntimeException;

final class AdminRepository
{
    private ?PDO $pdo = null;

    public function __construct(private string $databaseUrl)
    {
        $this->databaseUrl = str_replace('%kernel.project_dir%', dirname(__DIR__, 2), $databaseUrl);
    }

    public function initialize(): void
    {
        $pdo = $this->pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
        );
    }

    public function createAdmin(string $email, string $password): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid admin email is required.');
        }
        if (strlen($password) < 10) {
            throw new RuntimeException('Admin password must have at least 10 characters.');
        }

        $this->initialize();
        $statement = $this->pdo()->prepare('INSERT INTO admins (email, password_hash, created_at) VALUES (:email, :password_hash, :created_at)');
        $ok = $statement->execute([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => gmdate('c'),
        ]);
        if (!$ok) {
            throw new RuntimeException('Could not create admin.');
        }
    }

    /** @return array{id:int,email:string,password_hash:string}|null */
    public function findByEmail(string $email): ?array
    {
        $this->initialize();
        $statement = $this->pdo()->prepare('SELECT id, email, password_hash FROM admins WHERE email = :email');
        $statement->execute(['email' => strtolower(trim($email))]);
        $admin = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($admin)) {
            return null;
        }
        return [
            'id' => (int) $admin['id'],
            'email' => (string) $admin['email'],
            'password_hash' => (string) $admin['password_hash'],
        ];
    }

    public function countAdmins(): int
    {
        $this->initialize();
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $path = $this->sqlitePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create database directory %s.', $dir));
        }

        $this->pdo = new PDO('sqlite:'.$path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $this->pdo;
    }

    private function sqlitePath(): string
    {
        $prefix = 'sqlite:///';
        if (!str_starts_with($this->databaseUrl, $prefix)) {
            throw new RuntimeException('Only sqlite database URLs are supported by the manager.');
        }

        return substr($this->databaseUrl, strlen($prefix));
    }
}
