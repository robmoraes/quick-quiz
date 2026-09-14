<?php

namespace App\Tests\Storage;

use App\Storage\S3ContentStorage;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;

final class S3ContentStorageTest extends TestCase
{
    public function testUsesConfiguredBucketAndPrefixForContentOperations(): void
    {
        $handler = new MockHandler([
            new Result(),
            new Result(),
            new Result(['Body' => "{\"prompt\":\"Test\"}\n"]),
            new Result([
                'IsTruncated' => false,
                'Contents' => [
                    ['Key' => 'questions/dev/en-US/php/1/php-1-001.json'],
                    ['Key' => 'questions/dev/en-US/php/2/php-2-001.json'],
                ],
            ]),
            new Result(),
            new Result(),
        ]);
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
        ]);
        $storage = new S3ContentStorage($client, 'quickquiz-develop', 'questions');
        $key = 'dev/en-US/php/1/php-1-001.json';

        $storage->write($key, "{\"prompt\":\"Test\"}\n");
        self::assertSame('PutObject', $handler->getLastCommand()->getName());
        self::assertSame('questions/'.$key, $handler->getLastCommand()['Key']);
        self::assertTrue($storage->exists($key));
        self::assertSame("{\"prompt\":\"Test\"}\n", $storage->read($key));
        self::assertSame([
            'dev/en-US/php/1/php-1-001.json',
            'dev/en-US/php/2/php-2-001.json',
        ], $storage->list('dev/en-US/php'));
        self::assertTrue($storage->delete($key));
        self::assertSame('DeleteObject', $handler->getLastCommand()->getName());
        self::assertSame('s3://quickquiz-develop/questions', $storage->description());
        self::assertSame('s3://quickquiz-develop/questions/'.$key, $storage->location($key));
    }
}
