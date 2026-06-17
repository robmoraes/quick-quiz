<?php

namespace App\Tests\Service;

use App\Repository\AiPromptRepository;
use App\Service\AiPromptDefaults;
use App\Service\AiPromptImportService;
use PHPUnit\Framework\TestCase;

final class AiPromptImportServiceTest extends TestCase
{
    public function testImportsPromptJsonIntoSqlite(): void
    {
        $repository = new AiPromptRepository($this->databaseUrl());
        $contents = json_encode([
            'theme' => 'dev',
            'prompts' => [
                [
                    'key' => AiPromptDefaults::QUESTION_RECOMMENDATION,
                    'text' => 'Private optimized question prompt with {{ wrongOptionTarget }} distractors.',
                ],
                [
                    'key' => AiPromptDefaults::ANSWER_RECOMMENDATION,
                    'text' => 'Private optimized answer prompt.',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $importer = new AiPromptImportService($repository);

        self::assertSame(2, $importer->importJson('dev', $contents));
        self::assertSame('Private optimized question prompt with {{ wrongOptionTarget }} distractors.', $repository->text('dev', AiPromptDefaults::QUESTION_RECOMMENDATION));
        self::assertSame('Private optimized answer prompt.', $repository->text('dev', AiPromptDefaults::ANSWER_RECOMMENDATION));
        self::assertStringContainsString('You recommend one new QuickQuiz question draft.', $repository->text('ops', AiPromptDefaults::QUESTION_RECOMMENDATION));
    }

    public function testRejectsImportForDifferentTheme(): void
    {
        $repository = new AiPromptRepository($this->databaseUrl());
        $importer = new AiPromptImportService($repository);

        $this->expectExceptionMessage('does not match selected theme');

        $importer->importJson('ops', json_encode([
            'theme' => 'dev',
            'prompts' => [
                [
                    'key' => AiPromptDefaults::QUESTION_RECOMMENDATION,
                    'text' => 'Private optimized question prompt.',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function databaseUrl(): string
    {
        return 'sqlite:///'.sys_get_temp_dir().'/quickquiz-ai-prompts-import-'.bin2hex(random_bytes(6)).'.sqlite';
    }
}
