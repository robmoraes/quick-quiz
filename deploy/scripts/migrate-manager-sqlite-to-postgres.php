<?php

declare(strict_types=1);


function requiredDatabaseUrl(string $name, string $fileName): string
{
    $file = trim((string) getenv($fileName));
    if ($file !== '') {
        if (!is_readable($file)) {
            throw new RuntimeException(sprintf('Cannot read %s.', $fileName));
        }

        $value = rtrim((string) file_get_contents($file), "\r\n");
        if ($value === '') {
            throw new RuntimeException(sprintf('%s is empty.', $fileName));
        }

        return $value;
    }

    $value = trim((string) getenv($name));
    if ($value === '') {
        throw new RuntimeException(sprintf('%s or %s is required.', $name, $fileName));
    }

    return $value;
}

function connect(string $databaseUrl): PDO
{
    if (str_starts_with($databaseUrl, 'sqlite:///')) {
        $pdo = new PDO('sqlite:'.substr($databaseUrl, strlen('sqlite:///')));
    } elseif (str_starts_with($databaseUrl, 'postgres://') || str_starts_with($databaseUrl, 'postgresql://')) {
        $parts = parse_url($databaseUrl);
        if (!is_array($parts)) {
            throw new RuntimeException('Invalid PostgreSQL URL.');
        }

        $host = (string) ($parts['host'] ?? '');
        $database = rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/'));
        if ($host === '' || $database === '') {
            throw new RuntimeException('PostgreSQL URL requires a host and database.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            (int) ($parts['port'] ?? 5432),
            $database,
        );
        $pdo = new PDO(
            $dsn,
            rawurldecode((string) ($parts['user'] ?? '')),
            rawurldecode((string) ($parts['pass'] ?? '')),
        );
    } else {
        throw new RuntimeException('Only sqlite and PostgreSQL database URLs are supported.');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    return $pdo;
}

function sqliteTableExists(PDO $source, string $table): bool
{
    $statement = $source->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table");
    $statement->execute(['table' => $table]);

    return (bool) $statement->fetchColumn();
}

function sqliteColumnExists(PDO $source, string $table, string $column): bool
{
    foreach ($source->query(sprintf('PRAGMA table_info(%s)', $table))->fetchAll() as $definition) {
        if (($definition['name'] ?? null) === $column) {
            return true;
        }
    }

    return false;
}

try {
    $sourceUrl = requiredDatabaseUrl('SOURCE_DATABASE_URL', 'SOURCE_DATABASE_URL__FILE');
    $targetUrl = requiredDatabaseUrl('TARGET_DATABASE_URL', 'TARGET_DATABASE_URL__FILE');
    $source = connect($sourceUrl);
    $target = connect($targetUrl);

    if ($source->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        throw new RuntimeException('The source database must be SQLite.');
    }
    if ($target->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
        throw new RuntimeException('The target database must be PostgreSQL.');
    }
    foreach (['admins', 'ai_prompts'] as $table) {
        if (!sqliteTableExists($source, $table)) {
            throw new RuntimeException(sprintf('Source table %s does not exist.', $table));
        }
    }
    if (!sqliteColumnExists($source, 'ai_prompts', 'theme')) {
        throw new RuntimeException('Source ai_prompts table does not contain the theme column.');
    }

    $admins = $source->query('SELECT id, email, password_hash, created_at FROM admins ORDER BY id')->fetchAll();
    $prompts = $source->query(
        'SELECT theme, prompt_key, prompt_text, created_at, updated_at FROM ai_prompts ORDER BY theme, prompt_key',
    )->fetchAll();

    $target->beginTransaction();
    $target->exec(
        'CREATE TABLE IF NOT EXISTS admins (
            id BIGSERIAL PRIMARY KEY,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        )',
    );
    $target->exec(
        'CREATE TABLE IF NOT EXISTS ai_prompts (
            theme TEXT NOT NULL,
            prompt_key TEXT NOT NULL,
            prompt_text TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (theme, prompt_key)
        )',
    );

    $targetAdminCount = (int) $target->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    $targetPromptCount = (int) $target->query('SELECT COUNT(*) FROM ai_prompts')->fetchColumn();
    if ($targetAdminCount !== 0 || $targetPromptCount !== 0) {
        throw new RuntimeException('Target tables must be empty.');
    }

    $insertAdmin = $target->prepare(
        'INSERT INTO admins (id, email, password_hash, created_at)
         VALUES (:id, :email, :password_hash, :created_at)',
    );
    foreach ($admins as $admin) {
        $insertAdmin->execute($admin);
    }

    $insertPrompt = $target->prepare(
        'INSERT INTO ai_prompts (theme, prompt_key, prompt_text, created_at, updated_at)
         VALUES (:theme, :prompt_key, :prompt_text, :created_at, :updated_at)',
    );
    foreach ($prompts as $prompt) {
        $insertPrompt->execute($prompt);
    }

    $target->exec(
        "SELECT setval(
            pg_get_serial_sequence('admins', 'id'),
            COALESCE((SELECT MAX(id) FROM admins), 1),
            (SELECT COUNT(*) > 0 FROM admins)
        )",
    );

    $migratedAdmins = (int) $target->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    $migratedPrompts = (int) $target->query('SELECT COUNT(*) FROM ai_prompts')->fetchColumn();
    if ($migratedAdmins !== count($admins) || $migratedPrompts !== count($prompts)) {
        throw new RuntimeException('Target row counts do not match the source database.');
    }

    $target->commit();
    printf("Migration complete: admins=%d ai_prompts=%d\n", $migratedAdmins, $migratedPrompts);
} catch (Throwable $error) {
    if (isset($target) && $target instanceof PDO && $target->inTransaction()) {
        $target->rollBack();
    }

    fwrite(STDERR, 'Migration failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
