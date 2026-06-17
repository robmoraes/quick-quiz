<?php

namespace App\Tests\Service;

use App\Service\AiPromptDefaults;
use App\Service\AiPromptExportService;
use PHPUnit\Framework\TestCase;

final class AiPromptExportServiceTest extends TestCase
{
    public function testExportsQuestionSolutionPromptIntoThemeContentRoot(): void
    {
        $contentRoot = sys_get_temp_dir().'/quickquiz-ai-prompt-export-'.bin2hex(random_bytes(6));
        $exporter = new AiPromptExportService($contentRoot);

        $exporter->export('dev', AiPromptDefaults::QUESTION_SOLUTION, 'Custom solution prompt.');

        self::assertSame(
            "Custom solution prompt.\n",
            file_get_contents($contentRoot.'/dev/ai-prompts/question-solution-prompt.txt'),
        );
    }

    public function testIgnoresPromptsThatAreNotConsumedByApi(): void
    {
        $contentRoot = sys_get_temp_dir().'/quickquiz-ai-prompt-export-'.bin2hex(random_bytes(6));
        $exporter = new AiPromptExportService($contentRoot);

        $exporter->export('dev', AiPromptDefaults::QUESTION_RECOMMENDATION, 'Private manager prompt.');

        self::assertFileDoesNotExist($contentRoot.'/dev/ai-prompts/question-solution-prompt.txt');
    }
}
