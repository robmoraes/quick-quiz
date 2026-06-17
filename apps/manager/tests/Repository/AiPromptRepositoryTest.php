<?php

namespace App\Tests\Repository;

use App\Repository\AiPromptRepository;
use App\Service\AiPromptDefaults;
use PHPUnit\Framework\TestCase;

final class AiPromptRepositoryTest extends TestCase
{
    public function testListsDefaultPromptsWhenDatabaseIsEmpty(): void
    {
        $repository = new AiPromptRepository($this->databaseUrl());

        $prompts = $repository->listPrompts('dev');

        self::assertCount(8, $prompts);
        self::assertSame('dev', $prompts[0]['theme']);
        self::assertSame(AiPromptDefaults::QUESTION_RECOMMENDATION, $prompts[0]['key']);
        self::assertFalse($prompts[0]['isCustomized']);
        self::assertStringContainsString('You recommend one new QuickQuiz question draft.', $prompts[0]['text']);
    }

    public function testSavesAndRestoresConfiguredPrompt(): void
    {
        $repository = new AiPromptRepository($this->databaseUrl());

        $repository->save('dev', AiPromptDefaults::ANSWER_RECOMMENDATION, 'Custom answer prompt.');

        $prompt = $repository->prompt('dev', AiPromptDefaults::ANSWER_RECOMMENDATION);
        self::assertSame('dev', $prompt['theme']);
        self::assertTrue($prompt['isCustomized']);
        self::assertSame('Custom answer prompt.', $prompt['text']);
        self::assertSame('Custom answer prompt.', $repository->text('dev', AiPromptDefaults::ANSWER_RECOMMENDATION));
        self::assertStringContainsString('You recommend answer options', $repository->text('ops', AiPromptDefaults::ANSWER_RECOMMENDATION));

        $repository->restoreDefault('dev', AiPromptDefaults::ANSWER_RECOMMENDATION);

        $prompt = $repository->prompt('dev', AiPromptDefaults::ANSWER_RECOMMENDATION);
        self::assertFalse($prompt['isCustomized']);
        self::assertStringContainsString('You recommend answer options', $prompt['text']);
    }

    private function databaseUrl(): string
    {
        return 'sqlite:///'.sys_get_temp_dir().'/quickquiz-ai-prompts-'.bin2hex(random_bytes(6)).'.sqlite';
    }
}
