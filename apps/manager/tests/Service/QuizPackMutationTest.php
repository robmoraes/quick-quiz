<?php

namespace App\Tests\Service;

use App\Service\QuizPackService;
use App\Tests\Support\FaultInjectingContentStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QuizPackMutationTest extends TestCase
{
    public function testBatchCreationRestoresAllObjectsWhenAWriteFails(): void
    {
        [$service, $storage] = $this->service();
        $service->saveTopic(['key' => 'git', 'active' => true]);
        $before = $storage->snapshot();
        $storage->failOnMutation(2);

        try {
            $service->createLocalizedQuestionSets('git', 1, [[
                'translations' => $this->translations(),
            ]]);
            self::fail('Expected injected storage failure.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('injected storage mutation', $error->getMessage());
        }

        self::assertSame($before, $storage->snapshot());
    }

    public function testQuestionDeletionRestoresAllObjectsWhenADeleteFails(): void
    {
        [$service, $storage] = $this->service();
        $service->saveTopic(['key' => 'git', 'active' => true]);
        $service->createLocalizedQuestionSets('git', 1, [[
            'id' => 'git-1-001',
            'translations' => $this->translations(),
        ]]);
        $before = $storage->snapshot();
        $storage->failOnMutation(2);

        try {
            $service->deleteLocalizedQuestionSet('git', 1, 'git-1-001');
            self::fail('Expected injected storage failure.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('injected storage mutation', $error->getMessage());
        }

        self::assertSame($before, $storage->snapshot());
    }

    /** @return array{QuizPackService,FaultInjectingContentStorage} */
    private function service(): array
    {
        $storage = new FaultInjectingContentStorage();

        return [
            new QuizPackService(
                '/unused',
                'en-US',
                'en-US,pt-BR',
                fixedTheme: 'dev',
                contentStorage: $storage,
            ),
            $storage,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function translations(): array
    {
        $question = [
            'prompt' => 'Which command creates a commit?',
            'correctOptions' => ['git commit'],
            'wrongOptions' => ['git add', 'git push'],
        ];

        return [
            'en-US' => $question,
            'pt-BR' => $question,
        ];
    }
}
