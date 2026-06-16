<?php

namespace App\Tests\Service;

use App\Service\AdService;
use PHPUnit\Framework\TestCase;

final class AdServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quickquiz-manager-ads-test-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testCreatesBaseAdvertisingFileWhenMissing(): void
    {
        $this->writeThemes();
        $service = new AdService($this->root);

        self::assertFalse($service->exists());

        $service->createBaseFile();

        self::assertTrue($service->exists());
        self::assertSame(['ads' => []], json_decode((string) file_get_contents($this->root.'/ads/ads.json'), true));
    }

    public function testSavesAndUpdatesAd(): void
    {
        $this->writeThemes();
        $service = new AdService($this->root);
        $service->createBaseFile();

        $service->saveAd(null, [
            'uri' => 'https://example.com/ad',
            'id' => 'ignored',
            'provider_id' => 'AD-1',
            'description' => 'Example ad',
            'image' => 'https://example.com/ad.webp',
            'created_at' => '2026-06-15T20:00:00-03:00',
            'expires_in' => '',
            'active' => '1',
            'emphasis' => '1',
            'theme' => 'dev',
            'topics' => ['php', 'js'],
        ]);

        $created = $service->listAds()[0];
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $created['id']);
        self::assertNotSame('ignored', $created['id']);
        self::assertSame('AD-1', $created['provider_id']);
        self::assertTrue($created['emphasis']);
        self::assertSame(['theme' => 'dev', 'topics' => ['php', 'js']], $created['themes']);
        self::assertSame('Example ad', $created['description']);
        self::assertSame('2026-06-15T23:00:00+00:00', $created['created_at']);
        self::assertSame(
            ['theme' => 'dev', 'topics' => ['php', 'js']],
            json_decode((string) file_get_contents($this->root.'/ads/ads.json'), true)['ads'][0]['themes'],
        );

        $service->saveAd($created['id'], [
            'id' => '0b8a0347-6ad7-4b10-9c63-3ac46f7d9c43',
            'uri' => 'https://example.com/updated',
            'provider_id' => '',
            'description' => 'Updated ad',
            'image' => 'https://example.com/updated.webp',
            'created_at' => '',
            'active' => '0',
            'emphasis' => '0',
            'theme' => 'qa',
            'topics' => [],
        ]);

        $ad = $service->listAds()[0];
        self::assertSame($created['id'], $ad['id']);
        self::assertSame('https://example.com/updated', $ad['uri']);
        self::assertSame('', $ad['provider_id']);
        self::assertFalse($ad['emphasis']);
        self::assertSame(['theme' => 'qa', 'topics' => []], $ad['themes']);
        self::assertFalse($ad['active']);
    }

    public function testDeletesAdByIndex(): void
    {
        $this->writeThemes();
        $service = new AdService($this->root);
        $service->createBaseFile();
        $service->saveAd(null, [
            'uri' => 'https://example.com/ad',
            'description' => 'Example ad',
            'image' => 'https://example.com/ad.webp',
            'active' => '1',
        ]);
        $id = $service->listAds()[0]['id'];

        $service->deleteAd($id);

        self::assertSame([], $service->listAds());
    }

    public function testListsThemesFromThemeIndex(): void
    {
        $this->writeThemes();
        $service = new AdService($this->root);

        self::assertSame([
            ['id' => 'dev', 'name' => 'Development', 'description' => '', 'active' => true],
            ['id' => 'qa', 'name' => 'Quality Assurance', 'description' => '', 'active' => false],
        ], $service->listThemes());
    }

    public function testFiltersAdsByTheme(): void
    {
        $this->writeThemes();
        $service = new AdService($this->root);
        $service->createBaseFile();
        $service->saveAd(null, [
            'uri' => 'https://example.com/dev',
            'description' => 'Dev ad',
            'image' => 'https://example.com/dev.webp',
            'active' => '1',
            'theme' => 'dev',
            'topics' => ['php'],
        ]);
        $service->saveAd(null, [
            'uri' => 'https://example.com/qa',
            'description' => 'QA ad',
            'image' => 'https://example.com/qa.webp',
            'active' => '1',
            'theme' => 'qa',
        ]);

        self::assertSame(['Dev ad'], array_column($service->listAds('dev'), 'description'));
        self::assertSame(['QA ad'], array_column($service->listAds('qa'), 'description'));
        self::assertCount(2, $service->listAds());
    }

    public function testRejectsUnknownTheme(): void
    {
        $this->writeThemes();
        $service = new AdService($this->root);
        $service->createBaseFile();

        $this->expectExceptionMessage('Unknown theme "missing".');

        $service->saveAd(null, [
            'uri' => 'https://example.com/ad',
            'description' => 'Example ad',
            'image' => 'https://example.com/ad.webp',
            'active' => '1',
            'theme' => 'missing',
        ]);
    }

    public function testListsTopicsByTheme(): void
    {
        $this->writeThemes();
        $service = new AdService($this->root);

        self::assertSame([
            'dev' => [
                ['key' => 'js', 'name' => 'JavaScript', 'active' => false],
                ['key' => 'php', 'name' => 'PHP', 'active' => true],
            ],
            'qa' => [
                ['key' => 'manual', 'name' => 'Manual tests', 'active' => true],
            ],
        ], $service->listTopicsByTheme());
    }

    public function testRejectsUnknownTopicForTheme(): void
    {
        $this->writeThemes();
        $service = new AdService($this->root);
        $service->createBaseFile();

        $this->expectExceptionMessage('Unknown topic "missing" for theme "dev".');

        $service->saveAd(null, [
            'uri' => 'https://example.com/ad',
            'description' => 'Example ad',
            'image' => 'https://example.com/ad.webp',
            'active' => '1',
            'theme' => 'dev',
            'topics' => ['missing'],
        ]);
    }

    private function writeThemes(): void
    {
        file_put_contents($this->root.'/themes.json', json_encode([
            'themes' => [
                ['id' => 'qa', 'name' => 'Quality Assurance', 'active' => false],
                ['id' => 'dev', 'name' => 'Development', 'active' => true],
            ],
        ]));
        mkdir($this->root.'/dev', 0775, true);
        file_put_contents($this->root.'/dev/index.json', json_encode([
            'topics' => [
                ['key' => 'php', 'name' => 'PHP', 'active' => true],
                ['key' => 'js', 'name' => 'JavaScript', 'active' => false],
            ],
        ]));
        mkdir($this->root.'/qa', 0775, true);
        file_put_contents($this->root.'/qa/index.json', json_encode([
            'topics' => [
                ['key' => 'manual', 'name' => 'Manual tests', 'active' => true],
            ],
        ]));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
