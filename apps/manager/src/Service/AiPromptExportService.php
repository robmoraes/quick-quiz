<?php

namespace App\Service;

use App\Storage\ContentStorage;
use App\Storage\LocalContentStorage;
use RuntimeException;

final class AiPromptExportService
{
    private readonly ContentStorage $contentStorage;

    public function __construct(string $contentRoot, ?ContentStorage $contentStorage = null)
    {
        $this->contentStorage = $contentStorage ?? new LocalContentStorage($contentRoot);
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

        $this->contentStorage->write($this->questionSolutionPromptKey($theme), $text."\n");
    }

    public function questionSolutionPromptPath(string $theme): string
    {
        return $this->contentStorage->location($this->questionSolutionPromptKey($theme));
    }

    private function questionSolutionPromptKey(string $theme): string
    {
        return $this->cleanPathComponent($theme, 'theme').'/ai-prompts/question-solution-prompt.txt';
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
