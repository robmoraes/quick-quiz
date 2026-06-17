<?php

namespace App\Service;

use RuntimeException;

final class AiPromptExportService
{
    public function __construct(private readonly string $contentRoot)
    {
    }

    public function export(string $theme, string $key, string $text): void
    {
        if ($key !== AiPromptDefaults::QUESTION_SOLUTION) {
            return;
        }

        $theme = $this->cleanPathComponent($theme, 'theme');
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Prompt text is required for export.');
        }

        $path = $this->questionSolutionPromptPath($theme);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create AI prompt export directory %s.', $dir));
        }

        if (file_put_contents($path, $text."\n", LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Could not export AI prompt to %s.', $path));
        }
    }

    public function questionSolutionPromptPath(string $theme): string
    {
        return rtrim($this->contentRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
            .$this->cleanPathComponent($theme, 'theme').DIRECTORY_SEPARATOR
            .'ai-prompts'.DIRECTORY_SEPARATOR
            .'question-solution-prompt.txt';
    }

    private function cleanPathComponent(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '' || $value === '.' || $value === '..' || str_contains($value, '/') || str_contains($value, '\\')) {
            throw new RuntimeException(sprintf('Invalid %s for AI prompt export.', $label));
        }

        return $value;
    }
}
