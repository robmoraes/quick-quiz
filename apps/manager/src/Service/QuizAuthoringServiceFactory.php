<?php

namespace App\Service;

use RuntimeException;

final class QuizAuthoringServiceFactory
{
    public function __construct(
        private readonly QuizPackService $legacy,
        private readonly PostgresQuizAuthoringService $postgres,
    ) {
    }

    public function create(string $provider): QuizAuthoringService
    {
        return match (strtolower(trim($provider))) {
            'legacy' => $this->legacy,
            'postgres' => $this->postgres,
            default => throw new RuntimeException(sprintf('Unsupported Manager quiz persistence provider "%s".', $provider)),
        };
    }
}
