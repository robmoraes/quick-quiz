<?php

namespace App\Service;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiCatalogAssistant implements CatalogAssistant
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey = '',
        private readonly string $model = '',
        private readonly string $project = '',
        private readonly string $organization = '',
        private readonly ?AiPromptProvider $aiPrompts = null,
        private readonly ?OpenAiModelProvider $modelProvider = null,
    ) {
    }

    public function suggestDescription(string $canonicalLocale, string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Fallback name is required before suggesting a description with AI.');
        }

        return $this->parseTopicResponse($this->request($this->requestBody(
            schemaName: 'quickquiz_topic_description_suggestion',
            promptKey: AiPromptDefaults::CATALOG_DESCRIPTION_SUGGESTION,
            payload: [
                'canonicalLocale' => $canonicalLocale,
                'name' => $name,
            ],
        )))['description'];
    }

    public function canonicalize(string $canonicalLocale, string $name, string $description): array
    {
        $this->assertTopicText($name, $description);

        return $this->parseTopicResponse($this->request($this->requestBody(
            schemaName: 'quickquiz_topic_canonicalization',
            promptKey: AiPromptDefaults::CATALOG_CANONICALIZATION,
            payload: [
                'canonicalLocale' => $canonicalLocale,
                'topic' => [
                    'name' => trim($name),
                    'description' => trim($description),
                ],
            ],
        )));
    }

    public function translate(string $targetLocale, string $name, string $description): array
    {
        $this->assertTopicText($name, $description);

        return $this->parseTopicResponse($this->request($this->requestBody(
            schemaName: 'quickquiz_topic_translation',
            promptKey: AiPromptDefaults::CATALOG_TRANSLATION,
            payload: [
                'targetLocale' => $targetLocale,
                'topic' => [
                    'name' => trim($name),
                    'description' => trim($description),
                ],
            ],
        )));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function requestBody(string $schemaName, string $promptKey, array $payload): array
    {
        return [
            'model' => $this->model(),
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->promptText($promptKey),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'description'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function promptText(string $key): string
    {
        if ($this->aiPrompts instanceof AiPromptProvider) {
            return $this->aiPrompts->text($key);
        }

        return AiPromptDefaults::renderDefault($key);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function request(array $body): array
    {
        if ($this->apiKey() === '' || $this->model() === '') {
            throw new RuntimeException('OpenAI configuration is missing. Set OPENAI_API_KEY and OPENAI_MODEL.');
        }

        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey(),
            'Content-Type' => 'application/json',
        ];
        if (trim($this->project) !== '') {
            $headers['OpenAI-Project'] = $this->project;
        }
        if (trim($this->organization) !== '') {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
                'headers' => $headers,
                'timeout' => 45,
                'json' => $body,
            ]);
            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $error) {
            throw new RuntimeException('OpenAI request failed: '.$error->getMessage(), previous: $error);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $data['error']['message'] ?? 'OpenAI request failed.';
            throw new RuntimeException((string) $message);
        }

        return $data;
    }

    /** @param array<string,mixed> $response @return array{name:string, description:string} */
    private function parseTopicResponse(array $response): array
    {
        $text = $response['output_text'] ?? null;
        if (!is_string($text)) {
            $text = $this->extractOutputText($response);
        }
        if ($text === '') {
            throw new RuntimeException('OpenAI response did not contain catalog metadata JSON.');
        }

        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new RuntimeException('OpenAI response was not valid JSON.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        if ($name === '' || $description === '') {
            throw new RuntimeException('OpenAI response did not include complete catalog metadata.');
        }

        return ['name' => $name, 'description' => $description];
    }

    /** @param array<string,mixed> $response */
    private function extractOutputText(array $response): string
    {
        foreach (($response['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (($item['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text']) && is_string($content['text'])) {
                    return $content['text'];
                }
            }
        }
        return '';
    }

    private function apiKey(): string
    {
        if ($this->modelProvider instanceof OpenAiModelProvider) {
            return $this->modelProvider->apiKey();
        }

        return trim($this->apiKey);
    }

    private function model(): string
    {
        if ($this->modelProvider instanceof OpenAiModelProvider) {
            return $this->modelProvider->activeModel();
        }

        return trim($this->model);
    }

    private function assertTopicText(string $name, string $description): void
    {
        if (trim($name) === '') {
            throw new RuntimeException('Topic name is required before using AI.');
        }
        if (trim($description) === '') {
            throw new RuntimeException('Topic description is required before using AI.');
        }
    }
}
