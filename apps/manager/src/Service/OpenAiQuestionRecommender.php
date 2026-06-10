<?php

namespace App\Service;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiQuestionRecommender implements QuestionRecommender
{
    private const WRONG_OPTION_TARGET = 9;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey = '',
        private readonly string $model = '',
        private readonly string $project = '',
        private readonly string $organization = '',
    ) {
    }

    /**
     * @param list<string> $existingPrompts
     * @param array{theme?:string, key:string, name:string, description:string} $topic
     * @param array{label:string, optionCount:int, wrongRequired:int} $difficulty
     * @return array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}
     */
    public function recommend(string $locale, array $topic, int $difficultyId, array $difficulty, array $existingPrompts): array
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
                'json' => $this->requestBody($locale, $topic, $difficultyId, $difficulty, $existingPrompts),
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
     * @param array{theme?:string, key:string, name:string, description:string} $topic
     * @param array{label:string, optionCount:int, wrongRequired:int} $difficulty
     * @param list<string> $existingPrompts
     * @return array<string,mixed>
     */
    private function requestBody(string $locale, array $topic, int $difficultyId, array $difficulty, array $existingPrompts): array
    {
        return [
            'model' => $this->model,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => implode("\n", [
                                'You recommend one new QuickQuiz question draft.',
                                'Use the requested locale to choose the human language of the draft.',
                                'Always return exactly '.self::WRONG_OPTION_TARGET.' wrongOptions.',
                                'Avoid repeating existing prompts exactly.',
                                'Use existing prompts only as context; they do not include answers.',
                                'Do not choose or return locale, topic key, difficulty, question id, explanations, hints, or extra fields.',
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
                                'draftLocale' => $locale,
                                'topic' => $topic,
                                'difficulty' => [
                                    'id' => $difficultyId,
                                    'label' => $difficulty['label'],
                                    'wrongRequired' => $difficulty['wrongRequired'],
                                    'wrongOptionTarget' => self::WRONG_OPTION_TARGET,
                                ],
                                'existingPrompts' => $existingPrompts,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'quickquiz_question_recommendation',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['prompt', 'correctOptions', 'wrongOptions'],
                        'properties' => [
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
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @return array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}
     */
    private function parseResponse(array $response): array
    {
        $text = $response['output_text'] ?? null;
        if (!is_string($text)) {
            $text = $this->extractOutputText($response);
        }
        if ($text === '') {
            throw new RuntimeException('OpenAI response did not contain recommendation JSON.');
        }

        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new RuntimeException('OpenAI response was not valid JSON.');
        }

        return [
            'prompt' => (string) ($data['prompt'] ?? ''),
            'correctOptions' => $this->stringList($data['correctOptions'] ?? []),
            'wrongOptions' => $this->stringList($data['wrongOptions'] ?? []),
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
