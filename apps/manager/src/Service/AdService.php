<?php

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AdService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $contentRoot,
        private readonly string $adsApiBaseUrl,
    ) {
    }

    public function exists(): bool
    {
        $data = $this->requestJson('GET', '/api/admin/ads/file');

        return filter_var($data['exists'] ?? false, FILTER_VALIDATE_BOOL);
    }

    public function contentRoot(): string
    {
        return $this->contentRoot;
    }

    public function adsApiBaseUrl(): string
    {
        return $this->normalizedAdsApiBaseUrl();
    }

    public function createBaseFile(): void
    {
        $this->requestJson('PUT', '/api/admin/ads/file');
    }

    /** @return list<array{id:string,name:string,description:string,active:bool}> */
    public function listThemes(): array
    {
        $path = $this->join($this->contentRoot, 'themes.json');
        if (!is_file($path)) {
            return [];
        }

        $data = $this->readJson($path);
        $themes = $data['themes'] ?? [];
        if (!is_array($themes)) {
            return [];
        }

        $normalized = [];
        foreach ($themes as $theme) {
            if (!is_array($theme)) {
                continue;
            }
            $id = trim((string) ($theme['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $normalized[] = [
                'id' => $id,
                'name' => trim((string) ($theme['name'] ?? '')),
                'description' => trim((string) ($theme['description'] ?? '')),
                'active' => filter_var($theme['active'] ?? false, FILTER_VALIDATE_BOOL),
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $normalized;
    }

    /** @return list<array{id:string,provider_id:string,uri:string,description:string,image:string,created_at:string,expires_in:string|null,active:bool,emphasis:bool,themes:list<array{theme:string,topics:list<string>}>>}> */
    public function listAds(string $theme = ''): array
    {
        if (!$this->exists()) {
            return [];
        }

        $query = trim($theme) === '' ? '' : '?theme='.rawurlencode(trim($theme));
        $ads = $this->requestJson('GET', '/api/admin/ads'.$query)['ads'] ?? [];
        if (!is_array($ads)) {
            throw new RuntimeException('Ads API returned an invalid ads list.');
        }

        $normalized = array_values(array_filter(array_map(
            function (mixed $ad): ?array {
                if (!is_array($ad)) {
                    return null;
                }

                return $this->normalizeAd($ad);
            },
            $ads,
        )));

        $theme = trim($theme);
        return $theme === '' ? $normalized : array_values(array_filter(
            $normalized,
            static fn (array $ad): bool => self::adHasThemeTarget($ad, $theme),
        ));
    }

    /** @return array<string,list<array{key:string,name:string,active:bool}>> */
    public function listTopicsByTheme(): array
    {
        $topicsByTheme = [];
        foreach ($this->listThemes() as $theme) {
            $topicsByTheme[$theme['id']] = $this->listTopicsForTheme($theme['id']);
        }

        return $topicsByTheme;
    }

    /** @return array{id:string,provider_id:string,uri:string,description:string,image:string,created_at:string,expires_in:string|null,active:bool,emphasis:bool,themes:list<array{theme:string,topics:list<string>}>>} */
    public function emptyAd(): array
    {
        return [
            'id' => '',
            'provider_id' => '',
            'uri' => '',
            'description' => '',
            'image' => '',
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'expires_in' => null,
            'active' => true,
            'emphasis' => false,
            'themes' => [],
        ];
    }

    /** @param array<string,mixed> $input @return array{id:string,provider_id:string,uri:string,description:string,image:string,created_at:string,expires_in:string|null,active:bool,emphasis:bool,themes:list<array{theme:string,topics:list<string>}>>} */
    public function formAd(array $input): array
    {
        return $this->normalizeAd($input);
    }

    /** @return array{id:string,provider_id:string,uri:string,description:string,image:string,created_at:string,expires_in:string|null,active:bool,emphasis:bool,themes:list<array{theme:string,topics:list<string>}>>}|null */
    public function ad(string $id): ?array
    {
        $result = $this->requestJsonOrNull('GET', '/api/admin/ads/'.rawurlencode($id));
        if ($result === null) {
            return null;
        }

        return $this->normalizeAd($result);
    }

    /** @param array<string,mixed> $input */
    public function saveAd(?string $id, array $input): void
    {
        if ($id === null) {
            $payload = $this->validatedAd($input, null);
            $this->requestJson('POST', '/api/admin/ads', ['json' => $payload]);
        } else {
            $payload = $this->validatedAd($input, $id);
            $this->requestJson('PUT', '/api/admin/ads/'.rawurlencode($id), ['json' => $payload]);
        }
    }

    public function deleteAd(string $id): void
    {
        $this->requestJson('DELETE', '/api/admin/ads/'.rawurlencode($id));
    }

    /** @param array<string,mixed> $ad @return array{id:string,provider_id:string,uri:string,description:string,image:string,created_at:string,expires_in:string|null,active:bool,emphasis:bool,themes:list<array{theme:string,topics:list<string>}>>} */
    private function normalizeAd(array $ad): array
    {
        $expiresIn = trim((string) ($ad['expires_in'] ?? ''));
        $themes = $this->normalizeThemeTargets($ad);

        return [
            'id' => trim((string) ($ad['id'] ?? '')),
            'provider_id' => trim((string) ($ad['provider_id'] ?? '')),
            'uri' => trim((string) ($ad['uri'] ?? '')),
            'description' => trim((string) ($ad['description'] ?? '')),
            'image' => trim((string) ($ad['image'] ?? '')),
            'created_at' => trim((string) ($ad['created_at'] ?? '')),
            'expires_in' => $expiresIn === '' ? null : $expiresIn,
            'active' => filter_var($ad['active'] ?? false, FILTER_VALIDATE_BOOL),
            'emphasis' => filter_var($ad['emphasis'] ?? false, FILTER_VALIDATE_BOOL),
            'themes' => $themes,
        ];
    }

    /** @param array<string,mixed> $input @return array{id:string,provider_id:string,uri:string,description:string,image:string,created_at:string,expires_in:string|null,active:bool,emphasis:bool,themes:list<array{theme:string,topics:list<string>}>>} */
    private function validatedAd(array $input, ?string $id): array
    {
        $ad = $this->normalizeAd($input);

        if ($id !== null) {
            $ad['id'] = $id;
            if (!$this->isUuid($ad['id'])) {
                throw new RuntimeException('Ad ID must be a valid UUID.');
            }
        } else {
            $ad['id'] = '';
        }
        if ($ad['uri'] === '' || filter_var($ad['uri'], FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Ad URL must be a valid URL.');
        }
        if ($ad['image'] === '' || filter_var($ad['image'], FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Image URL must be a valid URL.');
        }
        if ($ad['description'] === '') {
            throw new RuntimeException('Description is required.');
        }
        $ad['created_at'] = $this->normalizeOptionalDatetime($ad['created_at'], 'Created at');
        if ($ad['expires_in'] !== null) {
            $ad['expires_in'] = $this->normalizeOptionalDatetime($ad['expires_in'], 'Expires in');
        }
        $this->assertKnownThemeTargets($ad['themes']);

        return $ad;
    }

    /** @param array<string,mixed> $ad @return list<array{theme:string,topics:list<string>}> */
    private function normalizeThemeTargets(array $ad): array
    {
        if (array_key_exists('theme', $ad) || array_key_exists('topics', $ad)) {
            return $this->deduplicateTargets([[
                'theme' => trim((string) ($ad['theme'] ?? '')),
                'topics' => $this->normalizeTopics($ad['topics'] ?? []),
            ]]);
        }

        $themes = $ad['themes'] ?? [];
        if (!is_array($themes)) {
            return $this->deduplicateTargets([[
                'theme' => trim((string) $themes),
                'topics' => [],
            ]]);
        }

        if (array_key_exists('theme', $themes) || array_key_exists('topics', $themes)) {
            return $this->deduplicateTargets([[
                'theme' => trim((string) ($themes['theme'] ?? '')),
                'topics' => $this->normalizeTopics($themes['topics'] ?? []),
            ]]);
        }

        $targets = [];
        foreach ($themes as $key => $value) {
            if (is_array($value)) {
                $hasEnabledFlag = array_key_exists('enabled', $value);
                if (is_string($key) && !$hasEnabledFlag) {
                    continue;
                }
                $enabled = filter_var($value['enabled'] ?? true, FILTER_VALIDATE_BOOL);
                $theme = trim((string) ($value['theme'] ?? (is_string($key) ? $key : '')));
                if (!$enabled) {
                    continue;
                }
                $targets[] = [
                    'theme' => $theme,
                    'topics' => $this->normalizeTopics($value['topics'] ?? []),
                ];
                continue;
            }

            $theme = trim((string) $value);
            if ($theme !== '') {
                $targets[] = ['theme' => $theme, 'topics' => []];
            }
        }

        return $this->deduplicateTargets($targets);
    }

    /** @param list<array{theme:string,topics:list<string>}> $targets @return list<array{theme:string,topics:list<string>}> */
    private function deduplicateTargets(array $targets): array
    {
        $byTheme = [];
        foreach ($targets as $target) {
            $theme = trim($target['theme']);
            if ($theme === '') {
                continue;
            }
            $topics = $this->normalizeTopics($target['topics']);
            if (!isset($byTheme[$theme])) {
                $byTheme[$theme] = $topics;
                continue;
            }
            if ($byTheme[$theme] === [] || $topics === []) {
                $byTheme[$theme] = [];
                continue;
            }
            if ($topics !== []) {
                $byTheme[$theme] = array_values(array_unique([...$byTheme[$theme], ...$topics]));
            }
        }

        $normalized = [];
        foreach ($byTheme as $theme => $topics) {
            $normalized[] = ['theme' => $theme, 'topics' => $topics];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['theme'] <=> $b['theme']);

        return $normalized;
    }

    /** @param mixed $topics @return list<string> */
    private function normalizeTopics(mixed $topics): array
    {
        if (is_string($topics)) {
            $topics = preg_split('/[\s,]+/', $topics) ?: [];
        }
        if (!is_array($topics)) {
            return [];
        }

        $normalized = [];
        foreach ($topics as $topic) {
            $topic = trim((string) $topic);
            if ($topic !== '') {
                $normalized[] = $topic;
            }
        }

        return array_values(array_unique($normalized));
    }

    /** @param list<array{theme:string,topics:list<string>}> $targets */
    private function assertKnownThemeTargets(array $targets): void
    {
        if ($targets === []) {
            return;
        }

        $known = array_flip(array_map(
            static fn (array $theme): string => $theme['id'],
            $this->listThemes(),
        ));

        foreach ($targets as $target) {
            if (!isset($known[$target['theme']])) {
                throw new RuntimeException(sprintf('Unknown theme "%s".', $target['theme']));
            }

            if ($target['topics'] === []) {
                continue;
            }

            $knownTopics = array_flip(array_map(
                static fn (array $topic): string => $topic['key'],
                $this->listTopicsForTheme($target['theme']),
            ));

            foreach ($target['topics'] as $topic) {
                if (!isset($knownTopics[$topic])) {
                    throw new RuntimeException(sprintf('Unknown topic "%s" for theme "%s".', $topic, $target['theme']));
                }
            }
        }
    }

    /** @param array<string,mixed> $ad */
    private static function adHasThemeTarget(array $ad, string $theme): bool
    {
        foreach ($ad['themes'] as $target) {
            if ($target['theme'] === $theme) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{key:string,name:string,active:bool}> */
    private function listTopicsForTheme(string $theme): array
    {
        $path = $this->join($this->contentRoot, $theme, 'index.json');
        if (!is_file($path)) {
            return [];
        }

        $data = $this->readJson($path);
        $topics = $data['topics'] ?? [];
        if (!is_array($topics)) {
            return [];
        }

        $normalized = [];
        foreach ($topics as $topic) {
            if (!is_array($topic)) {
                continue;
            }
            $key = trim((string) ($topic['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $normalized[] = [
                'key' => $key,
                'name' => trim((string) ($topic['name'] ?? '')),
                'active' => filter_var($topic['active'] ?? false, FILTER_VALIDATE_BOOL),
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return $normalized;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function normalizeOptionalDatetime(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            throw new RuntimeException(sprintf('%s must be a valid datetime with timezone.', $label));
        }

        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:sP');
        } catch (\Exception) {
            throw new RuntimeException(sprintf('%s must be a valid datetime with timezone.', $label));
        }
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function requestJson(string $method, string $path, array $options = []): array
    {
        $status = null;
        $data = $this->request($method, $path, $options, $status);

        if ($status < 200 || $status >= 300) {
            $message = is_array($data['error'] ?? null)
                ? trim((string) ($data['error']['message'] ?? ''))
                : '';
            throw new RuntimeException($message === '' ? sprintf('Ads API returned HTTP %d.', $status) : $message);
        }

        return $data;
    }

    /** @return array<string,mixed>|null */
    private function requestJsonOrNull(string $method, string $path): ?array
    {
        $status = null;
        $data = $this->request($method, $path, [], $status);
        if ($status === 404) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            $message = is_array($data['error'] ?? null)
                ? trim((string) ($data['error']['message'] ?? ''))
                : '';
            throw new RuntimeException($message === '' ? sprintf('Ads API returned HTTP %d.', $status) : $message);
        }

        return $data;
    }

    /** @param array<string,mixed> $options @param int|null $status @return array<string,mixed> */
    private function request(string $method, string $path, array $options, ?int &$status): array
    {
        $requestOptions = ['timeout' => 5];
        if (isset($options['json'])) {
            $json = json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                throw new RuntimeException('Could not encode JSON request.');
            }
            $requestOptions['headers'] = ['Content-Type' => 'application/json'];
            $requestOptions['body'] = $json;
        }

        $url = $this->normalizedAdsApiBaseUrl().$path;

        try {
            $response = $this->httpClient->request($method, $url, $requestOptions);
            $status = $response->getStatusCode();
            if ($status === 204) {
                return [];
            }
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $error) {
            throw new RuntimeException(sprintf('Could not reach Quick Quiz Ads API at %s.', $url), 0, $error);
        } catch (ExceptionInterface $error) {
            throw new RuntimeException(sprintf('Quick Quiz Ads API returned an invalid response from %s.', $url), 0, $error);
        }

        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Quick Quiz Ads API returned an invalid response from %s.', $url));
        }

        return $data;
    }

    private function normalizedAdsApiBaseUrl(): string
    {
        $baseUrl = rtrim(trim($this->adsApiBaseUrl), '/');
        return $baseUrl === '' ? 'http://127.0.0.1:8084' : $baseUrl;
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Could not read %s.', $path));
        }
        $data = json_decode($contents, true);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Invalid JSON object in %s.', $path));
        }
        return $data;
    }

    private function join(string ...$parts): string
    {
        return implode(DIRECTORY_SEPARATOR, array_map(
            fn (string $part): string => rtrim($part, DIRECTORY_SEPARATOR),
            $parts,
        ));
    }
}
