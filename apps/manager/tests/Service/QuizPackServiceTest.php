<?php

namespace App\Tests\Service;

use App\Service\QuizPackService;
use PHPUnit\Framework\TestCase;

final class QuizPackServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quickquiz-manager-test-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testSavesCentralCatalogTopic(): void
    {
        $service = $this->service();

        $service->saveTopic([
            'key' => 'php',
            'name' => 'General PHP',
            'description' => 'PHP fundamentals',
            'weight' => '100',
            'created_at' => '2026-01-01T00:00:00-03:00',
            'active' => '1',
        ]);

        $catalog = json_decode((string) file_get_contents($this->root.'/dev/index.json'), true);

        self::assertSame('php', $catalog['topics'][0]['key']);
        self::assertTrue($catalog['topics'][0]['active']);
        self::assertSame(100, $catalog['topics'][0]['weight']);
    }

    public function testSavesQuestionWithOnlyCanonicalFields(): void
    {
        $service = $this->service();
        $service->saveTopic(['key' => 'php', 'active' => '1']);

        $service->saveQuestion('en-US', 'php', 1, 'php-1-001', [
            'prompt' => 'Which tag starts PHP?',
            'correctOptions' => "<?php\nPHP tag",
            'wrongOptions' => "<?\n<script>",
            'id' => 'ignored',
            'locale' => 'ignored',
        ]);

        $question = json_decode((string) file_get_contents($this->root.'/dev/en-US/php/1/php-1-001.json'), true);

        self::assertSame(['prompt', 'correctOptions', 'wrongOptions'], array_keys($question));
        self::assertSame(['<?php', 'PHP tag'], $question['correctOptions']);
    }

    public function testGeneratesNextQuestionIdWhenBlank(): void
    {
        $service = $this->service();
        $service->saveTopic(['key' => 'php', 'active' => '1']);
        $service->saveQuestion('en-US', 'php', 1, 'php-1-001', [
            'prompt' => 'Which tag starts PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ]);

        $questionId = $service->saveQuestion('en-US', 'php', 1, '', [
            'prompt' => 'Which symbol starts a PHP variable?',
            'correctOptions' => ['$'],
            'wrongOptions' => ['#', '@'],
        ]);

        self::assertSame('php-1-002', $questionId);
        self::assertFileExists($this->root.'/dev/en-US/php/1/php-1-002.json');
    }

    public function testTopicChoicesIncludeInactiveTopics(): void
    {
        $service = $this->service();
        $service->saveTopic(['key' => 'php', 'active' => '1']);
        $service->saveTopic(['key' => 'go', 'active' => '0']);

        self::assertSame([
            ['key' => 'go', 'active' => false],
            ['key' => 'php', 'active' => true],
        ], $service->topicChoices());
    }

    public function testRejectsTranslatedQuestionWithoutFallbackPackage(): void
    {
        $service = $this->service();
        $service->saveTopic(['key' => 'php', 'active' => '1']);

        $this->expectExceptionMessage('Translated locales must replicate canonical fallback question packages.');

        $service->saveQuestion('pt-BR', 'php', 1, 'php-1-001', [
            'prompt' => 'Qual tag inicia PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ]);
    }

    public function testReportsLocaleParityIssues(): void
    {
        $service = $this->service();
        $service->saveTopic(['key' => 'php', 'active' => '1']);
        $service->saveQuestion('en-US', 'php', 1, 'php-1-001', [
            'prompt' => 'Which tag starts PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ]);

        $errors = $service->validateAll();

        self::assertContains('Locale pt-BR is missing fallback question package php/1/php-1-001.', $errors);
    }

    public function testSavesLocalizedQuestionSetForEverySupportedLocale(): void
    {
        $service = $this->service();
        $service->saveTopic(['key' => 'php', 'active' => '0']);

        $prepared = $service->prepareNewLocalizedQuestionSet('php', 1, '', [
            'prompt' => 'Qual tag inicia PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ]);

        $service->saveLocalizedQuestionSet('php', 1, $prepared['questionId'], $prepared['question'], [
            'en-US' => [
                'prompt' => 'Which tag starts PHP?',
                'correctOptions' => ['<?php'],
                'wrongOptions' => ['<?', '<script>'],
            ],
            'pt-BR' => [
                'prompt' => 'Qual tag inicia PHP?',
                'correctOptions' => ['<?php'],
                'wrongOptions' => ['<?', '<script>'],
            ],
        ]);

        self::assertSame('php-1-001', $prepared['questionId']);
        self::assertFileExists($this->root.'/dev/en-US/php/1/php-1-001.json');
        self::assertFileExists($this->root.'/dev/pt-BR/php/1/php-1-001.json');
    }

    public function testRejectsLocalizedQuestionSetWhenAnyLocaleIsMissing(): void
    {
        $service = $this->service();
        $service->saveTopic(['key' => 'php', 'active' => '1']);
        $prepared = $service->prepareNewLocalizedQuestionSet('php', 1, 'php-1-001', [
            'prompt' => 'Which tag starts PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ]);

        $this->expectExceptionMessage('Missing localizations for locale(s): pt-BR.');

        $service->saveLocalizedQuestionSet('php', 1, 'php-1-001', $prepared['question'], [
            'en-US' => [
                'prompt' => 'Which tag starts PHP?',
                'correctOptions' => ['<?php'],
                'wrongOptions' => ['<?', '<script>'],
            ],
        ]);
    }

    public function testInvalidLocalizedQuestionSetDoesNotCreatePartialFiles(): void
    {
        $service = $this->service();
        $service->saveTopic(['key' => 'php', 'active' => '1']);
        $prepared = $service->prepareNewLocalizedQuestionSet('php', 1, 'php-1-001', [
            'prompt' => 'Which tag starts PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ]);

        try {
            $service->saveLocalizedQuestionSet('php', 1, 'php-1-001', $prepared['question'], [
                'en-US' => [
                    'prompt' => 'Which tag starts PHP?',
                    'correctOptions' => ['<?php'],
                    'wrongOptions' => ['<?', '<script>'],
                ],
                'pt-BR' => [
                    'prompt' => '',
                    'correctOptions' => ['<?php'],
                    'wrongOptions' => ['<?', '<script>'],
                ],
            ]);
            self::fail('Expected localized question validation to fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('prompt is required.', $error->getMessage());
        }

        self::assertFileDoesNotExist($this->root.'/dev/en-US/php/1/php-1-001.json');
        self::assertFileDoesNotExist($this->root.'/dev/pt-BR/php/1/php-1-001.json');
    }

    public function testRejectsLocalizedQuestionSetDuplicateBeforeLocalization(): void
    {
        $service = $this->service();
        $service->saveTopic(['key' => 'php', 'active' => '1']);
        $service->saveQuestion('en-US', 'php', 1, 'php-1-001', [
            'prompt' => 'Which tag starts PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ]);

        $this->expectExceptionMessage('Question path already exists in locale en-US.');

        $service->prepareNewLocalizedQuestionSet('php', 1, 'php-1-001', [
            'prompt' => 'Which symbol starts a PHP variable?',
            'correctOptions' => ['$'],
            'wrongOptions' => ['#', '@'],
        ]);
    }

    public function testRecommendationPromptsUseSelectedContextAndLimitToFifty(): void
    {
        $service = $this->service();
        $service->saveTopic(['key' => 'php', 'active' => '1']);
        $service->saveTopic(['key' => 'go', 'active' => '1']);

        for ($i = 1; $i <= 55; $i++) {
            $service->saveQuestion('en-US', 'php', 1, sprintf('php-1-%03d', $i), [
                'prompt' => sprintf('PHP prompt %03d', $i),
                'correctOptions' => ['correct'],
                'wrongOptions' => ['wrong a', 'wrong b'],
            ]);
        }
        $service->saveQuestion('en-US', 'php', 2, 'php-2-001', [
            'prompt' => 'Different difficulty',
            'correctOptions' => ['correct'],
            'wrongOptions' => ['wrong a', 'wrong b', 'wrong c', 'wrong d'],
        ]);
        $service->saveQuestion('en-US', 'go', 1, 'go-1-001', [
            'prompt' => 'Different topic',
            'correctOptions' => ['correct'],
            'wrongOptions' => ['wrong a', 'wrong b'],
        ]);

        $prompts = $service->recommendationPrompts('en-US', 'php', 1);

        self::assertCount(50, $prompts);
        self::assertSame('PHP prompt 001', $prompts[0]);
        self::assertSame('PHP prompt 050', $prompts[49]);
        self::assertNotContains('Different difficulty', $prompts);
        self::assertNotContains('Different topic', $prompts);
    }

    public function testRecommendationTopicMetadataUsesLocalizedOverrideWithCentralFallback(): void
    {
        $service = $this->service();
        $service->saveTopic([
            'key' => 'php',
            'name' => 'General PHP',
            'description' => 'Version-agnostic PHP fundamentals',
            'active' => '1',
        ]);
        $service->saveLocalizedTopic('pt-BR', [
            'key' => 'php',
            'name' => 'PHP Geral',
            'description' => '',
        ]);

        self::assertSame([
            'theme' => 'dev',
            'key' => 'php',
            'name' => 'PHP Geral',
            'description' => 'Version-agnostic PHP fundamentals',
        ], $service->recommendationTopicMetadata('pt-BR', 'php'));
    }

    public function testRecommendationTopicMetadataUsesCentralFallbackWhenLocalizedEntryIsMissing(): void
    {
        $service = $this->service();
        $service->saveTopic([
            'key' => 'rust',
            'name' => 'Rust',
            'description' => 'Rust topic fundamentals',
            'active' => '0',
        ]);

        self::assertSame([
            'theme' => 'dev',
            'key' => 'rust',
            'name' => 'Rust',
            'description' => 'Rust topic fundamentals',
        ], $service->recommendationTopicMetadata('pt-BR', 'rust'));
    }

    public function testSavesThemeMetadata(): void
    {
        $service = $this->service();

        $service->saveTheme([
            'id' => 'math',
            'name' => 'Math',
            'description' => 'Mathematics quizzes.',
            'weight' => '50',
            'createdAt' => '2026-06-07T00:00:00-03:00',
            'active' => '0',
        ]);

        $themes = json_decode((string) file_get_contents($this->root.'/themes.json'), true);

        self::assertSame('math', $themes['themes'][0]['id']);
        self::assertSame('Math', $themes['themes'][0]['name']);
        self::assertFalse($themes['themes'][0]['active']);
    }

    public function testLocaleParityIsScopedBySelectedTheme(): void
    {
        $dev = $this->service('dev');
        $dev->saveTopic(['key' => 'php', 'active' => '1']);
        $dev->saveQuestion('en-US', 'php', 1, 'php-1-001', [
            'prompt' => 'Which tag starts PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ]);
        $dev->saveQuestion('pt-BR', 'php', 1, 'php-1-001', [
            'prompt' => 'Qual tag inicia PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ]);

        $math = $this->service('math');
        $math->saveTopic(['key' => 'algebra', 'active' => '1']);
        $math->saveQuestion('en-US', 'algebra', 1, 'algebra-1-001', [
            'prompt' => 'What is x if x + 1 = 2?',
            'correctOptions' => ['1'],
            'wrongOptions' => ['0', '2'],
        ]);

        self::assertSame([], $dev->validateAll());
        self::assertContains('Locale pt-BR is missing fallback question package algebra/1/algebra-1-001.', $math->validateAll());
    }

    public function testRejectsRecommendedDraftWithExactDuplicatePromptOnly(): void
    {
        $service = $this->service();
        $service->saveTopic(['key' => 'php', 'active' => '1']);
        $service->saveQuestion('en-US', 'php', 1, 'php-1-001', [
            'prompt' => 'Which tag starts PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ]);

        $this->expectExceptionMessage('Recommended prompt duplicates an existing question.');

        $service->validateRecommendedQuestionDraft('en-US', 'php', 1, [
            'prompt' => 'Which tag starts PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ]);
    }

    public function testAcceptsRecommendedDraftWithNonExactSimilarPrompt(): void
    {
        $service = $this->service();
        $service->saveTopic(['key' => 'php', 'active' => '1']);
        $service->saveQuestion('en-US', 'php', 1, 'php-1-001', [
            'prompt' => 'Which tag starts PHP?',
            'correctOptions' => ['<?php'],
            'wrongOptions' => ['<?', '<script>'],
        ]);

        $draft = $service->validateRecommendedQuestionDraft('en-US', 'php', 1, [
            'prompt' => 'Which PHP construct outputs text?',
            'correctOptions' => ['echo'],
            'wrongOptions' => ['select', 'mount'],
        ]);

        self::assertSame('Which PHP construct outputs text?', $draft['prompt']);
    }

    private function service(string $theme = 'dev'): QuizPackService
    {
        return new QuizPackService($this->root, 'en-US', 'en-US,pt-BR', fixedTheme: $theme);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path.'/'.$item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
