<?php

namespace App\Service;

use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;

final class ThemeContext
{
    private const SESSION_KEY = 'selected_theme';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function selectedTheme(): string
    {
        return trim((string) $this->requestStack->getSession()->get(self::SESSION_KEY, ''));
    }

    public function requireSelectedTheme(): string
    {
        $theme = $this->selectedTheme();
        if ($theme === '') {
            throw new RuntimeException('Select a theme before managing content.');
        }
        return $theme;
    }

    public function selectTheme(string $theme): void
    {
        $theme = trim($theme);
        if ($theme === '') {
            throw new RuntimeException('Theme is required.');
        }
        $this->requestStack->getSession()->set(self::SESSION_KEY, $theme);
    }

    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }
}
