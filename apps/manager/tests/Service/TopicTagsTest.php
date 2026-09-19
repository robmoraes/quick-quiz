<?php

namespace App\Tests\Service;

use App\Service\TopicTags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TopicTagsTest extends TestCase
{
    public function testNormalizesDuplicatesAndReturnsStringSlugsInAsciiOrder(): void
    {
        self::assertSame(['0', '123', 'aws', 'redes'], TopicTags::normalize([' AWS ', 'redes', 'aws', '123', '0']));
        self::assertSame(['aws', 'redes'], TopicTags::fromForm(" AWS , redes,aws\t"));
        self::assertSame([], TopicTags::fromForm(" \n"));
        self::assertSame([], TopicTags::normalize([]));
        self::assertSame([str_repeat('a', 50)], TopicTags::normalize([str_repeat('a', 50)]));
        self::assertCount(20, TopicTags::normalize(array_map(fn (int $i): string => 'tag-'.$i, range(1, 20))));
    }

    #[DataProvider('invalidSets')]
    public function testRejectsInvalidSets(mixed $input): void
    {
        $this->expectException(RuntimeException::class);
        TopicTags::normalize($input);
    }

    public static function invalidSets(): array
    {
        return [
            [null], ['aws'], [new \stdClass()], [['a' => 'aws']], [[false]], [[123]], [[[]]],
            [['']], [['   ']], [['aws redes']], [['segurança']], [['-aws']], [['aws-']],
            [['aws--redes']], [["aws\0"]], [[str_repeat('a', 51)]],
            [[' '.str_repeat('a', 50)]], [array_fill(0, 21, 'aws')],
        ];
    }

    public function testFormRejectsEmptyCommaSeparatedEntries(): void
    {
        $this->expectException(RuntimeException::class);
        TopicTags::fromForm('aws,,redes');
    }
}
