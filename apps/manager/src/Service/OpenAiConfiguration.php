<?php

namespace App\Service;

final class OpenAiConfiguration
{
    public function __construct(
        private readonly string $apiKey = '',
        private readonly string $model = '',
        private readonly ?OpenAiModelProvider $modelProvider = null,
    ) {
    }

    public function isConfigured(): bool
    {
        if ($this->modelProvider instanceof OpenAiModelProvider) {
            return $this->modelProvider->isConfigured();
        }

        return trim($this->apiKey) !== '' && trim($this->model) !== '';
    }

    public function model(): string
    {
        if ($this->modelProvider instanceof OpenAiModelProvider) {
            return $this->modelProvider->activeModel();
        }

        return trim($this->model);
    }

    /** @return list<string> */
    public function availableModels(): array
    {
        if ($this->modelProvider instanceof OpenAiModelProvider) {
            return $this->modelProvider->availableModels();
        }

        return trim($this->model) === '' ? [] : [trim($this->model)];
    }
}
