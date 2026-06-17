<?php

namespace App\Service;

use App\Repository\AiPromptRepository;

final class AiPromptProvider
{
    public function __construct(
        private readonly AiPromptRepository $prompts,
        private readonly ThemeContext $themeContext,
    ) {
    }

    /** @param array<string,int|string> $variables */
    public function text(string $key, array $variables = []): string
    {
        return AiPromptDefaults::render($this->prompts->text($this->themeContext->requireSelectedTheme(), $key), $variables);
    }
}
