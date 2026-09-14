<?php

namespace App\Repository;

use PDO;
use RuntimeException;

final class DatabaseConnectionFactory
{
    public static function connect(string $databaseUrl): PDO
    {
        $databaseUrl = str_replace('%kernel.project_dir%', dirname(__DIR__, 2), trim($databaseUrl));

        if (str_starts_with($databaseUrl, 'sqlite:///')) {
            return self::connectSqlite(substr($databaseUrl, strlen('sqlite:///')));
        }

        if (str_starts_with($databaseUrl, 'pgsql:')) {
            return self::configure(new PDO($databaseUrl));
        }

        $scheme = strtolower((string) parse_url($databaseUrl, PHP_URL_SCHEME));
        if ($scheme === 'postgres' || $scheme === 'postgresql') {
            return self::connectPostgresUrl($databaseUrl);
        }

        throw new RuntimeException('Manager database URL must use sqlite, postgres, or postgresql.');
    }

    private static function connectSqlite(string $path): PDO
    {
        if ($path === '') {
            throw new RuntimeException('SQLite database path is required.');
        }

        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Could not create database directory %s.', $directory));
            }
        }

        return self::configure(new PDO('sqlite:'.$path));
    }

    private static function connectPostgresUrl(string $databaseUrl): PDO
    {
        $parts = parse_url($databaseUrl);
        if (!is_array($parts)) {
            throw new RuntimeException('Invalid PostgreSQL database URL.');
        }

        $host = (string) ($parts['host'] ?? '');
        $database = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));
        if ($host === '' || $database === '') {
            throw new RuntimeException('PostgreSQL database URL requires a host and database name.');
        }

        $parameters = [
            'host='.$host,
            'port='.(int) ($parts['port'] ?? 5432),
            'dbname='.$database,
        ];

        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (['sslmode', 'connect_timeout', 'application_name'] as $option) {
            if (isset($query[$option]) && is_scalar($query[$option])) {
                $parameters[] = $option.'='.(string) $query[$option];
            }
        }

        $username = isset($parts['user']) ? rawurldecode((string) $parts['user']) : null;
        $password = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : null;

        return self::configure(new PDO('pgsql:'.implode(';', $parameters), $username, $password));
    }

    private static function configure(PDO $pdo): PDO
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        return $pdo;
    }
}
