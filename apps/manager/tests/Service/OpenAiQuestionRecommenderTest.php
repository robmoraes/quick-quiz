<?php

namespace App\Tests\Service;

use App\Service\OpenAiQuestionRecommender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiQuestionRecommenderTest extends TestCase
{
    public function testParsesStructuredRecommendationResponse(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'output_text' => json_encode([
                    'prompt' => 'Which PHP construct outputs text?',
                    'correctOptions' => ['echo'],
                    'wrongOptions' => ['select', 'mount', 'render', 'printText', 'display', 'writeLine', 'console.log', 'show', 'output'],
                ]),
            ])),
        ]);

        $recommender = new OpenAiQuestionRecommender($client, 'test-key', 'gpt-test');
        $draft = $recommender->recommend('en-US', [
            'key' => 'php',
            'name' => 'PHP',
            'description' => 'PHP fundamentals',
        ], 1, [
            'label' => 'easy',
            'optionCount' => 3,
            'wrongRequired' => 2,
        ], ['Which tag starts PHP?']);

        self::assertSame('Which PHP construct outputs text?', $draft['prompt']);
        self::assertSame(['echo'], $draft['correctOptions']);
        self::assertCount(9, $draft['wrongOptions']);
    }

    public function testRequestContextSendsPromptsWithoutExistingAnswers(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode([
                'output_text' => json_encode([
                    'prompt' => 'Which PHP construct outputs text?',
                    'correctOptions' => ['echo'],
                    'wrongOptions' => ['select', 'mount', 'render', 'printText', 'display', 'writeLine', 'console.log', 'show', 'output'],
                ]),
            ]));
        });

        $recommender = new OpenAiQuestionRecommender($client, 'test-key', 'gpt-test');
        $recommender->recommend('pt-BR', [
            'theme' => 'dev',
            'key' => 'php',
            'name' => 'PHP Geral',
            'description' => 'Fundamentos de PHP',
        ], 1, [
            'label' => 'easy',
            'optionCount' => 3,
            'wrongRequired' => 2,
        ], ['Qual tag inicia PHP?']);

        $payload = json_decode($requests[0]['input'][1]['content'][0]['text'], true);

        self::assertSame('pt-BR', $payload['draftLocale']);
        self::assertSame([
            'theme' => 'dev',
            'key' => 'php',
            'name' => 'PHP Geral',
            'description' => 'Fundamentos de PHP',
        ], $payload['topic']);
        self::assertSame(1, $payload['difficulty']['id']);
        self::assertSame(9, $payload['difficulty']['wrongOptionTarget']);
        self::assertSame(['Qual tag inicia PHP?'], $payload['existingPrompts']);
        self::assertArrayNotHasKey('correctOptions', $payload);
        self::assertArrayNotHasKey('wrongOptions', $payload);
    }

    public function testRequestInstructsNineWrongOptions(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode([
                'output_text' => json_encode([
                    'prompt' => 'Which PHP construct outputs text?',
                    'correctOptions' => ['echo'],
                    'wrongOptions' => ['select', 'mount', 'render', 'printText', 'display', 'writeLine', 'console.log', 'show', 'output'],
                ]),
            ]));
        });

        $recommender = new OpenAiQuestionRecommender($client, 'test-key', 'gpt-test');
        $recommender->recommend('en-US', [
            'key' => 'php',
            'name' => 'PHP',
            'description' => 'PHP fundamentals',
        ], 1, [
            'label' => 'easy',
            'optionCount' => 3,
            'wrongRequired' => 2,
        ], []);

        $instructions = $requests[0]['input'][0]['content'][0]['text'];

        self::assertStringContainsString('Always return exactly 9 wrongOptions.', $instructions);
    }

    public function testRejectsMissingConfigurationBeforeRequest(): void
    {
        $client = new MockHttpClient([]);
        $recommender = new OpenAiQuestionRecommender($client, '', '');

        $this->expectExceptionMessage('OpenAI configuration is missing.');

        $recommender->recommend('en-US', [
            'key' => 'php',
            'name' => 'PHP',
            'description' => 'PHP fundamentals',
        ], 1, [
            'label' => 'easy',
            'optionCount' => 3,
            'wrongRequired' => 2,
        ], []);
    }
}
