<?php

namespace App\Service;

use App\Storage\ContentStorage;
use JsonException;
use RuntimeException;

/** Reads and validates the complete legacy JSON catalog before any database write. */
final class QuizSourceCatalog
{
    public function __construct(private readonly ContentStorage $storage, private readonly QuizContentRules $rules)
    {
    }

    /**
     * @return array{
     *   themes:list<array<string,mixed>>,
     *   topics:array<string,list<array<string,mixed>>>,
     *   localizedTopics:array<string,array<string,list<array<string,mixed>>>>,
     *   questions:array<string,array<string,array<int,array<string,array<string,array<string,mixed>>>>>>,
     *   objects:array<string,array<string,mixed>>,
     *   counts:array<string,int>,
     *   breakdown:array<string,mixed>
     * }
     */
    public function load(): array
    {
        $keys = array_values(array_filter(
            $this->storage->list(''),
            static fn (string $key): bool => $key !== 'ads/ads.json'
                && preg_match('~^[^/]+/ai-prompts/~', $key) !== 1,
        ));
        if (!in_array('themes.json', $keys, true)) {
            throw new RuntimeException('themes.json: theme index is missing.');
        }
        $objects = [];
        $themesIndex = $this->json('themes.json');
        $themes = $this->list($themesIndex, 'themes', 'themes.json');
        $themeIds = [];
        foreach ($themes as &$theme) {
            $id = $this->identifier($theme['id'] ?? '', 'theme ID', 'themes.json');
            if (isset($themeIds[$id])) {
                throw new RuntimeException(sprintf('themes.json: duplicate theme ID "%s".', $id));
            }
            $themeIds[$id] = true;
            $theme = [
                'id' => $id,
                'name' => $this->name($theme['name'] ?? '', 'themes.json'),
                'description' => trim((string) ($theme['description'] ?? '')),
                'weight' => $this->integer($theme['weight'] ?? null, 'weight', 'themes.json'),
                'createdAt' => $this->createdAt($theme['createdAt'] ?? $theme['created_at'] ?? '', 'themes.json'),
                'active' => $this->active($theme['active'] ?? null, 'themes.json'),
            ];
        }
        unset($theme);
        usort($themes, static fn (array $a, array $b): int => [$a['weight'], $a['id']] <=> [$b['weight'], $b['id']]);
        $objects['themes.json'] = ['themes' => $themes];

        $topics = [];
        $localizedTopics = [];
        $questions = [];
        foreach ($themes as $theme) {
            $id = $theme['id'];
            $path = $id.'/index.json';
            if (!in_array($path, $keys, true)) {
                throw new RuntimeException(sprintf('%s: central topic index is missing.', $path));
            }
            $entries = $this->list($this->json($path), 'topics', $path);
            $topicIds = [];
            foreach ($entries as &$entry) {
                $key = $this->identifier($entry['key'] ?? '', 'topic key', $path);
                if (isset($topicIds[$key])) {
                    throw new RuntimeException(sprintf('%s: duplicate topic key "%s".', $path, $key));
                }
                $topicIds[$key] = true;
                $entry = [
                    'key' => $key,
                    'name' => $this->name($entry['name'] ?? '', $path),
                    'description' => trim((string) ($entry['description'] ?? '')),
                    'weight' => $this->integer($entry['weight'] ?? null, 'weight', $path),
                    'created_at' => $this->createdAt($entry['created_at'] ?? '', $path),
                    'active' => $this->active($entry['active'] ?? null, $path),
                ];
            }
            unset($entry);
            usort($entries, static fn (array $a, array $b): int => [$a['weight'], $a['key']] <=> [$b['weight'], $b['key']]);
            $topics[$id] = $entries;
            $objects[$path] = ['topics' => $entries];
            foreach ($this->rules->supportedLocales() as $locale) {
                $localizedPath = $id.'/'.$locale.'/index.json';
                if (!in_array($localizedPath, $keys, true)) {
                    throw new RuntimeException(sprintf('%s: localized topic index is missing.', $localizedPath));
                }
                $localized = $this->list($this->json($localizedPath), 'topics', $localizedPath);
                $seen = [];
                foreach ($localized as &$entry) {
                    $key = $this->identifier($entry['key'] ?? '', 'topic key', $localizedPath);
                    if (!isset($topicIds[$key]) || isset($seen[$key])) {
                        throw new RuntimeException(sprintf('%s: unknown or duplicate topic key "%s".', $localizedPath, $key));
                    }
                    $seen[$key] = true;
                    $entry = [
                        'key' => $key,
                        'name' => $this->name($entry['name'] ?? '', $localizedPath),
                        'description' => trim((string) ($entry['description'] ?? '')),
                    ];
                }
                unset($entry);
                usort($localized, static function (array $a, array $b) use ($entries): int {
                    $weights = array_column($entries, 'weight', 'key');
                    return [$weights[$a['key']], $a['key']] <=> [$weights[$b['key']], $b['key']];
                });
                $localizedTopics[$id][$locale] = $localized;
                $objects[$localizedPath] = ['topics' => $localized];
            }
        }

        foreach ($keys as $path) {
            if (isset($objects[$path])) {
                continue;
            }
            if (!preg_match('~^([^/]+)/([^/]+)/([^/]+)/([1-4])/([^/]+)\\.json$~', $path, $matches)) {
                throw new RuntimeException(sprintf('%s: unexpected catalog path.', $path));
            }
            [, $theme, $locale, $topic, $difficultyText, $id] = $matches;
            $difficulty = (int) $difficultyText;
            $this->identifier($theme, 'theme ID', $path);
            $this->identifier($topic, 'topic key', $path);
            $this->identifier($id, 'question ID', $path);
            if (!isset($themeIds[$theme]) || !in_array($topic, array_column($topics[$theme], 'key'), true)) {
                throw new RuntimeException(sprintf('%s: theme or topic is not defined.', $path));
            }
            try {
                $this->rules->assertSupportedLocale($locale);
            } catch (RuntimeException $error) {
                throw new RuntimeException(sprintf('%s: %s', $path, $error->getMessage()), previous: $error);
            }
            $raw = $this->json($path);
            $fields = array_keys($raw);
            sort($fields);
            if ($fields !== ['correctOptions', 'prompt', 'wrongOptions']
                || !is_string($raw['prompt'])
                || !is_array($raw['correctOptions']) || !array_is_list($raw['correctOptions'])
                || !is_array($raw['wrongOptions']) || !array_is_list($raw['wrongOptions'])) {
                throw new RuntimeException(sprintf('%s: invalid question JSON shape.', $path));
            }
            try {
                $payload = $this->rules->questionPayload($raw, $difficulty);
            } catch (RuntimeException $error) {
                throw new RuntimeException(sprintf('%s: %s', $path, $error->getMessage()), previous: $error);
            }
            if ($payload !== $raw) {
                throw new RuntimeException(sprintf('%s: answer values must be non-blank and unique without normalization.', $path));
            }
            $questions[$theme][$topic][$difficulty][$id][$locale] = $payload;
            $objects[$path] = $payload;
        }
        foreach ($questions as $theme => $byTopic) {
            foreach ($byTopic as $topic => $byDifficulty) {
                foreach ($byDifficulty as $difficulty => $byId) {
                    foreach ($byId as $id => $translations) {
                        $provided = array_keys($translations);
                        $expected = $this->rules->supportedLocales();
                        sort($provided);
                        sort($expected);
                        if ($provided !== $expected) {
                            throw new RuntimeException(sprintf('%s/%s/%d/%s: translations must contain exactly: %s.',
                                $theme, $topic, $difficulty, $id, implode(', ', $expected)));
                        }
                        $canonical = $translations[$this->rules->fallbackLocale()];
                        foreach ($translations as $locale => $question) {
                            if (count($question['correctOptions']) !== count($canonical['correctOptions'])
                                || count($question['wrongOptions']) !== count($canonical['wrongOptions'])) {
                                throw new RuntimeException(sprintf('%s/%s/%d/%s: locale %s changed answer counts.',
                                    $theme, $topic, $difficulty, $id, $locale));
                            }
                        }
                    }
                }
            }
        }
        ksort($objects);
        $counts = [
            'themes' => count($themes),
            'topics' => array_sum(array_map('count', $topics)),
            'topicTranslations' => array_sum(array_map(static fn (array $byLocale): int => array_sum(array_map('count', $byLocale)), $localizedTopics)),
            'questions' => 0,
            'questionTranslations' => 0,
            'correctAnswers' => 0,
            'wrongAnswers' => 0,
        ];
        foreach ($questions as $byTopic) {
            foreach ($byTopic as $byDifficulty) {
                foreach ($byDifficulty as $byId) {
                    $counts['questions'] += count($byId);
                    foreach ($byId as $translations) {
                        $counts['questionTranslations'] += count($translations);
                        foreach ($translations as $payload) {
                            $counts['correctAnswers'] += count($payload['correctOptions']);
                            $counts['wrongAnswers'] += count($payload['wrongOptions']);
                        }
                    }
                }
            }
        }
        $breakdown = [
            'byTheme' => [], 'byTopic' => [], 'byLocale' => [], 'byDifficulty' => [],
        ];
        foreach ($questions as $theme => $byTopic) {
            foreach ($byTopic as $topic => $byDifficulty) {
                foreach ($byDifficulty as $difficulty => $byId) {
                    foreach ($byId as $translations) {
                        $breakdown['byTheme'][$theme] ??= $this->reportBucket();
                        $breakdown['byTopic'][$theme][$topic] ??= $this->reportBucket();
                        $breakdown['byDifficulty'][$difficulty] ??= $this->reportBucket();
                        foreach (['byTheme' => $theme, 'byDifficulty' => $difficulty] as $group => $key) {
                            ++$breakdown[$group][$key]['questions'];
                        }
                        ++$breakdown['byTopic'][$theme][$topic]['questions'];
                        foreach ($translations as $locale => $payload) {
                            $breakdown['byLocale'][$locale] ??= $this->reportBucket();
                            ++$breakdown['byLocale'][$locale]['questions'];
                            foreach (['byTheme' => $theme, 'byDifficulty' => $difficulty, 'byLocale' => $locale] as $group => $key) {
                                ++$breakdown[$group][$key]['questionTranslations'];
                                $breakdown[$group][$key]['correctAnswers'] += count($payload['correctOptions']);
                                $breakdown[$group][$key]['wrongAnswers'] += count($payload['wrongOptions']);
                            }
                            ++$breakdown['byTopic'][$theme][$topic]['questionTranslations'];
                            $breakdown['byTopic'][$theme][$topic]['correctAnswers'] += count($payload['correctOptions']);
                            $breakdown['byTopic'][$theme][$topic]['wrongAnswers'] += count($payload['wrongOptions']);
                        }
                    }
                }
            }
        }
        return compact('themes', 'topics', 'localizedTopics', 'questions', 'objects', 'counts', 'breakdown');
    }

