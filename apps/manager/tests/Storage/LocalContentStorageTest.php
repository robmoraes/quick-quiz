<?php

namespace App\Tests\Storage;

use App\Storage\LocalContentStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LocalContentStorageTest extends TestCase
{
    public function testReadsWritesListsAndDeletesContent(): void
    {
        $root = sys_get_temp_dir().'/quickquiz-content-storage-'.bin2hex(random_bytes(6));
        $storage = new LocalContentStorage($root);

        $storage->write('dev/en-US/php/1/php-1-001.json', "{\"prompt\":\"Test\"}\n");

        self::assertTrue($storage->exists('dev/en-US/php/1/php-1-001.json'));
        self::assertSame("{\"prompt\":\"Test\"}\n", $storage->read('dev/en-US/php/1/php-1-001.json'));
        self::assertSame(
            ['dev/en-US/php/1/php-1-001.json'],
            $storage->list('dev/en-US/php'),
        );
        self::assertSame($root.'/dev/en-US/php/1/php-1-001.json', $storage->location('dev/en-US/php/1/php-1-001.json'));
        self::assertTrue($storage->delete('dev/en-US/php/1/php-1-001.json'));
        self::assertFalse($storage->delete('dev/en-US/php/1/php-1-001.json'));
    }

    public function testRejectsParentPathSegments(): void
    {
        $storage = new LocalContentStorage(sys_get_temp_dir());

        $this->expectException(RuntimeException::class);
        $storage->read('../secret');
    }
}
