<?php

namespace App\Service;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiQuestionLocalizer implements QuestionLocalizer
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey = '',
        private readonly string $model = '',
        private readonly string $project = '',
        private readonly string $organization = '',
    ) {
    }

    /**
     * @param array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} $question
     * @param list<string> $locales
     * @return array{detectedLanguage:string, localizations:array<string,array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}>}
     */
    public function localize(array $question, array $locales, bool $translateOptions = true): array
    {
        if (trim($this->apiKey) === '' || trim($this->model) === '') {
            throw new RuntimeException('OpenAI configuration is missing. Set OPENAI_API_KEY and OPENAI_MODEL.');
        }

        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
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
                'json' => $this->requestBody($question, $locales, $translateOptions),
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

        return $this->parseResponse($data);
    }

    /**
     * @param array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} $question
     * @param list<string> $locales
     * @return array<string,mixed>
     */
    private function requestBody(array $question, array $locales, bool $translateOptions): array
    {
        $optionInstruction = $translateOptions
            ? 'Localize prompt, correctOptions, and wrongOptions for every requested locale.'
            : 'Localize only prompt. Copy correctOptions and wrongOptions exactly as provided for every requested locale.';

        return [
            'model' => $this->model,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => implode("\n", [
                                'You localize QuickQuiz question JSON.',
                                'Detect the human language used by the input question.',
                                'Return localized content for every requested BCP 47 locale as an array of localization items.',
                                $optionInstruction,
                                'Preserve answer semantics: correct options remain correct and wrong options remain wrong.',
                                'Do not add, remove, merge, split, reorder, or duplicate options.',
                                'Do not include locale, topic key, difficulty, question id, explanations, hints, or extra fields.',
                                'Return only data that matches the schema.',
                            ]),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => json_encode([
                                'targetLocales' => $locales,
                                'question' => $question,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'quickquiz_question_localizations',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['detectedLanguage', 'localizations'],
                        'properties' => [
                            'detectedLanguage' => ['type' => 'string'],
                            'localizations' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['locale', 'prompt', 'correctOptions', 'wrongOptions'],
                                    'properties' => [
                                        'locale' => ['type' => 'string'],
                                        'prompt' => ['type' => 'string'],
                                        'correctOptions' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                        ],
                                        'wrongOptions' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @return array{detectedLanguage:string, localizations:array<string,array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}>}
     */
    private function parseResponse(array $response): array
    {
        $text = $response['output_text'] ?? null;
        if (!is_string($text)) {
            $text = $this->extractOutputText($response);
        }
        if ($text === '') {
            throw new RuntimeException('OpenAI response did not contain localization JSON.');
        }

        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new RuntimeException('OpenAI response was not valid JSON.');
        }

        $detectedLanguage = trim((string) ($data['detectedLanguage'] ?? ''));
        $localizations = $data['localizations'] ?? null;
        if ($detectedLanguage === '') {
            throw new RuntimeException('OpenAI could not detect the source language.');
        }
        if (!is_array($localizations)) {
            throw new RuntimeException('OpenAI response did not include localizations.');
        }

        $normalized = [];
        foreach ($localizations as $key => $question) {
            if (!is_array($question)) {
                continue;
            }
            $locale = isset($question['locale']) ? (string) $question['locale'] : (string) $key;
            $normalized[$locale] = [
                'prompt' => (string) ($question['prompt'] ?? ''),
                'correctOptions' => $this->stringList($question['correctOptions'] ?? []),
                'wrongOptions' => $this->stringList($question['wrongOptions'] ?? []),
            ];
        }

        return [
            'detectedLanguage' => $detectedLanguage,
            'localizations' => $normalized,
        ];
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

    /** @param mixed $value @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }
}
