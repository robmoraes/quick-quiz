<?php

namespace App\Repository;

use App\Service\AiPromptDefaults;
use PDO;
use RuntimeException;

final class AiPromptRepository
{
    private ?PDO $pdo = null;

    public function __construct(private string $databaseUrl)
    {
        $this->databaseUrl = str_replace('%kernel.project_dir%', dirname(__DIR__, 2), $databaseUrl);
    }

    public function initialize(): void
    {
        $pdo = $this->pdo();
        $exists = (bool) $pdo
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'ai_prompts'")
            ->fetchColumn();

        if ($exists && !$this->hasThemeColumn()) {
            $pdo->exec('ALTER TABLE ai_prompts RENAME TO ai_prompts_legacy_'.date('YmdHis'));
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ai_prompts (
                theme TEXT NOT NULL,
                prompt_key TEXT NOT NULL,
                prompt_text TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (theme, prompt_key)
            )',
        );
    }

    /** @return list<array{theme:string,key:string,title:string,description:string,text:string,defaultText:string,isCustomized:bool,updatedAt:string|null}> */
    public function listPrompts(string $theme): array
    {
        $theme = $this->normalizeTheme($theme);
        $configured = $this->configuredPrompts($theme);
        $prompts = [];
        foreach (AiPromptDefaults::all() as $default) {
            $row = $configured[$default['key']] ?? null;
            $prompts[] = [
                'theme' => $theme,
                'key' => $default['key'],
                'title' => $default['title'],
                'description' => $default['description'],
                'text' => is_array($row) ? $row['text'] : $default['defaultText'],
                'defaultText' => $default['defaultText'],
                'isCustomized' => is_array($row),
                'updatedAt' => is_array($row) ? $row['updated_at'] : null,
            ];
        }

        return $prompts;
    }

    /** @return array{theme:string,key:string,title:string,description:string,text:string,defaultText:string,isCustomized:bool,updatedAt:string|null} */
    public function prompt(string $theme, string $key): array
    {
        $theme = $this->normalizeTheme($theme);
        $default = AiPromptDefaults::get($key);
        $configured = $this->findConfigured($theme, $key);

        return [
            'theme' => $theme,
            'key' => $default['key'],
            'title' => $default['title'],
            'description' => $default['description'],
            'text' => $configured['text'] ?? $default['defaultText'],
            'defaultText' => $default['defaultText'],
            'isCustomized' => $configured !== null,
            'updatedAt' => $configured['updated_at'] ?? null,
        ];
    }

    public function text(string $theme, string $key): string
    {
        $theme = $this->normalizeTheme($theme);
        AiPromptDefaults::get($key);
        $configured = $this->findConfigured($theme, $key);
        if ($configured === null || trim($configured['text']) === '') {
            return AiPromptDefaults::get($key)['defaultText'];
        }

        return $configured['text'];
    }

    public function save(string $theme, string $key, string $text): void
    {
        $theme = $this->normalizeTheme($theme);
        AiPromptDefaults::get($key);
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Prompt text is required.');
        }

        $this->initialize();
        $now = gmdate('c');
        $statement = $this->pdo()->prepare(
            'INSERT INTO ai_prompts (theme, prompt_key, prompt_text, created_at, updated_at)
             VALUES (:theme, :prompt_key, :prompt_text, :created_at, :updated_at)
             ON CONFLICT(theme, prompt_key) DO UPDATE SET
                prompt_text = excluded.prompt_text,
                updated_at = excluded.updated_at',
        );
        $ok = $statement->execute([
            'theme' => $theme,
            'prompt_key' => $key,
            'prompt_text' => $text,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$ok) {
            throw new RuntimeException('Could not save AI prompt.');
        }
    }

    public function restoreDefault(string $theme, string $key): void
    {
        $theme = $this->normalizeTheme($theme);
        AiPromptDefaults::get($key);
        $this->initialize();
        $statement = $this->pdo()->prepare('DELETE FROM ai_prompts WHERE theme = :theme AND prompt_key = :prompt_key');
        $statement->execute(['theme' => $theme, 'prompt_key' => $key]);
    }

    /** @return array<string,array{text:string,updated_at:string}> */
    private function configuredPrompts(string $theme): array
    {
        $this->initialize();
        $statement = $this->pdo()->prepare('SELECT prompt_key, prompt_text, updated_at FROM ai_prompts WHERE theme = :theme');
        $statement->execute(['theme' => $theme]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $configured = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $configured[(string) $row['prompt_key']] = [
                'text' => (string) $row['prompt_text'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $configured;
    }

    /** @return array{text:string,updated_at:string}|null */
    private function findConfigured(string $theme, string $key): ?array
    {
        $this->initialize();
        $statement = $this->pdo()->prepare('SELECT prompt_text, updated_at FROM ai_prompts WHERE theme = :theme AND prompt_key = :prompt_key');
        $statement->execute(['theme' => $theme, 'prompt_key' => $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'text' => (string) $row['prompt_text'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private function hasThemeColumn(): bool
    {
        $columns = $this->pdo()->query('PRAGMA table_info(ai_prompts)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $column) {
            if (is_array($column) && ($column['name'] ?? null) === 'theme') {
                return true;
            }
        }

        return false;
    }

    private function normalizeTheme(string $theme): string
    {
        $theme = trim($theme);
        if ($theme === '') {
            throw new RuntimeException('Theme is required for AI prompts.');
        }

        return $theme;
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