    /** @return array{questions:int,questionTranslations:int,correctAnswers:int,wrongAnswers:int} */
    private function reportBucket(): array
    {
        return ['questions' => 0, 'questionTranslations' => 0, 'correctAnswers' => 0, 'wrongAnswers' => 0];
    }

    /** @return array<string,mixed> */
    private function json(string $path): array
    {
        try {
            $decoded = json_decode($this->storage->read($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException(sprintf('%s: invalid JSON.', $path), previous: $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException(sprintf('%s: expected a JSON object.', $path));
        }
        return $decoded;
    }

    /** @param array<string,mixed> $index @return list<array<string,mixed>> */
    private function list(array $index, string $field, string $path): array
    {
        if (array_keys($index) !== [$field] || !is_array($index[$field]) || !array_is_list($index[$field])) {
            throw new RuntimeException(sprintf('%s: expected a %s array.', $path, $field));
        }
        foreach ($index[$field] as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new RuntimeException(sprintf('%s: invalid %s entry.', $path, $field));
            }
        }
        return $index[$field];
    }

    private function identifier(mixed $value, string $label, string $path): string
    {
        try {
            if (!is_string($value)) {
                throw new RuntimeException(sprintf('Invalid %s.', $label));
            }
            return $this->rules->normalizeIdentifier($value, $label);
        } catch (RuntimeException $error) {
            throw new RuntimeException(sprintf('%s: %s', $path, $error->getMessage()), previous: $error);
        }
    }

    private function name(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('%s: name must be a string.', $path));
        }
        $name = trim($value);
        if ($name === '') {
            throw new RuntimeException(sprintf('%s: name must not be blank.', $path));
        }
        return $name;
    }

    private function integer(mixed $value, string $field, string $path): int
    {
        if (!is_int($value)) {
            throw new RuntimeException(sprintf('%s: %s must be an integer.', $path, $field));
        }
        return $value;
    }

    private function active(mixed $value, string $path): bool
    {
        if (!is_bool($value)) {
            throw new RuntimeException(sprintf('%s: active must be a boolean.', $path));
        }
        return $value;
    }

    private function createdAt(mixed $value, string $path): string
    {
        try {
            if (!is_string($value)) {
                throw new RuntimeException('Created at must be a string.');
            }
            $timestamp = $this->rules->normalizeCreatedAtUtc($value);
        } catch (RuntimeException $error) {
            throw new RuntimeException(sprintf('%s: %s', $path, $error->getMessage()), previous: $error);
        }
        if ($timestamp === '') {
            throw new RuntimeException(sprintf('%s: created timestamp is required.', $path));
        }
        return $timestamp;
    }
}
