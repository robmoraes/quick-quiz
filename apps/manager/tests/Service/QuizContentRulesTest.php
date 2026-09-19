<?php

namespace App\Tests\Service;

use App\Service\QuizContentRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QuizContentRulesTest extends TestCase
{
    public function testKeepsFallbackFirstAndDeduplicatesSupportedLocales(): void
    {
        $rules = new QuizContentRules('en-US', 'pt-BR,en-US, pt-BR');

        self::assertSame(['en-US', 'pt-BR'], $rules->supportedLocales());
    }

    public function testNormalizesQuestionPayloadAndRemovesDuplicateOptions(): void
    {
        $payload = $this->rules()->questionPayload([
            'prompt' => '  Which command formats Go?  ',
            'correctOptions' => " gofmt \ngofmt\n",
            'wrongOptions' => [" go test ", 'go vet', 'go test', ''],
        ], 1);

        self::assertSame('Which command formats Go?', $payload['prompt']);
        self::assertSame(['gofmt'], $payload['correctOptions']);
        self::assertSame(['go test', 'go vet'], $payload['wrongOptions']);
    }

    #[DataProvider('invalidQuestionProvider')]
    public function testRejectsInvalidQuestionPayload(array $input, int $difficulty, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->rules()->questionPayload($input, $difficulty);
    }

    public static function invalidQuestionProvider(): iterable
    {
        yield 'blank prompt' => [[
            'correctOptions' => ['yes'],
            'wrongOptions' => ['no', 'maybe'],
        ], 1, 'prompt is required.'];

        yield 'missing correct option' => [[
            'prompt' => 'Question?',
            'wrongOptions' => ['no', 'maybe'],
        ], 1, 'correctOptions must contain at least one option.'];

        yield 'difficulty answer count' => [[
            'prompt' => 'Question?',
            'correctOptions' => ['yes'],
            'wrongOptions' => ['a', 'b', 'c'],
        ], 2, 'wrongOptions must contain at least 4 options for difficulty 2.'];
    }

    public function testAllocatesNextQuestionIdFromMatchingNumericSuffixes(): void
    {
        self::assertSame(
            'git-2-011',
            $this->rules()->nextQuestionId('git', 2, ['git-2-001', 'git-2-010', 'git-1-999', 'custom']),
        );
    }

    public function testNormalizesTimestampToUtc(): void
    {
        self::assertSame(
            '2026-06-13T09:15:30+00:00',
            $this->rules()->normalizeCreatedAtUtc('2026-06-13T02:15:30-07:00'),
        );
    }

    private function rules(): QuizContentRules
    {
        return new QuizContentRules('en-US', 'en-US,pt-BR');
    }
}
