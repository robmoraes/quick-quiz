<?php

namespace App\Tests\Service;

use App\Service\QuizContentComparator;
use App\Service\QuizProjectionPublisher;
use App\Tests\Support\FaultInjectingContentStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QuizProjectionPublisherTest extends TestCase
{
    public function testFullPublicationPreservesAdsAndDeletesOnlyRemovedQuizObjects(): void
    {
        $storage = new FaultInjectingContentStorage();
        $storage->write('themes.json', '{"themes":[]}');
        $storage->write('dev/index.json', '{"topics":[]}');
        $storage->write('ads/ads.json', '{"ads":["untouched"]}');
        $publisher = new QuizProjectionPublisher($storage, new QuizContentComparator());

        $result = $publisher->publish(['themes.json' => ['themes' => []]]);
        self::assertSame(2, $result['objectCount']);
        self::assertFalse($storage->exists('dev/index.json'));
        self::assertSame('{"ads":["untouched"]}', $storage->read('ads/ads.json'));
        self::assertSame(64, strlen($result['checksum']));
    }

    public function testFailedPublicationRestoresEveryPreviouslyTouchedObject(): void
    {
        $storage = new FaultInjectingContentStorage();
        $storage->write('themes.json', 'old themes');
        $storage->write('dev/index.json', 'old topics');
        $storage->write('ads/ads.json', 'old ads');
        $before = $storage->snapshot();
        $storage->failOnMutation(2);
        $publisher = new QuizProjectionPublisher($storage, new QuizContentComparator());

        try {
            $publisher->publish([
                'themes.json' => ['themes' => []],
                'dev/index.json' => ['topics' => []],
            ]);
            self::fail('Expected an injected publication failure.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('previous objects were restored', $error->getMessage());
        }
        self::assertSame($before, $storage->snapshot());
    }

    public function testScopedPublicationTouchesOnlySelectedKeys(): void
    {
        $storage = new FaultInjectingContentStorage();
        $storage->write('themes.json', 'old themes');
        $storage->write('dev/index.json', 'old topics');
        $publisher = new QuizProjectionPublisher($storage, new QuizContentComparator());
        $result = $publisher->publish([
            'themes.json' => ['themes' => []],
            'dev/index.json' => ['topics' => []],
        ], ['themes.json']);
        self::assertSame(1, $result['objectCount']);
        self::assertSame('old topics', $storage->read('dev/index.json'));
    }
}
