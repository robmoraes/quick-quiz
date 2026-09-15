<?php

namespace App\Tests\Service;

use App\Exception\AdminApiException;
use App\Service\QuizAdministrationService;
use App\Service\QuizPackService;
use PHPUnit\Framework\TestCase;

final class QuizAdministrationServiceTest extends TestCase
{
    private string $root;
    private QuizAdministrationService $administration;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quickquiz-admin-api-test-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
        $this->administration = new QuizAdministrationService(
            new QuizPackService($this->root, 'en-US', 'en-US,pt-BR'),
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testCreatesDiscoversReplacesAndDeletesLocalizedQuestionSets(): void
    {
        $this->createThemeAndTopic();

        $created = $this->administration->createQuestions('dev', 'git', [
            'difficulty' => 1,
            'questions' => [
                ['translations' => $this->translations('Which command creates a commit?', 'Qual comando cria um commit?')],
                ['translations' => $this->translations('Which command changes branches?', 'Qual comando troca de branch?')],
            ],
        ]);

        self::assertSame(['git-1-001', 'git-1-002'], $created['questionIds']);
        self::assertTrue($created['publication']['apiReloadRequired']);
        self::assertFileExists($this->root.'/dev/en-US/git/1/git-1-001.json');
        self::assertFileExists($this->root.'/dev/pt-BR/git/1/git-1-002.json');

        $catalog = $this->administration->catalog('pt-BR');
        $topic = $catalog['themes'][0]['topics'][0];
        self::assertSame('Git', $topic['displayName']);
        self::assertSame('Fluxos fundamentais do Git.', $topic['displayDescription']);
        self::assertSame(2, $topic['questionCounts'][1]);

        $replaced = $this->administration->replaceQuestion('dev', 'git', 1, 'git-1-001', [
            'translations' => $this->translations('Which command records a commit?', 'Qual comando registra um commit?'),
        ]);
        self::assertSame(
            'Qual comando registra um commit?',
            $replaced['translations']['pt-BR']['prompt'],
        );

        $deleted = $this->administration->deleteQuestion('dev', 'git', 1, 'git-1-001');
        self::assertSame(['en-US', 'pt-BR'], $deleted['deletedLocales']);
        self::assertFileDoesNotExist($this->root.'/dev/en-US/git/1/git-1-001.json');
        self::assertFileDoesNotExist($this->root.'/dev/pt-BR/git/1/git-1-001.json');
    }

    public function testReplacesThemeAndTopicMetadata(): void
    {
        $this->createThemeAndTopic();

        $themeResult = $this->administration->replaceTheme('dev', [
            'name' => 'Developer Studies',
            'description' => 'Updated development subjects.',
            'weight' => 25,
            'createdAt' => '2026-09-15T10:00:00-03:00',
            'active' => false,
        ]);
        self::assertSame('Developer Studies', $themeResult['theme']['name']);
        self::assertFalse($this->administration->theme('dev')['active']);

        $topicResult = $this->administration->replaceTopic('dev', 'git', [
            'name' => 'Git Internals',
            'description' => 'Git data structures.',
            'weight' => 50,
            'created_at' => '2026-09-15T10:00:00-03:00',
            'active' => false,
            'localizations' => [
                'pt-BR' => [
                    'name' => 'Git por Dentro',
                    'description' => 'Estruturas de dados do Git.',
                ],
            ],
        ]);

        self::assertSame('Git Internals', $topicResult['topic']['name']);
        self::assertSame(
            'Git por Dentro',
            $this->administration->topic('dev', 'git', 'pt-BR')['displayName'],
        );
    }

    public function testRejectsIncompleteLocaleSetWithoutWritingFiles(): void
    {
        $this->createThemeAndTopic();

        try {
            $this->administration->createQuestions('dev', 'git', [
                'difficulty' => 1,
                'questions' => [[
                    'translations' => [
                        'en-US' => $this->question('Which command creates a commit?'),
                    ],
                ]],
            ]);
            self::fail('Expected locale validation to fail.');
        } catch (AdminApiException $error) {
            self::assertSame(422, $error->status);
            self::assertSame('validation_failed', $error->errorCode);
        }

        self::assertFileDoesNotExist($this->root.'/dev/en-US/git/1/git-1-001.json');
        self::assertFileDoesNotExist($this->root.'/dev/pt-BR/git/1/git-1-001.json');
    }

    public function testReturnsConflictsForDuplicateResourcesAndGuardedDeletion(): void
    {
        $this->createThemeAndTopic();

        try {
            $this->administration->createTheme([
                'id' => 'dev',
                'name' => 'Development',
                'description' => 'Software development.',
            ]);
            self::fail('Expected duplicate theme conflict.');
        } catch (AdminApiException $error) {
            self::assertSame(409, $error->status);
        }

        $this->administration->createQuestions('dev', 'git', [
            'difficulty' => 1,
            'questions' => [['translations' => $this->translations('Question?', 'Pergunta?')]],
        ]);

        try {
            $this->administration->deleteTopic('dev', 'git', false);
            self::fail('Expected recursive deletion guard.');
        } catch (AdminApiException $error) {
            self::assertSame(409, $error->status);
        }

        self::assertFileExists($this->root.'/dev/en-US/git/1/git-1-001.json');

        $deleted = $this->administration->deleteTopic('dev', 'git', true);
        self::assertSame(2, $deleted['deletedQuestionFiles']);
        self::assertSame([], $this->administration->topics('dev'));
        self::assertFileDoesNotExist($this->root.'/dev/en-US/git/1/git-1-001.json');

        try {
            $this->administration->deleteTheme('dev', false);
            self::fail('Expected theme recursive deletion guard.');
        } catch (AdminApiException $error) {
            self::assertSame(409, $error->status);
        }

        $themeDeleted = $this->administration->deleteTheme('dev', true);
        self::assertGreaterThanOrEqual(1, $themeDeleted['deletedObjects']);
        self::assertSame([], $this->administration->themes());
    }

    public function testUnknownOrIncompleteQuestionSetReturnsNotFound(): void
    {
        $this->createThemeAndTopic();

        try {
            $this->administration->question('dev', 'git', 1, 'git-1-999');
            self::fail('Expected missing question set.');
        } catch (AdminApiException $error) {
            self::assertSame(404, $error->status);
            self::assertSame('not_found', $error->errorCode);
        }
    }

    private function createThemeAndTopic(): void
    {
        $this->administration->createTheme([
            'id' => 'dev',
            'name' => 'Development',
            'description' => 'Software development.',
        ]);
        $this->administration->createTopic('dev', [
            'key' => 'git',
            'name' => 'Git',
            'description' => 'Git fundamentals.',
            'localizations' => [
                'pt-BR' => [
                    'name' => 'Git',
                    'description' => 'Fluxos fundamentais do Git.',
                ],
            ],
        ]);
    }

    /** @return array<string,array<string,mixed>> */
    private function translations(string $englishPrompt, string $portuguesePrompt): array
    {
        return [
            'en-US' => $this->question($englishPrompt),
            'pt-BR' => $this->question($portuguesePrompt),
        ];
    }

    /** @return array{prompt:string,correctOptions:list<string>,wrongOptions:list<string>} */
    private function question(string $prompt): array
    {
        return [
            'prompt' => $prompt,
            'correctOptions' => ['git commit'],
            'wrongOptions' => ['git add', 'git push'],
        ];
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
