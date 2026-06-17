<?php

namespace App\Tests\Service;

use App\Repository\AiPromptRepository;
use App\Service\AiPromptDefaults;
use App\Service\AiPromptExportService;
use App\Service\AiPromptImportService;
use PHPUnit\Framework\TestCase;

final class AiPromptImportServiceTest extends TestCase
{
    public function testImportsPromptJsonIntoSqlite(): void
    {
        $repository = new AiPromptRepository($this->databaseUrl());
        $contentRoot = sys_get_temp_dir().'/quickquiz-ai-prompts-import-export-'.bin2hex(random_bytes(6));
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
                [
                    'key' => AiPromptDefaults::QUESTION_SOLUTION,
                    'text' => 'Private optimized solution prompt.',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $importer = new AiPromptImportService($repository, new AiPromptExportService($contentRoot));

        self::assertSame(3, $importer->importJson('dev', $contents));
        self::assertSame('Private optimized question prompt with {{ wrongOptionTarget }} distractors.', $repository->text('dev', AiPromptDefaults::QUESTION_RECOMMENDATION));
        self::assertSame('Private optimized answer prompt.', $repository->text('dev', AiPromptDefaults::ANSWER_RECOMMENDATION));
        self::assertSame('Private optimized solution prompt.', $repository->text('dev', AiPromptDefaults::QUESTION_SOLUTION));
        self::assertSame("Private optimized solution prompt.\n", file_get_contents($contentRoot.'/dev/ai-prompts/question-solution-prompt.txt'));
        self::assertStringContainsString('You recommend one new QuickQuiz question draft.', $repository->text('ops', AiPromptDefaults::QUESTION_RECOMMENDATION));
    }

    public function testRejectsImportForDifferentTheme(): void
    {
        $repository = new AiPromptRepository($this->databaseUrl());
        $importer = new AiPromptImportService($repository, new AiPromptExportService(sys_get_temp_dir()));

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
