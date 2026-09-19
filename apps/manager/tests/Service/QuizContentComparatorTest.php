<?php

namespace App\Tests\Service;

use App\Service\QuizContentComparator;
use PHPUnit\Framework\TestCase;

final class QuizContentComparatorTest extends TestCase
{
    public function testIgnoresJsonObjectFieldOrderButKeepsArrayOrder(): void
    {
        $comparator = new QuizContentComparator();
        $source = ['question.json' => [
            'prompt' => 'Q', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C'],
        ]];
        $reordered = ['question.json' => [
            'wrongOptions' => ['B', 'C'], 'correctOptions' => ['A'], 'prompt' => 'Q',
        ]];
        self::assertTrue($comparator->compare($source, $reordered)['equal']);
        self::assertSame($comparator->checksum($source), $comparator->checksum($reordered));
        $reordered['question.json']['wrongOptions'] = ['C', 'B'];
        self::assertSame(['question.json'], $comparator->compare($source, $reordered)['changed']);
    }

    public function testReportsMissingExtraAndChangedKeysPrecisely(): void
    {
        $comparator = new QuizContentComparator();
        self::assertSame([
            'missing' => ['a.json'],
            'extra' => ['c.json'],
            'changed' => ['b.json'],
            'equal' => false,
        ], $comparator->compare([
            'a.json' => ['a' => 1], 'b.json' => ['b' => 2],
        ], [
            'b.json' => ['b' => 3], 'c.json' => ['c' => 4],
        ]));
    }
}
