<?php

namespace App\Tests\Service;

use App\Service\OpenAiQuestionLocalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiQuestionLocalizerTest extends TestCase
{
    public function testParsesStructuredResponseOutputText(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'output_text' => json_encode([
                    'detectedLanguage' => 'pt-BR',
                    'localizations' => [
                        [
                            'locale' => 'en-US',
                            'prompt' => 'Which tag starts PHP?',
                            'correctOptions' => ['<?php'],
                            'wrongOptions' => ['<?', '<script>'],
                        ],
                        [
                            'locale' => 'pt-BR',
                            'prompt' => 'Qual tag inicia PHP?',
                            'correctOptions' => ['<?php'],
                            'wrongOptions' => ['<?', '<script>'],
                        ],
                    ],
                ]),
            ])),
        ]);

        $localizer = new OpenAiQuestionLocalizer($client, 'test-key', 'gpt-test');
        $result = $localizer->localize([
            'prompt' => 'Qual tag inicia PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ], ['en-US', 'pt-BR']);

        self::assertSame('pt-BR', $result['detectedLanguage']);
        self::assertSame('Which tag starts PHP?', $result['localizations']['en-US']['prompt']);
    }

    public function testRejectsMissingConfigurationBeforeRequest(): void
    {
        $client = new MockHttpClient([]);
        $localizer = new OpenAiQuestionLocalizer($client, '', '');

        $this->expectExceptionMessage('OpenAI configuration is missing.');

        $localizer->localize([
            'prompt' => 'Question',
            'correctOptions' => ['A'],
            'wrongOptions' => ['B', 'C'],
        ], ['en-US']);
    }

    public function testCanRequestPromptOnlyLocalization(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode([
                'output_text' => json_encode([
                    'detectedLanguage' => 'pt-BR',
                    'localizations' => [
                        [
                            'locale' => 'en-US',
                            'prompt' => 'Which tag starts PHP?',
                            'correctOptions' => ['<?php'],
                            'wrongOptions' => ['<?', '<script>'],
                        ],
                    ],
                ]),
            ]));
        });

        $localizer = new OpenAiQuestionLocalizer($client, 'test-key', 'gpt-test');
        $localizer->localize([
            'prompt' => 'Qual tag inicia PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ], ['en-US'], false);

        $instructions = $requests[0]['input'][0]['content'][0]['text'];

        self::assertStringContainsString('Localize only prompt.', $instructions);
        self::assertStringContainsString('Copy correctOptions and wrongOptions exactly as provided', $instructions);
    }
}
