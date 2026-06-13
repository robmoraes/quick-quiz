<?php

namespace App\Service;

final class OpenAiConfiguration
{
    public function __construct(
        private readonly string $apiKey = '',
        private readonly string $model = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->model) !== '';
    }

    public function model(): string
    {
        return trim($this->model);
    }
}
