<?php

namespace App\Tests\Service;

use App\Repository\AiPromptRepository;
use App\Service\AiPromptDefaults;
use App\Service\AiPromptProvider;
use App\Service\OpenAiQuestionRecommender;
use App\Service\ThemeContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

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

    public function testUsesConfiguredQuestionRecommendationPrompt(): void
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
        $repository = new AiPromptRepository('sqlite:///'.sys_get_temp_dir().'/quickquiz-recommender-'.bin2hex(random_bytes(6)).'.sqlite');
        $repository->save('dev', AiPromptDefaults::QUESTION_RECOMMENDATION, 'Custom question prompt with {{ wrongOptionTarget }} wrong answers.');

        $recommender = new OpenAiQuestionRecommender($client, 'test-key', 'gpt-test', '', '', $this->promptProvider($repository));
        $recommender->recommend('en-US', [
            'key' => 'php',
            'name' => 'PHP',
            'description' => 'PHP fundamentals',
        ], 1, [
            'label' => 'easy',
            'optionCount' => 3,
            'wrongRequired' => 2,
        ], []);

        self::assertSame('Custom question prompt with 9 wrong answers.', $requests[0]['input'][0]['content'][0]['text']);
    }

    private function promptProvider(AiPromptRepository $repository): AiPromptProvider
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $themeContext = new ThemeContext($requestStack);
        $themeContext->selectTheme('dev');

        return new AiPromptProvider($repository, $themeContext);
    }

    public function testCanUsePromptFieldAsQuestionGenerationGuidance(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode([
                'output_text' => json_encode([
                    'prompt' => 'Which PHP keyword prints a short string?',
                    'correctOptions' => ['echo'],
                    'wrongOptions' => ['select', 'mount', 'render', 'printText', 'display', 'writeLine', 'console.log', 'show', 'output'],
                ]),
            ]));
        });

        $recommender = new OpenAiQuestionRecommender($client, 'test-key', 'gpt-test');
        $draft = $recommender->recommend('en-US', [
            'key' => 'php',
            'name' => 'PHP',
            'description' => 'PHP fundamentals',
        ], 1, [
            'label' => 'easy',
            'optionCount' => 3,
            'wrongRequired' => 2,
        ], [], 'Create a question for short answers.');

        $instructions = $requests[0]['input'][0]['content'][0]['text'];
        $payload = json_decode($requests[0]['input'][1]['content'][0]['text'], true);

        self::assertSame('Which PHP keyword prints a short string?', $draft['prompt']);
        self::assertStringContainsString('treat it as directional guidance for creating the question', $instructions);
        self::assertStringContainsString('Do not copy generationGuidance into prompt', $instructions);
        self::assertSame('Create a question for short answers.', $payload['generationGuidance']);
        self::assertArrayNotHasKey('prompt', $payload);
    }

    public function testCanRecommendAnswersWithoutReturningPrompt(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode([
                'output_text' => json_encode([
                    'correctOptions' => ['echo'],
                    'wrongOptions' => ['select', 'mount', 'render', 'printText', 'display', 'writeLine', 'console.log', 'show', 'output'],
                ]),
            ]));
        });

        $recommender = new OpenAiQuestionRecommender($client, 'test-key', 'gpt-test');
        $answers = $recommender->recommendAnswers('en-US', [
            'key' => 'php',
            'name' => 'PHP',
            'description' => 'PHP fundamentals',
        ], 1, [
            'label' => 'easy',
            'optionCount' => 3,
            'wrongRequired' => 2,
        ], 'Which PHP construct outputs text?', ['Which tag starts PHP?']);

        $instructions = $requests[0]['input'][0]['content'][0]['text'];
        $payload = json_decode($requests[0]['input'][1]['content'][0]['text'], true);

        self::assertSame(['echo'], $answers['correctOptions']);
        self::assertCount(9, $answers['wrongOptions']);
        self::assertStringContainsString('Write answers in the same human language as the provided prompt.', $instructions);
        self::assertStringContainsString('Do not rewrite, translate, summarize, or return the prompt.', $instructions);
        self::assertSame('Which PHP construct outputs text?', $payload['prompt']);
        self::assertArrayNotHasKey('prompt', $requests[0]['text']['format']['schema']['properties']);
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
