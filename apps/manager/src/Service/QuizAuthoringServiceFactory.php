<?php

namespace App\Service;

use RuntimeException;
use Closure;

final class QuizAuthoringServiceFactory
{
    public function __construct(
        private readonly QuizPackService|Closure $legacy,
        private readonly PostgresQuizAuthoringService|Closure $postgres,
        private readonly string $publicationProvider = 'content_storage',
    ) {
        if (!in_array($publicationProvider, ['content_storage', 'postgres'], true)) {
            throw new RuntimeException('Unsupported quiz publication provider.');
        }
    }

    public function create(string $provider): QuizAuthoringService
    {
        if ($this->publicationProvider === 'postgres' && strtolower(trim($provider)) !== 'postgres') {
            throw new RuntimeException('PostgreSQL runtime publication requires PostgreSQL quiz authoring.');
        }
        return match (strtolower(trim($provider))) {
            'legacy' => $this->resolve($this->legacy),
            'postgres' => $this->resolve($this->postgres),
            default => throw new RuntimeException(sprintf('Unsupported Manager quiz persistence provider "%s".', $provider)),
        };
    }

    private function resolve(QuizAuthoringService|Closure $service): QuizAuthoringService
    {
        return $service instanceof Closure ? $service() : $service;
    }
}
