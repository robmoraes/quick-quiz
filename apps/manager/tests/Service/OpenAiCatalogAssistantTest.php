<?php

namespace App\Tests\Service;

use App\Service\OpenAiCatalogAssistant;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiCatalogAssistantTest extends TestCase
{
    public function testSuggestsDescriptionFromName(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode([
                'output_text' => json_encode([
                    'name' => 'PHP',
                    'description' => 'Core PHP language and runtime fundamentals.',
                ]),
            ]));
        });

        $assistant = new OpenAiCatalogAssistant($client, 'test-key', 'gpt-test');
        $description = $assistant->suggestDescription('en-US', 'PHP');
        $payload = json_decode($requests[0]['input'][1]['content'][0]['text'], true);

        self::assertSame('Core PHP language and runtime fundamentals.', $description);
        self::assertSame('en-US', $payload['canonicalLocale']);
        self::assertSame('PHP', $payload['name']);
    }

    public function testCanonicalizesTopicMetadata(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode([
                'output_text' => json_encode([
                    'name' => 'General PHP',
                    'description' => 'PHP language fundamentals.',
                ]),
            ]));
        });

        $assistant = new OpenAiCatalogAssistant($client, 'test-key', 'gpt-test');
        $topic = $assistant->canonicalize('en-US', 'PHP Geral', 'Fundamentos da linguagem PHP.');
        $instructions = $requests[0]['input'][0]['content'][0]['text'];
        $payload = json_decode($requests[0]['input'][1]['content'][0]['text'], true);

        self::assertSame('General PHP', $topic['name']);
        self::assertSame('PHP language fundamentals.', $topic['description']);
        self::assertStringContainsString('Detect the human language', $instructions);
        self::assertSame('en-US', $payload['canonicalLocale']);
        self::assertSame('PHP Geral', $payload['topic']['name']);
    }

    public function testTranslatesTopicMetadata(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode([
                'output_text' => json_encode([
                    'name' => 'PHP Geral',
                    'description' => 'Fundamentos da linguagem PHP.',
                ]),
            ]));
        });

        $assistant = new OpenAiCatalogAssistant($client, 'test-key', 'gpt-test');
        $topic = $assistant->translate('pt-BR', 'General PHP', 'PHP language fundamentals.');
        $payload = json_decode($requests[0]['input'][1]['content'][0]['text'], true);

        self::assertSame('PHP Geral', $topic['name']);
        self::assertSame('Fundamentos da linguagem PHP.', $topic['description']);
        self::assertSame('pt-BR', $payload['targetLocale']);
    }

    public function testRejectsMissingConfigurationBeforeRequest(): void
    {
        $client = new MockHttpClient([]);
        $assistant = new OpenAiCatalogAssistant($client, '', '');

        $this->expectExceptionMessage('OpenAI configuration is missing.');

        $assistant->suggestDescription('en-US', 'PHP');
    }
}
