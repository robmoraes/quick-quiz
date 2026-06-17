<?php

namespace App\Service;

use App\Repository\AiPromptRepository;
use RuntimeException;

final class AiPromptImportService
{
    public function __construct(private readonly AiPromptRepository $prompts)
    {
    }

    public function importJson(string $theme, string $contents): int
    {
        $theme = trim($theme);
        if ($theme === '') {
            throw new RuntimeException('Theme is required for AI prompt import.');
        }
        if (trim($contents) === '') {
            throw new RuntimeException('AI prompt import file is empty.');
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            throw new RuntimeException('AI prompt import file is not valid JSON.');
        }

        $items = $data['prompts'] ?? null;
        if (!is_array($items)) {
            throw new RuntimeException('AI prompt import file must include a prompts array.');
        }

        $packageTheme = trim((string) ($data['theme'] ?? ''));
        if ($packageTheme === '') {
            throw new RuntimeException('AI prompt import file must include a theme.');
        }
        if ($packageTheme !== $theme) {
            throw new RuntimeException(sprintf('AI prompt import theme "%s" does not match selected theme "%s".', $packageTheme, $theme));
        }

        $count = 0;
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new RuntimeException(sprintf('Prompt item %d must be an object.', $index));
            }

            $key = trim((string) ($item['key'] ?? ''));
            $text = trim((string) ($item['text'] ?? ''));
            if ($key === '' || $text === '') {
                throw new RuntimeException(sprintf('Prompt item %d must include key and text.', $index));
            }

            AiPromptDefaults::get($key);
            $this->prompts->save($theme, $key, $text);
            $count++;
        }

        return $count;
    }
}
