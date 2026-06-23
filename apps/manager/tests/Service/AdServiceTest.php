<?php

namespace App\Tests\Service;

use App\Service\AdService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AdServiceTest extends TestCase
{
    private string $root;
    /** @var array{exists:bool,ads:list<array<string,mixed>>} */
    private array $adsApiState;
    private int $nextAdId;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quickquiz-manager-ads-test-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
        $this->adsApiState = ['exists' => false, 'ads' => []];
        $this->nextAdId = 1;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testCreatesBaseAdvertisingFileWhenMissing(): void
    {
        $this->writeThemes();
        $service = $this->service();

        self::assertFalse($service->exists());

        $service->createBaseFile();

        self::assertTrue($service->exists());
        self::assertSame([], $service->listAds());
    }

    public function testSavesAndUpdatesAd(): void
    {
        $this->writeThemes();
        $service = $this->service();
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
        self::assertSame([
            ['theme' => 'dev', 'topics' => ['php', 'js']],
        ], $created['themes']);
        self::assertSame('Example ad', $created['description']);
        self::assertSame('2026-06-15T23:00:00+00:00', $created['created_at']);
        self::assertSame(
            [
                ['theme' => 'dev', 'topics' => ['php', 'js']],
            ],
            $created['themes'],
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
        self::assertSame([
            ['theme' => 'qa', 'topics' => []],
        ], $ad['themes']);
        self::assertFalse($ad['active']);
    }

    public function testSavesMultipleThemeTargets(): void
    {
        $this->writeThemes();
        $service = $this->service();
        $service->createBaseFile();

        $service->saveAd(null, [
            'uri' => 'https://example.com/ad',
            'description' => 'Example ad',
            'image' => 'https://example.com/ad.webp',
            'active' => '1',
            'themes' => [
                'dev' => ['enabled' => '1', 'topics' => ['php']],
                'qa' => ['enabled' => '1', 'topics' => []],
            ],
        ]);

        $ad = $service->listAds()[0];
        self::assertSame([
            ['theme' => 'dev', 'topics' => ['php']],
            ['theme' => 'qa', 'topics' => []],
        ], $ad['themes']);
        self::assertSame(['Example ad'], array_column($service->listAds('dev'), 'description'));
        self::assertSame(['Example ad'], array_column($service->listAds('qa'), 'description'));
    }

    public function testDeletesAdByIndex(): void
    {
        $this->writeThemes();
        $service = $this->service();
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
        $service = $this->service();

        self::assertSame([
            ['id' => 'dev', 'name' => 'Development', 'description' => '', 'active' => true],
            ['id' => 'qa', 'name' => 'Quality Assurance', 'description' => '', 'active' => false],
        ], $service->listThemes());
    }

    public function testFiltersAdsByTheme(): void
    {
        $this->writeThemes();
        $service = $this->service();
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
        $service = $this->service();
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
        $service = $this->service();

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
        $service = $this->service();
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

    private function service(): AdService
    {
        $client = new MockHttpClient(
            fn (string $method, string $url, array $options = []): MockResponse => $this->adsApiResponse($method, $url, $options),
            'http://ads-api.test',
        );

        return new AdService($client, $this->root, 'http://ads-api.test');
    }

    /** @param array<string,mixed> $options */
    private function adsApiResponse(string $method, string $url, array $options): MockResponse
    {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        if ($path === '/api/admin/ads/file' && $method === 'GET') {
            return $this->jsonResponse(['exists' => $this->adsApiState['exists']]);
        }

        if ($path === '/api/admin/ads/file' && in_array($method, ['PUT', 'POST'], true)) {
            $this->adsApiState['exists'] = true;
            return $this->jsonResponse(['exists' => true]);
        }

        if ($path === '/api/admin/ads' && $method === 'GET') {
            $theme = trim((string) ($query['theme'] ?? ''));
            $ads = $theme === ''
                ? $this->adsApiState['ads']
                : array_values(array_filter(
                    $this->adsApiState['ads'],
                    static fn (array $ad): bool => self::adHasTheme($ad, $theme),
                ));

            return $this->jsonResponse(['ads' => $ads]);
        }

        if ($path === '/api/admin/ads' && $method === 'POST') {
            $ad = $this->requestBody($options);
            $ad['id'] = $this->nextUuid();
            $this->adsApiState['ads'][] = $ad;

            return $this->jsonResponse($ad, 201);
        }

        if (preg_match('#^/api/admin/ads/([^/]+)$#', $path, $matches) === 1) {
            $id = rawurldecode($matches[1]);
            $index = $this->adIndex($id);
            if ($index === null) {
                return $this->jsonResponse(['error' => ['message' => 'Ad not found']], 404);
            }

            if ($method === 'GET') {
                return $this->jsonResponse($this->adsApiState['ads'][$index]);
            }

            if ($method === 'PUT') {
                $ad = $this->requestBody($options);
                $ad['id'] = $id;
                $this->adsApiState['ads'][$index] = $ad;

                return $this->jsonResponse($ad);
            }

            if ($method === 'DELETE') {
                array_splice($this->adsApiState['ads'], $index, 1);

                return new MockResponse('', ['http_code' => 204]);
            }
        }

        return $this->jsonResponse(['error' => ['message' => 'Not found']], 404);
    }

    /** @param array<string,mixed> $payload */
    private function jsonResponse(array $payload, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($payload), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function requestBody(array $options): array
    {
        $body = (string) ($options['body'] ?? '{}');
        $data = json_decode($body, true);
        self::assertIsArray($data);

        return $data;
    }

    private function nextUuid(): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $this->nextAdId++);
    }

    private function adIndex(string $id): ?int
    {
        foreach ($this->adsApiState['ads'] as $index => $ad) {
            if ((string) ($ad['id'] ?? '') === $id) {
                return $index;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $ad */
    private static function adHasTheme(array $ad, string $theme): bool
    {
        $targets = $ad['themes'] ?? [];
        if (!is_array($targets)) {
            return false;
        }

        foreach ($targets as $target) {
            if (is_array($target) && (string) ($target['theme'] ?? '') === $theme) {
                return true;
            }
        }

        return false;
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
