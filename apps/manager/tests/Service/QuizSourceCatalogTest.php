<?php

namespace App\Tests\Service;

use App\Service\QuizContentRules;
use App\Service\QuizSourceCatalog;
use App\Storage\LocalContentStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QuizSourceCatalogTest extends TestCase
{
    private string $root;
    private LocalContentStorage $storage;
    private QuizSourceCatalog $source;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quiz-source-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        $this->storage = new LocalContentStorage($this->root);
        $this->source = new QuizSourceCatalog($this->storage, new QuizContentRules('en-US', 'en-US,pt-BR'));
        $this->fixture();
    }

    protected function tearDown(): void
    {
        if (!isset($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testLoadsValidatedCatalogAndAggregateCounts(): void
    {
        $snapshot = $this->source->load();
        self::assertSame(1, $snapshot['counts']['themes']);
        self::assertSame(1, $snapshot['counts']['topics']);
        self::assertSame(2, $snapshot['counts']['topicTranslations']);
        self::assertSame(1, $snapshot['counts']['questions']);
        self::assertSame(2, $snapshot['counts']['questionTranslations']);
        self::assertSame(2, $snapshot['counts']['correctAnswers']);
        self::assertSame(1, $snapshot['breakdown']['byTheme']['dev']['questions']);
        self::assertSame(2, $snapshot['breakdown']['byLocale']['pt-BR']['wrongAnswers']);
        self::assertSame(1, $snapshot['breakdown']['byDifficulty'][1]['questions']);
        self::assertSame(4, $snapshot['counts']['wrongAnswers']);
        self::assertSame('2026-09-18T12:00:00+00:00', $snapshot['themes'][0]['createdAt']);
        self::assertSame(['B', 'C'], $snapshot['questions']['dev']['php'][1]['php-1-001']['en-US']['wrongOptions']);
    }

    public function testIgnoresAdvertisingAndAiPromptObjectsInSharedStorage(): void
    {
        $this->storage->write('ads/ads.json', '{"ads":[]}');
        $this->storage->write('dev/ai-prompts/question-solution-prompt.txt', 'Private prompt.');
        $snapshot = $this->source->load();
        self::assertCount(1, $snapshot['questions']['dev']['php'][1]);
        self::assertArrayNotHasKey('ads/ads.json', $snapshot['objects']);
        self::assertArrayNotHasKey('dev/ai-prompts/question-solution-prompt.txt', $snapshot['objects']);
    }

    public function testPreservesMissingLocalizedTopicMetadata(): void
    {
        $this->write('dev/pt-BR/index.json', ['topics' => []]);
        $snapshot = $this->source->load();
        self::assertSame([], $snapshot['localizedTopics']['dev']['pt-BR']);
        self::assertSame(['topics' => []], $snapshot['objects']['dev/pt-BR/index.json']);
        self::assertSame(1, $snapshot['counts']['topicTranslations']);
        self::assertSame(2, $snapshot['counts']['questionTranslations']);
    }

    public function testRejectsNonStringMetadataWithLogicalPath(): void
    {
        $this->write('themes.json', ['themes' => [[
            'id' => ['bad'], 'name' => 'Development', 'description' => '',
            'weight' => 10, 'createdAt' => '2026-09-18T12:00:00Z', 'active' => true,
        ]]]);
        $this->expectExceptionMessage('themes.json: Invalid theme ID');
        $this->source->load();
    }

    public function testRejectsMissingTranslationWithLogicalPath(): void
    {
        $this->storage->delete('dev/pt-BR/php/1/php-1-001.json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dev/php/1/php-1-001: translations must contain exactly');
        $this->source->load();
    }

    public function testRejectsInvalidQuestionJsonWithFilePath(): void
    {
        $this->storage->write('dev/pt-BR/php/1/php-1-001.json', '{invalid');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dev/pt-BR/php/1/php-1-001.json: invalid JSON');
        $this->source->load();
    }

    public function testRejectsQuestionWhoseTopicIsNotInCentralCatalog(): void
    {
        $path = 'dev/en-US/orphan/1/orphan-1-001.json';
        $this->write($path, ['prompt' => 'Question?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C']]);
        $this->expectExceptionMessage($path.': theme or topic is not defined');
        $this->source->load();
    }

    public function testRejectsUnexpectedCatalogPath(): void
    {
        $this->storage->write('dev/en-US/php/5/wrong.json', '{}');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dev/en-US/php/5/wrong.json: unexpected catalog path');
        $this->source->load();
    }

    public function testRejectsDuplicateThemeIds(): void
    {
        $theme = [
            'id' => 'dev', 'name' => 'Development', 'description' => '',
            'weight' => 10, 'createdAt' => '2026-09-18T12:00:00Z', 'active' => true,
        ];
        $this->write('themes.json', ['themes' => [$theme, $theme]]);
        $this->expectExceptionMessage('themes.json: duplicate theme ID "dev"');
        $this->source->load();
    }

    public function testRejectsUnsupportedLocaleWithFilePath(): void
    {
        $path = 'dev/fr-FR/php/1/php-1-001.json';
        $this->write($path, ['prompt' => 'Question?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C']]);
        $this->expectExceptionMessage($path.': Unsupported locale');
        $this->source->load();
    }

    public function testRejectsLocalizedAnswerCountDrift(): void
    {
        $this->write('dev/pt-BR/php/1/php-1-001.json', [
            'prompt' => 'Pergunta?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C', 'D'],
        ]);
        $this->expectExceptionMessage('dev/php/1/php-1-001: locale pt-BR changed answer counts');
        $this->source->load();
    }

    private function fixture(): void
    {
        $this->write('themes.json', ['themes' => [[
            'id' => 'dev', 'name' => 'Development', 'description' => '',
            'weight' => 10, 'createdAt' => '2026-09-18T09:00:00-03:00', 'active' => true,
        ]]]);
        $this->write('dev/index.json', ['topics' => [[
            'key' => 'php', 'name' => 'PHP', 'description' => '',
            'weight' => 20, 'created_at' => '2026-09-18T12:00:00Z', 'active' => true,
        ]]]);
        $this->write('dev/en-US/index.json', ['topics' => [[
            'key' => 'php', 'name' => 'PHP', 'description' => '',
        ]]]);
        $this->write('dev/pt-BR/index.json', ['topics' => [[
            'key' => 'php', 'name' => 'PHP em Português', 'description' => '',
        ]]]);
        foreach (['en-US', 'pt-BR'] as $locale) {
            $this->write('dev/'.$locale.'/php/1/php-1-001.json', [
                'prompt' => 'A question?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C'],
            ]);
        }
    }

    /** @param array<string,mixed> $payload */
    private function write(string $key, array $payload): void
    {
        $this->storage->write($key, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
