<?php

namespace App\Service;

use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiModelProvider
{
    private const SELECTED_MODEL_KEY = 'openai.selected_model';
    private const AVAILABLE_MODELS_KEY = 'openai.available_models';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey = '',
        private readonly string $defaultModel = '',
        private readonly string $project = '',
        private readonly string $organization = '',
    ) {
    }

    public function apiKey(): string
    {
        return trim($this->apiKey);
    }

    public function activeModel(): string
    {
        $selected = trim((string) $this->requestStack->getSession()->get(self::SELECTED_MODEL_KEY, ''));
        if ($selected !== '') {
            return $selected;
        }

        return trim($this->defaultModel);
    }

    public function defaultModel(): string
    {
        return trim($this->defaultModel);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->activeModel() !== '';
    }

    /** @return list<string> */
    public function availableModels(): array
    {
        $session = $this->requestStack->getSession();
        $cached = $session->get(self::AVAILABLE_MODELS_KEY);
        if (is_array($cached)) {
            return $this->sanitizeModels($cached);
        }

        $models = $this->fetchModels();
        $session->set(self::AVAILABLE_MODELS_KEY, $models);

        return $models;
    }

    public function selectModel(string $model): void
    {
        $model = trim($model);
        if ($model === '') {
            throw new RuntimeException('OpenAI model is required.');
        }

        $models = $this->availableModels();
        if ($models !== [] && !in_array($model, $models, true)) {
            throw new RuntimeException(sprintf('OpenAI model "%s" is not available for this API key.', $model));
        }

        $this->requestStack->getSession()->set(self::SELECTED_MODEL_KEY, $model);
    }

    /** @return list<string> */
    private function fetchModels(): array
    {
        if ($this->apiKey() === '') {
            return $this->defaultModel() === '' ? [] : [$this->defaultModel()];
        }

        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey(),
        ];
        if (trim($this->project) !== '') {
            $headers['OpenAI-Project'] = $this->project;
        }
        if (trim($this->organization) !== '') {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://api.openai.com/v1/models', [
                'headers' => $headers,
                'timeout' => 15,
            ]);
            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface) {
            return $this->defaultModel() === '' ? [] : [$this->defaultModel()];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return $this->defaultModel() === '' ? [] : [$this->defaultModel()];
        }

        $models = [];
        foreach (($data['data'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $models[] = $id;
        }

        return $this->sanitizeModels($models);
    }

    /** @param list<mixed> $models @return list<string> */
    private function sanitizeModels(array $models): array
    {
        $items = array_values(array_unique(array_filter(array_map(
            static fn (mixed $model): string => trim((string) $model),
            $models,
        ))));
        $items = array_values(array_filter($items, fn (string $model): bool => $this->isQuickQuizContentModel($model)));

        if ($this->defaultModel() !== '' && !in_array($this->defaultModel(), $items, true)) {
            $items[] = $this->defaultModel();
        }

        sort($items, SORT_NATURAL);

        return $items;
    }

    private function isQuickQuizContentModel(string $model): bool
    {
        if (preg_match('/^gpt-5(?:[.-]|$)/', $model) === 1) {
            return !$this->isSpecializedNonTextModel($model);
        }

        if (preg_match('/^gpt-4\.1(?:[.-]|$)/', $model) === 1) {
            return !$this->isSpecializedNonTextModel($model);
        }

        return false;
    }

    private function isSpecializedNonTextModel(string $model): bool
    {
        foreach ([
            'audio',
            'realtime',
            'transcribe',
            'tts',
            'speech',
            'image',
            'vision',
            'embedding',
            'moderation',
            'search',
        ] as $token) {
            if (str_contains($model, $token)) {
                return true;
            }
        }

        return false;
    }
}
