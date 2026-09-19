<?php

namespace App\Service;

use App\Exception\TopicTagsUnavailableException;
use App\Storage\ContentStorage;
use App\Storage\LocalContentStorage;
use RuntimeException;

final class QuizPackService implements QuizAuthoringService
{
    public const RECOMMENDATION_PROMPT_LIMIT = 50;

    /** @var list<string> */
    private array $supportedLocales;
    private readonly ContentStorage $contentStorage;
    private readonly QuizContentRules $rules;

    public function __construct(
        private readonly string $contentRoot,
        private readonly string $fallbackLocale,
        string $supportedLocales,
        private readonly ?ThemeContext $themeContext = null,
        private readonly ?string $fixedTheme = null,
        private readonly int $runQuestionLimit = 10,
        ?ContentStorage $contentStorage = null,
        ?QuizContentRules $rules = null,
    ) {
        $this->contentStorage = $contentStorage ?? new LocalContentStorage($this->contentRoot);
        $this->rules = $rules ?? new QuizContentRules($fallbackLocale, $supportedLocales);
        $this->supportedLocales = $this->rules->supportedLocales();
    }

    public function publicationInfo(): array
    {
        return [
            'apiReloadRequired' => true,
            'reason' => 'The Quiz API loads quiz content during startup.',
        ];
    }

    public function publicationStatus(): array
    {
        return ['provider' => 'legacy', 'status' => 'published', 'apiReloadRequired' => false];
    }

    public function retryPublication(): array
    {
        throw new RuntimeException('Could not retry publication while legacy quiz authoring is selected.');
    }

    public function contentRoot(): string
    {
        return $this->contentStorage->description();
    }

    public function forTheme(string $theme): self
    {
        $theme = $this->normalizeKey($theme);
        $this->assertSafeIdentifier($theme, 'theme ID');

        return new self(
            $this->contentRoot,
            $this->fallbackLocale,
            implode(',', $this->supportedLocales),
            fixedTheme: $theme,
            runQuestionLimit: $this->runQuestionLimit,
            contentStorage: $this->contentStorage,
            rules: $this->rules,
        );
    }

    public function selectedTheme(): string
    {
        if ($this->fixedTheme !== null) {
            $theme = $this->normalizeKey($this->fixedTheme);
            if ($theme !== '') {
                return $theme;
            }
        }

        if ($this->themeContext !== null) {
            return $this->themeContext->requireSelectedTheme();
        }

        throw new RuntimeException('Select a theme before managing content.');
    }

    /** @return list<array{id:string,name:string,description:string,weight:int,createdAt:string,active:bool}> */
    public function listThemes(): array
    {
        $themes = $this->readThemeIndex()['themes'];
        usort($themes, function (array $a, array $b): int {
            $weight = ((int) ($a['weight'] ?? 0)) <=> ((int) ($b['weight'] ?? 0));
            if ($weight !== 0) {
                return $weight;
            }
            return ((string) ($a['id'] ?? '')) <=> ((string) ($b['id'] ?? ''));
        });
        return $themes;
    }

    /** @return array{id:string,name:string,description:string,weight:int,createdAt:string,active:bool}|null */
    public function theme(string $id): ?array
    {
        $id = $this->normalizeKey($id);
        foreach ($this->listThemes() as $theme) {
            if ($theme['id'] === $id) {
                return $theme;
            }
        }
        return null;
    }

    /** @return array{id:string,name:string,description:string,weight:int,createdAt:string,active:bool}|null */
    public function selectedThemeMetadata(): ?array
    {
        return $this->theme($this->selectedTheme());
    }

    /** @param array<string,mixed> $input */
    public function saveTheme(array $input): void
    {
        $id = $this->normalizeKey((string) ($input['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('Theme ID is required.');
        }
        $this->assertSafeIdentifier($id, 'theme ID');

        $index = $this->readThemeIndex();
        $themes = $index['themes'];
        $payload = [
            'id' => $id,
            'name' => trim((string) ($input['name'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'weight' => (int) ($input['weight'] ?? 0),
            'createdAt' => trim((string) ($input['createdAt'] ?? '')),
            'active' => filter_var($input['active'] ?? false, FILTER_VALIDATE_BOOL),
        ];

        $found = false;
        foreach ($themes as &$theme) {
            if (($theme['id'] ?? '') !== $id) {
                continue;
            }
            $theme = $payload;
            $found = true;
            break;
        }
        unset($theme);

        if (!$found) {
            $themes[] = $payload;
        }

        $index['themes'] = $this->sortThemes($themes);
        $this->validateThemeIndex($index);
        $this->writeJson($this->themeIndexPath(), $index);
    }

    /** @return array{deletedObjects:int} */
    public function deleteTheme(string $id, bool $recursive = false): array
    {
        $id = $this->normalizeKey($id);
        $this->assertSafeIdentifier($id, 'theme ID');
        if ($this->theme($id) === null) {
            throw new RuntimeException(sprintf('Theme "%s" is not defined.', $id));
        }

        $keys = $this->contentStorage->list($id);
        if ($keys !== [] && !$recursive) {
            throw new RuntimeException(sprintf('Theme "%s" contains content; recursive deletion is required.', $id));
        }

        $index = $this->readThemeIndex();
        $index['themes'] = array_values(array_filter(
            $index['themes'],
            fn (array $theme): bool => $this->normalizeKey((string) ($theme['id'] ?? '')) !== $id,
        ));
        $this->validateThemeIndex($index);
        $this->writeJsonMutation([$this->themeIndexPath() => $index], $keys);

        return ['deletedObjects' => count($keys)];
    }

    public function fallbackLocale(): string
    {
        return $this->fallbackLocale;
    }

    /** @return list<string> */
    public function supportedLocales(): array
    {
        return $this->supportedLocales;
    }

    /** @return array<int, array{label:string, optionCount:int, wrongRequired:int}> */
    public function difficulties(): array
    {
        return $this->rules->difficulties();
    }

    /** @return array{topics:list<array<string,mixed>>} */
    public function readCentralCatalog(): array
    {
        $path = $this->join($this->themeRoot(), 'index.json');
        if (!$this->contentStorage->exists($path)) {
            return ['topics' => []];
        }

        $data = $this->readJson($path);
        $topics = $data['topics'] ?? [];
        if (!is_array($topics)) {
            return ['topics' => []];
        }

        return ['topics' => array_values(array_filter($topics, 'is_array'))];
    }

    /** @return array{topics:list<array<string,mixed>>} */
    public function readLocalizedCatalog(string $locale): array
    {
        $this->assertSupportedLocale($locale);
        $path = $this->join($this->themeRoot(), $locale, 'index.json');
        if (!$this->contentStorage->exists($path)) {
            return ['topics' => []];
        }

        $data = $this->readJson($path);
        $topics = $data['topics'] ?? [];
        if (!is_array($topics)) {
            return ['topics' => []];
        }

        return ['topics' => array_values(array_filter($topics, 'is_array'))];
    }

    /** @return list<array<string,mixed>> */
    public function listTopics(): array
    {
        $central = $this->readCentralCatalog()['topics'];
        $counts = $this->questionCountsByTopic();

        return array_map(function (array $topic) use ($counts): array {
            $key = trim((string) ($topic['key'] ?? ''));
            $topic['key'] = $key;
            $topic['active'] = (bool) ($topic['active'] ?? false);
            $topic['weight'] = (int) ($topic['weight'] ?? 0);
            $topic['questionCount'] = $counts[$key] ?? 0;
            return $topic;
        }, $central);
    }

    /** @return list<array<string,mixed>> */
    public function topicViews(string $locale): array
    {
        $this->assertSupportedLocale($locale);
        return array_map(function (array $topic) use ($locale): array {
            $key = (string) $topic['key'];
            $localized = $this->localizedTopic($locale, $key);
            $counts = [];
            foreach (array_keys($this->difficulties()) as $difficulty) {
                $counts[(string) $difficulty] = count($this->listQuestions($this->fallbackLocale(), $key, (int) $difficulty));
            }
            return $topic + [
                'displayLocale' => $locale,
                'displayName' => trim((string) ($localized['name'] ?? '')) ?: (string) ($topic['name'] ?? ''),
                'displayDescription' => trim((string) ($localized['description'] ?? '')) ?: (string) ($topic['description'] ?? ''),
                'questionCounts' => $counts,
            ];
        }, $this->listTopics());
    }

    /** @return array<string,mixed>|null */
    public function topic(string $key): ?array
    {
        $key = $this->normalizeKey($key);
        foreach ($this->listTopics() as $topic) {
            if (($topic['key'] ?? '') === $key) {
                return $topic;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $input */
    public function saveTopic(array $input): array
    {
        if (array_key_exists('tags', $input)) {
            throw new TopicTagsUnavailableException();
        }
        $key = $this->normalizeKey((string) ($input['key'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('Topic key is required.');
        }

        $catalog = $this->readCentralCatalog();
        $topics = $catalog['topics'];
        $found = false;
        foreach ($topics as &$topic) {
            if ($this->normalizeKey((string) ($topic['key'] ?? '')) !== $key) {
                continue;
            }
            $topic = $this->topicPayload($input, $key);
            $found = true;
            break;
        }
        unset($topic);

        if (!$found) {
            $topics[] = $this->topicPayload($input, $key);
        }

        $catalog['topics'] = $this->sortTopics($topics);
        $this->validateCentralCatalog($catalog);
        $this->writeJson($this->join($this->themeRoot(), 'index.json'), $catalog);
        return $this->publicationInfo();
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,array<string,mixed>> $localizations
     */
    public function saveTopicSet(array $input, array $localizations = []): array
    {
        if (array_key_exists('tags', $input)) {
            throw new TopicTagsUnavailableException();
        }
        $key = $this->normalizeKey((string) ($input['key'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('Topic key is required.');
        }
        $this->assertSafeIdentifier($key, 'topic key');

        $central = $this->readCentralCatalog();
        $found = false;
        foreach ($central['topics'] as &$topic) {
            if ($this->normalizeKey((string) ($topic['key'] ?? '')) !== $key) {
                continue;
            }
            $topic = $this->topicPayload($input, $key);
            $found = true;
            break;
        }
        unset($topic);
        if (!$found) {
            $central['topics'][] = $this->topicPayload($input, $key);
        }
        $central['topics'] = $this->sortTopics($central['topics']);
        $this->validateCentralCatalog($central);
        $centralKeys = array_flip(array_map(
            fn (array $topic): string => $this->normalizeKey((string) ($topic['key'] ?? '')),
            $central['topics'],
        ));

        $writes = [$this->join($this->themeRoot(), 'index.json') => $central];
        foreach ($localizations as $locale => $localizedInput) {
            $locale = (string) $locale;
            $this->assertSupportedLocale($locale);
            if (!is_array($localizedInput)) {
                throw new RuntimeException(sprintf('Localization %s must be an object.', $locale));
            }

            $catalog = $this->readLocalizedCatalog($locale);
            $payload = [
                'key' => $key,
                'name' => trim((string) ($localizedInput['name'] ?? '')),
                'description' => trim((string) ($localizedInput['description'] ?? '')),
            ];
            $localizedFound = false;
            foreach ($catalog['topics'] as &$localizedTopic) {
                if ($this->normalizeKey((string) ($localizedTopic['key'] ?? '')) !== $key) {
                    continue;
                }
                $localizedTopic = $payload;
                $localizedFound = true;
                break;
            }
            unset($localizedTopic);
            if (!$localizedFound) {
                $catalog['topics'][] = $payload;
            }
            $catalog['topics'] = $this->sortTopics($catalog['topics']);
            $this->validateLocalizedCatalog($locale, $catalog, centralKeys: $centralKeys);
            $writes[$this->join($this->themeRoot(), $locale, 'index.json')] = $catalog;
        }

        $this->writeJsonSet($writes);
        return $this->publicationInfo();
    }

    public function deleteTopic(string $key): void
    {
        $key = $this->normalizeKey($key);
        $catalog = $this->readCentralCatalog();
        $catalog['topics'] = array_values(array_filter(
            $catalog['topics'],
            fn (array $topic): bool => $this->normalizeKey((string) ($topic['key'] ?? '')) !== $key,
        ));
        $this->writeJson($this->join($this->themeRoot(), 'index.json'), $catalog);
    }

    /** @param array<string,mixed> $input */
    public function saveLocalizedTopic(string $locale, array $input): void
    {
        $this->assertSupportedLocale($locale);
        $key = $this->normalizeKey((string) ($input['key'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('Topic key is required.');
        }

        $this->assertCentralTopicExists($key);

        $catalog = $this->readLocalizedCatalog($locale);
        $topics = $catalog['topics'];
        $payload = [
            'key' => $key,
            'name' => trim((string) ($input['name'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
        ];

        $found = false;
        foreach ($topics as &$topic) {
            if ($this->normalizeKey((string) ($topic['key'] ?? '')) !== $key) {
                continue;
            }
            $topic = $payload;
            $found = true;
            break;
        }
        unset($topic);

        if (!$found) {
            $topics[] = $payload;
        }

        $catalog['topics'] = $this->sortTopics($topics);
        $this->validateLocalizedCatalog($locale, $catalog);
        $this->writeJson($this->join($this->themeRoot(), $locale, 'index.json'), $catalog);
    }

    /** @return array{key:string,name:string,description:string}|null */
    public function localizedTopic(string $locale, string $key): ?array
    {
        $this->assertSupportedLocale($locale);
        $key = $this->normalizeKey($key);
        foreach ($this->readLocalizedCatalog($locale)['topics'] as $topic) {
            if ($this->normalizeKey((string) ($topic['key'] ?? '')) === $key) {
                return [
                    'key' => $key,
                    'name' => trim((string) ($topic['name'] ?? '')),
                    'description' => trim((string) ($topic['description'] ?? '')),
                ];
            }
        }

        return null;
    }

    /** @return array{deletedQuestionFiles:int} */
    public function deleteTopicPackage(string $key, bool $recursive = false): array
    {
        $key = $this->normalizeKey($key);
        $this->assertSafeIdentifier($key, 'topic key');
        if ($this->topic($key) === null) {
            throw new RuntimeException(sprintf('Topic "%s" is not defined in central catalog.', $key));
        }

        $questionKeys = [];
        foreach ($this->supportedLocales as $locale) {
            $questionKeys = array_merge(
                $questionKeys,
                $this->contentStorage->list($this->join($this->themeRoot(), $locale, $key)),
            );
        }
        $questionKeys = array_values(array_unique($questionKeys));
        if ($questionKeys !== [] && !$recursive) {
            throw new RuntimeException(sprintf('Topic "%s" contains questions; recursive deletion is required.', $key));
        }

        $central = $this->readCentralCatalog();
        $central['topics'] = array_values(array_filter(
            $central['topics'],
            fn (array $topic): bool => $this->normalizeKey((string) ($topic['key'] ?? '')) !== $key,
        ));
        $writes = [$this->join($this->themeRoot(), 'index.json') => $central];

        foreach ($this->supportedLocales as $locale) {
            $path = $this->join($this->themeRoot(), $locale, 'index.json');
            if (!$this->contentStorage->exists($path)) {
                continue;
            }
            $catalog = $this->readLocalizedCatalog($locale);
            $catalog['topics'] = array_values(array_filter(
                $catalog['topics'],
                fn (array $topic): bool => $this->normalizeKey((string) ($topic['key'] ?? '')) !== $key,
            ));
            $writes[$path] = $catalog;
        }

        $this->writeJsonMutation($writes, $questionKeys);

        return ['deletedQuestionFiles' => count($questionKeys)];
    }

    /** @return list<array{id:string,path:string,prompt:string,correctCount:int,wrongCount:int}> */
    public function listQuestions(string $locale, string $topic, int $difficulty): array
    {
        $this->assertSupportedLocale($locale);
        $this->assertDifficulty($difficulty);
        $topic = $this->normalizeKey($topic);
        $prefix = $this->join($this->themeRoot(), $locale, $topic, (string) $difficulty);
        $questions = [];
        foreach ($this->contentStorage->list($prefix) as $key) {
            $entry = str_starts_with($key, $prefix.'/') ? substr($key, strlen($prefix) + 1) : '';
            if ($entry === '' || str_contains($entry, '/') || !str_ends_with($entry, '.json')) {
                continue;
            }
            $question = $this->readJson($key);
            $questions[] = [
                'id' => basename($entry, '.json'),
                'path' => $this->contentStorage->location($key),
                'prompt' => (string) ($question['prompt'] ?? ''),
                'correctCount' => count($question['correctOptions'] ?? []),
                'wrongCount' => count($question['wrongOptions'] ?? []),
            ];
        }

        usort($questions, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        return $questions;
    }

    /** @return array<string,mixed> */
    public function contentStats(): array
    {
        $topics = $this->listTopics();
        $topicKeys = array_map(static fn (array $topic): string => (string) $topic['key'], $topics);
        $runLimit = max(1, $this->runQuestionLimit);
        $stats = [
            'theme' => $this->selectedTheme(),
            'fallbackLocale' => $this->fallbackLocale,
            'runQuestionLimit' => $runLimit,
            'totals' => [
                'questions' => 0,
                'correctAnswers' => 0,
                'wrongAnswers' => 0,
                'canonicalQuestions' => 0,
                'activeTopics' => 0,
                'inactiveTopics' => 0,
                'activeTopicQuestions' => 0,
            ],
            'averages' => [
                'correctAnswersPerQuestion' => 0.0,
                'wrongAnswersPerQuestion' => 0.0,
            ],
            'runCapacity' => [
                'total' => 0,
                'byTopic' => [],
            ],
            'byLocale' => [],
            'byTopic' => [],
            'byDifficulty' => [],
            'zeroQuestionTopics' => [],
            'topicsBelowRunLimit' => [],
            'localeQuestionRange' => [
                'min' => 0,
                'max' => 0,
            ],
            'localeParityIssues' => array_values(array_filter(
                $this->validateLocaleParity(),
                static fn (string $issue): bool => str_starts_with($issue, 'Locale '),
            )),
        ];

        foreach ($this->supportedLocales as $locale) {
            $stats['byLocale'][$locale] = $this->emptyStatsBucket();
        }
        foreach ($topics as $topic) {
            $key = (string) $topic['key'];
            if ((bool) ($topic['active'] ?? false)) {
                $stats['totals']['activeTopics']++;
            } else {
                $stats['totals']['inactiveTopics']++;
            }
            $stats['byTopic'][$key] = $this->emptyStatsBucket() + [
                'name' => (string) ($topic['name'] ?? $key),
                'active' => (bool) ($topic['active'] ?? false),
                'canonicalQuestions' => 0,
                'difficultyQuestions' => [],
            ];
        }
        foreach (array_keys($this->rules->difficulties()) as $difficulty) {
            $stats['byDifficulty'][$difficulty] = $this->emptyStatsBucket() + [
                'label' => $this->rules->difficulties()[$difficulty]['label'],
            ];
        }

        foreach ($this->supportedLocales as $locale) {
            foreach ($topicKeys as $topic) {
                foreach (array_keys($this->rules->difficulties()) as $difficulty) {
                    $questions = $this->listQuestions($locale, $topic, (int) $difficulty);
                    foreach ($questions as $question) {
                        $this->addQuestionStats($stats['totals'], $question);
                        $this->addQuestionStats($stats['byLocale'][$locale], $question);
                        $this->addQuestionStats($stats['byTopic'][$topic], $question);
                        $this->addQuestionStats($stats['byDifficulty'][$difficulty], $question);
                    }
                }
            }
        }

        foreach ($topicKeys as $topic) {
            foreach (array_keys($this->rules->difficulties()) as $difficulty) {
                $canonicalQuestions = count($this->listQuestions($this->fallbackLocale, $topic, (int) $difficulty));
                $stats['totals']['canonicalQuestions'] += $canonicalQuestions;
                $stats['byTopic'][$topic]['canonicalQuestions'] += $canonicalQuestions;
                $stats['byTopic'][$topic]['difficultyQuestions'][$difficulty] = $canonicalQuestions;
            }
            if ($stats['byTopic'][$topic]['active']) {
                $stats['totals']['activeTopicQuestions'] += $stats['byTopic'][$topic]['canonicalQuestions'];
            }
            $stats['runCapacity']['byTopic'][$topic] = intdiv($stats['byTopic'][$topic]['canonicalQuestions'], $runLimit);
            if ($stats['byTopic'][$topic]['canonicalQuestions'] === 0) {
                $stats['zeroQuestionTopics'][] = $topic;
            }
            if ($stats['byTopic'][$topic]['canonicalQuestions'] < $runLimit) {
                $stats['topicsBelowRunLimit'][] = $topic;
            }
        }

        $stats['runCapacity']['total'] = intdiv($stats['totals']['canonicalQuestions'], $runLimit);
        if ($stats['totals']['questions'] > 0) {
            $stats['averages']['correctAnswersPerQuestion'] = round($stats['totals']['correctAnswers'] / $stats['totals']['questions'], 2);
            $stats['averages']['wrongAnswersPerQuestion'] = round($stats['totals']['wrongAnswers'] / $stats['totals']['questions'], 2);
        }

        $localeQuestionCounts = array_map(
            static fn (array $bucket): int => (int) $bucket['questions'],
            $stats['byLocale'],
        );
        if ($localeQuestionCounts !== []) {
            $stats['localeQuestionRange']['min'] = min($localeQuestionCounts);
            $stats['localeQuestionRange']['max'] = max($localeQuestionCounts);
        }

        return $stats;
    }

    /** @return array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} */
    public function readQuestion(string $locale, string $topic, int $difficulty, string $questionId): array
    {
        $path = $this->questionPath($locale, $topic, $difficulty, $questionId);
        if (!$this->contentStorage->exists($path)) {
            return ['prompt' => '', 'correctOptions' => [], 'wrongOptions' => []];
        }

        $question = $this->readJson($path);
        return $this->normalizeQuestionPayload($question);
    }

    public function nextQuestionId(string $topic, int $difficulty): string
    {
        $this->assertCentralTopicExists($topic);
        $ids = array_map(
            static fn (array $question): string => $question['id'],
            $this->listQuestions($this->fallbackLocale, $topic, $difficulty),
        );

        return $this->rules->nextQuestionId($topic, $difficulty, $ids);
    }

    /** @param array<string,mixed> $input */
    public function saveQuestion(string $locale, string $topic, int $difficulty, string $questionId, array $input): string
    {
        $questionId = trim($questionId);
        if ($questionId === '') {
            $questionId = $this->nextQuestionId($topic, $difficulty);
        }

        $this->assertQuestionPackageAllowed($locale, $topic, $difficulty, $questionId);

        $question = $this->validatedQuestionPayload($input, $difficulty);

        $this->writeJson($this->questionPath($locale, $topic, $difficulty, $questionId), $question);
        return $questionId;
    }

    /** @param array<string,mixed> $input */
    public function createReplicatedQuestionSet(string $sourceLocale, string $topic, int $difficulty, string $questionId, array $input): string
    {
        $this->assertSupportedLocale($sourceLocale);
        $questionId = trim($questionId);
        if ($questionId === '') {
            $questionId = $this->nextQuestionId($topic, $difficulty);
        }

        $this->assertCentralTopicExists($topic);
        $this->assertDifficulty($difficulty);
        $this->assertSafeIdentifier($questionId, 'question ID');
        $this->assertQuestionIdAvailableInAllLocales($topic, $difficulty, $questionId);

        $question = $this->validatedQuestionPayload($input, $difficulty);
        $payloads = [];
        foreach ($this->supportedLocales as $locale) {
            $payloads[$this->questionPath($locale, $topic, $difficulty, $questionId)] = $question;
        }

        $this->writeJsonSet($payloads);
        return $questionId;
    }

    /** @return array<string,array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}> */
    public function readLocalizedQuestionSet(string $topic, int $difficulty, string $questionId): array
    {
        $this->assertExistingQuestionSet($topic, $difficulty, $questionId);

        $questions = [];
        foreach ($this->supportedLocales as $locale) {
            $questions[$locale] = $this->readQuestion($locale, $topic, $difficulty, $questionId);
        }
        return $questions;
    }

    /** @param array<string,array<string,mixed>> $localizedInputs */
    public function updateManualLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $localizedInputs): void
    {
        $this->assertExistingQuestionSet($topic, $difficulty, $questionId);

        $payloads = [];
        $canonical = $this->validatedQuestionPayload($localizedInputs[$this->fallbackLocale] ?? [], $difficulty);
        $correctCount = count($canonical['correctOptions']);
        $wrongCount = count($canonical['wrongOptions']);

        foreach ($this->supportedLocales as $locale) {
            $payload = $locale === $this->fallbackLocale
                ? $canonical
                : $this->validatedQuestionPayload($localizedInputs[$locale] ?? [], $difficulty);

            if (count($payload['correctOptions']) !== $correctCount) {
                throw new RuntimeException(sprintf('Locale %s must contain %d correct option(s) to match canonical locale %s.', $locale, $correctCount, $this->fallbackLocale));
            }
            if (count($payload['wrongOptions']) !== $wrongCount) {
                throw new RuntimeException(sprintf('Locale %s must contain %d wrong option(s) to match canonical locale %s.', $locale, $wrongCount, $this->fallbackLocale));
            }

            $payloads[$this->questionPath($locale, $topic, $difficulty, $questionId)] = $payload;
        }

        $this->writeJsonSet($payloads);
    }

    /**
     * @param list<array{id?:string,translations?:array<string,array<string,mixed>>}> $items
     * @return list<string>
     */
    public function createLocalizedQuestionSets(string $topic, int $difficulty, array $items): array
    {
        $this->assertCentralTopicExists($topic);
        $this->assertDifficulty($difficulty);
        if ($items === [] || count($items) > 50) {
            throw new RuntimeException('Question batch must contain between 1 and 50 items.');
        }

        $provided = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Every question batch item must be an object.');
            }
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $this->assertSafeIdentifier($id, 'question ID');
            if (isset($provided[$id])) {
                throw new RuntimeException(sprintf('Question ID "%s" is duplicated in the batch.', $id));
            }
            $this->assertQuestionIdAvailableInAllLocales($topic, $difficulty, $id);
            $provided[$id] = true;
        }

        $nextId = $this->nextQuestionId($topic, $difficulty);
        $prefix = sprintf('%s-%d-', $this->normalizeKey($topic), $difficulty);
        $nextSequence = (int) substr($nextId, strlen($prefix));
        $reserved = $provided;
        $ids = [];
        $payloads = [];

        foreach ($items as $item) {
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                do {
                    $id = sprintf('%s-%d-%03d', $this->normalizeKey($topic), $difficulty, $nextSequence++);
                } while (isset($reserved[$id]));
                $this->assertQuestionIdAvailableInAllLocales($topic, $difficulty, $id);
                $reserved[$id] = true;
            }

            $translations = $item['translations'] ?? null;
            if (!is_array($translations)) {
                throw new RuntimeException(sprintf('Question "%s" translations must be an object.', $id));
            }
            $providedLocales = array_map('strval', array_keys($translations));
            sort($providedLocales);
            $expectedLocales = $this->supportedLocales;
            sort($expectedLocales);
            if ($providedLocales !== $expectedLocales) {
                throw new RuntimeException(sprintf(
                    'Question "%s" translations must contain exactly: %s.',
                    $id,
                    implode(', ', $expectedLocales),
                ));
            }

            $canonical = $this->validatedQuestionPayload(
                is_array($translations[$this->fallbackLocale] ?? null) ? $translations[$this->fallbackLocale] : [],
                $difficulty,
            );
            foreach ($this->supportedLocales as $locale) {
                $localizedInput = $translations[$locale] ?? [];
                $localized = $this->validatedQuestionPayload(is_array($localizedInput) ? $localizedInput : [], $difficulty);
                if (count($localized['correctOptions']) !== count($canonical['correctOptions'])) {
                    throw new RuntimeException(sprintf('Localization %s changed the number of correct options for question "%s".', $locale, $id));
                }
                if (count($localized['wrongOptions']) !== count($canonical['wrongOptions'])) {
                    throw new RuntimeException(sprintf('Localization %s changed the number of wrong options for question "%s".', $locale, $id));
                }
                $payloads[$this->questionPath($locale, $topic, $difficulty, $id)] = $localized;
            }
            $ids[] = $id;
        }

        $this->writeJsonSet($payloads);

        return $ids;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{questionId:string, question:array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}}
     */
    public function prepareNewLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $input): array
    {
        $questionId = trim($questionId);
        if ($questionId === '') {
            $questionId = $this->nextQuestionId($topic, $difficulty);
        }

        $this->assertCentralTopicExists($topic);
        $this->assertDifficulty($difficulty);
        $this->assertSafeIdentifier($questionId, 'question ID');
        $this->assertQuestionIdAvailableInAllLocales($topic, $difficulty, $questionId);

        return [
            'questionId' => $questionId,
            'question' => $this->validatedQuestionPayload($input, $difficulty),
        ];
    }

    /**
     * @param array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} $sourceQuestion
     * @param array<string,array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}> $localizations
     */
    public function saveLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $sourceQuestion, array $localizations, bool $copySourceAnswers = false): void
    {
        $this->assertCentralTopicExists($topic);
        $this->assertDifficulty($difficulty);
        $this->assertSafeIdentifier($questionId, 'question ID');
        $this->assertQuestionIdAvailableInAllLocales($topic, $difficulty, $questionId);

        $this->writeLocalizedQuestionSet($topic, $difficulty, $questionId, $sourceQuestion, $localizations, $copySourceAnswers);
    }

    /**
     * @param array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} $sourceQuestion
     * @param array<string,array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}> $localizations
     */
    public function updateAiLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $sourceQuestion, array $localizations, bool $copySourceAnswers = false): void
    {
        $this->assertExistingQuestionSet($topic, $difficulty, $questionId);
        $this->writeLocalizedQuestionSet($topic, $difficulty, $questionId, $sourceQuestion, $localizations, $copySourceAnswers);
    }

    /**
     * @param array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} $sourceQuestion
     * @param array<string,array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}> $localizations
     */
    private function writeLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $sourceQuestion, array $localizations, bool $copySourceAnswers): void
    {
        $sourceQuestion = $this->validatedQuestionPayload($sourceQuestion, $difficulty);

        $expectedLocales = $this->supportedLocales;
        $missing = array_values(array_diff($expectedLocales, array_keys($localizations)));
        if ($missing !== []) {
            throw new RuntimeException(sprintf('Missing localizations for locale(s): %s.', implode(', ', $missing)));
        }

        $payloads = [];
        foreach ($expectedLocales as $locale) {
            $localizedInput = $localizations[$locale] ?? [];
            if ($copySourceAnswers) {
                $localizedInput['correctOptions'] = $sourceQuestion['correctOptions'];
                $localizedInput['wrongOptions'] = $sourceQuestion['wrongOptions'];
            }
            $payload = $this->validatedQuestionPayload($localizedInput, $difficulty);
            if (count($payload['correctOptions']) !== count($sourceQuestion['correctOptions'])) {
                throw new RuntimeException(sprintf('Localization %s changed the number of correct options.', $locale));
            }
            if (count($payload['wrongOptions']) !== count($sourceQuestion['wrongOptions'])) {
                throw new RuntimeException(sprintf('Localization %s changed the number of wrong options.', $locale));
            }
            $payloads[$this->questionPath($locale, $topic, $difficulty, $questionId)] = $payload;
        }

        $this->writeJsonSet($payloads);
    }

    /** @return list<string> */
    public function recommendationPrompts(string $locale, string $topic, int $difficulty): array
    {
        return array_slice(array_values(array_filter(
            array_map(
                static fn (array $question): string => trim($question['prompt']),
                $this->listQuestions($locale, $topic, $difficulty),
            ),
            static fn (string $prompt): bool => $prompt !== '',
        )), 0, self::RECOMMENDATION_PROMPT_LIMIT);
    }

    /** @return array{theme:string, key:string, name:string, description:string} */
    public function recommendationTopicMetadata(string $locale, string $topic): array
    {
        $this->assertSupportedLocale($locale);
        $key = $this->normalizeKey($topic);
        $central = $this->topicMetadataFromCatalog($this->readCentralCatalog(), $key);
        if ($central === null) {
            throw new RuntimeException(sprintf('Topic "%s" is not defined in central catalog.', $key));
        }

        $localized = $this->topicMetadataFromCatalog($this->readLocalizedCatalog($locale), $key);

        return [
            'theme' => $this->selectedTheme(),
            'key' => $key,
            'name' => ($localized['name'] ?? '') !== '' ? $localized['name'] : $central['name'],
            'description' => ($localized['description'] ?? '') !== '' ? $localized['description'] : $central['description'],
        ];
    }

    /**
     * @param array<string,mixed> $draft
     * @return array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}
     */
    public function validateRecommendedQuestionDraft(string $locale, string $topic, int $difficulty, array $draft): array
    {
        $question = $this->validatedQuestionPayload($draft, $difficulty);
        $existingPrompts = array_map(
            static fn (array $existing): string => $existing['prompt'],
            $this->listQuestions($locale, $topic, $difficulty),
        );
        if (in_array($question['prompt'], $existingPrompts, true)) {
            throw new RuntimeException('Recommended prompt duplicates an existing question.');
        }
        return $question;
    }

    /**
     * @param array<string,mixed> $draft
     * @return array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}
     */
    public function validateRecommendedAnswerDraft(int $difficulty, string $prompt, array $draft): array
    {
        return $this->validatedQuestionPayload([
            'prompt' => $prompt,
            'correctOptions' => $draft['correctOptions'] ?? [],
            'wrongOptions' => $draft['wrongOptions'] ?? [],
        ], $difficulty);
    }

    /** @return array{deletedLocales:list<string>, missingLocales:list<string>} */
    public function deleteQuestion(string $topic, int $difficulty, string $questionId): array
    {
        $this->assertCentralTopicExists($topic);
        $this->assertDifficulty($difficulty);
        $this->assertSafeIdentifier($questionId, 'question ID');

        $deletedLocales = [];
        $missingLocales = [];
        foreach ($this->supportedLocales as $locale) {
            $path = $this->questionPath($locale, $topic, $difficulty, $questionId);
            if (!$this->contentStorage->exists($path)) {
                $missingLocales[] = $locale;
                continue;
            }
            if (!$this->contentStorage->delete($path)) {
                throw new RuntimeException(sprintf('Could not delete question file for locale %s.', $locale));
            }
            $deletedLocales[] = $locale;
        }

        return ['deletedLocales' => $deletedLocales, 'missingLocales' => $missingLocales];
    }

    /** @return array{deletedLocales:list<string>, missingLocales:list<string>} */
    public function deleteLocalizedQuestionSet(string $topic, int $difficulty, string $questionId): array
    {
        $this->assertExistingQuestionSet($topic, $difficulty, $questionId);

        $paths = [];
        foreach ($this->supportedLocales as $locale) {
            $paths[] = $this->questionPath($locale, $topic, $difficulty, $questionId);
        }
        $this->writeJsonMutation([], $paths);

        return ['deletedLocales' => $this->supportedLocales, 'missingLocales' => []];
    }

    /** @return list<string> */
    public function validateAll(): array
    {
        $errors = [];
        $central = $this->readCentralCatalog();
        $errors = array_merge($errors, $this->validateCentralCatalog($central, throw: false));

        foreach ($this->supportedLocales as $locale) {
            $errors = array_merge($errors, $this->validateLocalizedCatalog($locale, $this->readLocalizedCatalog($locale), throw: false));
            $errors = array_merge($errors, $this->validateQuestionTree($locale));
        }

        $errors = array_merge($errors, $this->validateLocaleParity());

        return $errors;
    }

    /** @return list<string> */
    public function activeTopicKeys(): array
    {
        $keys = [];
        foreach ($this->readCentralCatalog()['topics'] as $topic) {
            if ((bool) ($topic['active'] ?? false)) {
                $key = $this->normalizeKey((string) ($topic['key'] ?? ''));
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }
        sort($keys);
        return $keys;
    }

    /** @return list<string> */
    public function topicKeys(): array
    {
        $keys = [];
        foreach ($this->readCentralCatalog()['topics'] as $topic) {
            $key = $this->normalizeKey((string) ($topic['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        sort($keys);
        return $keys;
    }

    /** @return list<array{key:string, active:bool}> */
    public function topicChoices(): array
    {
        $choices = [];
        foreach ($this->listTopics() as $topic) {
            $key = $this->normalizeKey((string) ($topic['key'] ?? ''));
            if ($key !== '') {
                $choices[] = [
                    'key' => $key,
                    'active' => (bool) ($topic['active'] ?? false),
                ];
            }
        }
        usort($choices, fn (array $a, array $b): int => $a['key'] <=> $b['key']);
        return $choices;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function topicPayload(array $input, string $key): array
    {
        return [
            'key' => $key,
            'name' => trim((string) ($input['name'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'weight' => (int) ($input['weight'] ?? 0),
            'created_at' => $this->normalizeTopicCreatedAtUtc((string) ($input['created_at'] ?? '')),
            'active' => filter_var($input['active'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    private function normalizeTopicCreatedAtUtc(string $createdAt): string
    {
        return $this->rules->normalizeCreatedAtUtc($createdAt);
    }

    /** @return array{questions:int, correctAnswers:int, wrongAnswers:int} */
    private function emptyStatsBucket(): array
    {
        return [
            'questions' => 0,
            'correctAnswers' => 0,
            'wrongAnswers' => 0,
        ];
    }

    /** @param array<string,mixed> $bucket @param array{correctCount:int, wrongCount:int} $question */
    private function addQuestionStats(array &$bucket, array $question): void
    {
        $bucket['questions'] = (int) ($bucket['questions'] ?? 0) + 1;
        $bucket['correctAnswers'] = (int) ($bucket['correctAnswers'] ?? 0) + (int) $question['correctCount'];
        $bucket['wrongAnswers'] = (int) ($bucket['wrongAnswers'] ?? 0) + (int) $question['wrongCount'];
    }

    /** @param list<array<string,mixed>> $topics @return list<array<string,mixed>> */
    private function sortTopics(array $topics): array
    {
        usort($topics, function (array $a, array $b): int {
            $weight = ((int) ($a['weight'] ?? 0)) <=> ((int) ($b['weight'] ?? 0));
            if ($weight !== 0) {
                return $weight;
            }
            return ((string) ($a['key'] ?? '')) <=> ((string) ($b['key'] ?? ''));
        });
        return $topics;
    }

    /** @param array{topics:list<array<string,mixed>>} $catalog @return array{name:string, description:string}|null */
    private function topicMetadataFromCatalog(array $catalog, string $key): ?array
    {
        foreach ($catalog['topics'] as $topic) {
            if ($this->normalizeKey((string) ($topic['key'] ?? '')) === $key) {
                return [
                    'name' => trim((string) ($topic['name'] ?? '')),
                    'description' => trim((string) ($topic['description'] ?? '')),
                ];
            }
        }
        return null;
    }

    /** @return array<string,int> */
    private function questionCountsByTopic(): array
    {
        $counts = [];
        $locale = $this->fallbackLocale;
        foreach ($this->activeTopicKeys() as $topic) {
            foreach (array_keys($this->rules->difficulties()) as $difficulty) {
                $counts[$topic] = ($counts[$topic] ?? 0) + count($this->listQuestions($locale, $topic, (int) $difficulty));
            }
        }
        return $counts;
    }

    /** @param array<string,mixed> $catalog @return list<string> */
    private function validateCentralCatalog(array $catalog, bool $throw = true): array
    {
        $errors = [];
        $seen = [];
        foreach (($catalog['topics'] ?? []) as $topic) {
            if (!is_array($topic)) {
                $errors[] = 'Central catalog contains an invalid topic entry.';
                continue;
            }
            $key = $this->normalizeKey((string) ($topic['key'] ?? ''));
            if ($key === '') {
                $errors[] = 'Central catalog contains a topic without key.';
                continue;
            }
            if (isset($seen[$key])) {
                $errors[] = sprintf('Central catalog has duplicate topic key "%s".', $key);
            }
            $seen[$key] = true;
        }

        if ($throw && $errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
        return $errors;
    }

    /** @param array<string,mixed> $catalog @return list<string> */
    private function validateLocalizedCatalog(string $locale, array $catalog, bool $throw = true, ?array $centralKeys = null): array
    {
        $errors = [];
        $centralKeys ??= array_flip(array_map(
            fn (array $topic): string => $this->normalizeKey((string) ($topic['key'] ?? '')),
            $this->readCentralCatalog()['topics'],
        ));
        $seen = [];

        foreach (($catalog['topics'] ?? []) as $topic) {
            if (!is_array($topic)) {
                $errors[] = sprintf('Localized catalog %s contains an invalid topic entry.', $locale);
                continue;
            }
            $key = $this->normalizeKey((string) ($topic['key'] ?? ''));
            if ($key === '') {
                $errors[] = sprintf('Localized catalog %s contains a topic without key.', $locale);
                continue;
            }
            if (isset($seen[$key])) {
                $errors[] = sprintf('Localized catalog %s has duplicate topic key "%s".', $locale, $key);
            }
            if (!isset($centralKeys[$key])) {
                $errors[] = sprintf('Localized catalog %s has unknown topic key "%s".', $locale, $key);
            }
            $seen[$key] = true;
        }

        if ($throw && $errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
        return $errors;
    }

    /** @return list<string> */
    private function validateQuestionTree(string $locale): array
    {
        $errors = [];
        foreach ($this->activeTopicKeys() as $topic) {
            $topicPrefix = $this->join($this->themeRoot(), $locale, $topic);
            $difficultyDirectories = [];
            foreach ($this->contentStorage->list($topicPrefix) as $key) {
                $relative = str_starts_with($key, $topicPrefix.'/') ? substr($key, strlen($topicPrefix) + 1) : '';
                if (!str_contains($relative, '/')) {
                    continue;
                }
                $difficultyDirectories[explode('/', $relative, 2)[0]] = true;
            }

            foreach (array_keys($difficultyDirectories) as $difficultyDirectory) {
                $difficultyDirectory = (string) $difficultyDirectory;
                if (!ctype_digit($difficultyDirectory) || !array_key_exists((int) $difficultyDirectory, $this->rules->difficulties())) {
                    $errors[] = sprintf(
                        'Invalid difficulty directory: %s',
                        $this->contentStorage->location($this->join($topicPrefix, $difficultyDirectory)),
                    );
                    continue;
                }
                foreach ($this->listQuestions($locale, $topic, (int) $difficultyDirectory) as $question) {
                    $payload = $this->readQuestion($locale, $topic, (int) $difficultyDirectory, $question['id']);
                    foreach ($this->validateQuestionPayload($payload, (int) $difficultyDirectory) as $error) {
                        $errors[] = sprintf('%s/%s/%s/%s/%s.json: %s', $this->selectedTheme(), $locale, $topic, $difficultyDirectory, $question['id'], $error);
                    }
                }
            }
        }
        return $errors;
    }

    /** @return list<string> */
    private function validateLocaleParity(): array
    {
        $errors = [];
        $fallback = $this->questionPackageSet($this->fallbackLocale);
        foreach ($this->supportedLocales as $locale) {
            if ($locale === $this->fallbackLocale) {
                continue;
            }
            $localized = $this->questionPackageSet($locale);
            foreach (array_keys($fallback) as $package) {
                if (!isset($localized[$package])) {
                    $errors[] = sprintf('Locale %s is missing fallback question package %s.', $locale, $package);
                }
            }
            foreach (array_keys($localized) as $package) {
                if (!isset($fallback[$package])) {
                    $errors[] = sprintf('Locale %s has question package not defined by fallback: %s.', $locale, $package);
                }
            }
        }
        return $errors;
    }

    /** @return array<string,true> */
    private function questionPackageSet(string $locale): array
    {
        $packages = [];
        foreach ($this->activeTopicKeys() as $topic) {
            foreach (array_keys($this->rules->difficulties()) as $difficulty) {
                foreach ($this->listQuestions($locale, $topic, (int) $difficulty) as $question) {
                    $packages[sprintf('%s/%d/%s', $topic, $difficulty, $question['id'])] = true;
                }
            }
        }
        return $packages;
    }

    /** @param array<string,mixed> $question @return list<string> */
    private function validateQuestionPayload(array $question, int $difficulty): array
    {
        return $this->rules->questionPayloadErrors($question, $difficulty);
    }

    /** @param array<string,mixed> $input @return array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} */
    private function validatedQuestionPayload(array $input, int $difficulty): array
    {
        return $this->rules->questionPayload($input, $difficulty);
    }

    /** @param array<string,mixed> $input @return array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} */
    private function normalizeQuestionPayload(array $input): array
    {
        return $this->rules->normalizeQuestionPayload($input);
    }

    private function assertQuestionPackageAllowed(string $locale, string $topic, int $difficulty, string $questionId, bool $allowMissingFallback = true): void
    {
        $this->assertSupportedLocale($locale);
        $this->assertCentralTopicExists($topic);
        $this->assertDifficulty($difficulty);
        $this->assertSafeIdentifier($questionId, 'question ID');

        if ($locale === $this->fallbackLocale) {
            return;
        }

        $fallbackPath = $this->questionPath($this->fallbackLocale, $topic, $difficulty, $questionId);
        if (!$allowMissingFallback || !$this->contentStorage->exists($fallbackPath)) {
            if (!$this->contentStorage->exists($fallbackPath)) {
                throw new RuntimeException('Translated locales must replicate canonical fallback question packages.');
            }
        }
    }

    private function assertQuestionIdAvailableInAllLocales(string $topic, int $difficulty, string $questionId): void
    {
        foreach ($this->supportedLocales as $locale) {
            $path = $this->questionPath($locale, $topic, $difficulty, $questionId);
            if ($this->contentStorage->exists($path)) {
                throw new RuntimeException(sprintf('Question path already exists in locale %s.', $locale));
            }
        }
    }

    private function assertExistingQuestionSet(string $topic, int $difficulty, string $questionId): void
    {
        $this->assertCentralTopicExists($topic);
        $this->assertDifficulty($difficulty);
        $this->assertSafeIdentifier($questionId, 'question ID');

        foreach ($this->supportedLocales as $locale) {
            if (!$this->contentStorage->exists($this->questionPath($locale, $topic, $difficulty, $questionId))) {
                throw new RuntimeException(sprintf('Question package %s/%d/%s is missing in locale %s.', $topic, $difficulty, $questionId, $locale));
            }
        }
    }

    private function assertSupportedLocale(string $locale): void
    {
        $this->rules->assertSupportedLocale($locale);
    }

    private function assertCentralTopicExists(string $key): void
    {
        $key = $this->normalizeKey($key);
        foreach ($this->readCentralCatalog()['topics'] as $topic) {
            if ($this->normalizeKey((string) ($topic['key'] ?? '')) === $key) {
                return;
            }
        }
        throw new RuntimeException(sprintf('Topic "%s" is not defined in central catalog.', $key));
    }

    private function assertDifficulty(int $difficulty): void
    {
        $this->rules->assertDifficulty($difficulty);
    }

    private function assertSafeIdentifier(string $value, string $label): void
    {
        $this->rules->normalizeIdentifier($value, $label);
    }

    private function normalizeKey(string $key): string
    {
        return trim($key);
    }

    private function questionPath(string $locale, string $topic, int $difficulty, string $questionId): string
    {
        $this->assertSafeIdentifier($topic, 'topic key');
        $this->assertSafeIdentifier($questionId, 'question ID');
        return $this->join($this->themeRoot(), $locale, $topic, (string) $difficulty, $questionId.'.json');
    }

    private function themeRoot(): string
    {
        $theme = $this->selectedTheme();
        $this->assertSafeIdentifier($theme, 'theme ID');
        return $theme;
    }

    private function themeIndexPath(): string
    {
        return 'themes.json';
    }

    /** @return array{themes:list<array{id:string,name:string,description:string,weight:int,createdAt:string,active:bool}>} */
    private function readThemeIndex(): array
    {
        $path = $this->themeIndexPath();
        if (!$this->contentStorage->exists($path)) {
            return ['themes' => []];
        }

        $data = $this->readJson($path);
        $themes = $data['themes'] ?? [];
        if (!is_array($themes)) {
            return ['themes' => []];
        }

        return ['themes' => array_values(array_filter(array_map(function (mixed $theme): ?array {
            if (!is_array($theme)) {
                return null;
            }
            $createdAt = trim((string) ($theme['createdAt'] ?? $theme['created_at'] ?? ''));
            return [
                'id' => $this->normalizeKey((string) ($theme['id'] ?? '')),
                'name' => trim((string) ($theme['name'] ?? '')),
                'description' => trim((string) ($theme['description'] ?? '')),
                'weight' => (int) ($theme['weight'] ?? 0),
                'createdAt' => $createdAt,
                'active' => (bool) ($theme['active'] ?? false),
            ];
        }, $themes)))];
    }

    /** @param array{themes:list<array<string,mixed>>} $index */
    private function validateThemeIndex(array $index): void
    {
        $seen = [];
        foreach ($index['themes'] as $theme) {
            $id = $this->normalizeKey((string) ($theme['id'] ?? ''));
            if ($id === '') {
                throw new RuntimeException('Theme index contains a theme without ID.');
            }
            $this->assertSafeIdentifier($id, 'theme ID');
            if (isset($seen[$id])) {
                throw new RuntimeException(sprintf('Theme index has duplicate theme ID "%s".', $id));
            }
            $seen[$id] = true;
        }
    }

    /** @param list<array<string,mixed>> $themes @return list<array<string,mixed>> */
    private function sortThemes(array $themes): array
    {
        usort($themes, function (array $a, array $b): int {
            $weight = ((int) ($a['weight'] ?? 0)) <=> ((int) ($b['weight'] ?? 0));
            if ($weight !== 0) {
                return $weight;
            }
            return ((string) ($a['id'] ?? '')) <=> ((string) ($b['id'] ?? ''));
        });
        return $themes;
    }

    /** @return array<string,mixed> */
    private function readJson(string $key): array
    {
        $data = json_decode($this->contentStorage->read($key), true);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Invalid JSON object in %s.', $this->contentStorage->location($key)));
        }

        return $data;
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $key, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode JSON.');
        }

        $this->contentStorage->write($key, $json.PHP_EOL);
    }

    /** @param array<string,array<string,mixed>> $payloads */
    private function writeJsonSet(array $payloads): void
    {
        $this->writeJsonMutation($payloads, []);
    }

    /**
     * @param array<string,array<string,mixed>> $payloads
     * @param list<string> $deleteKeys
     */
    private function writeJsonMutation(array $payloads, array $deleteKeys): void
    {
        $writes = [];
        foreach ($payloads as $key => $data) {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                throw new RuntimeException('Could not encode JSON.');
            }
            $writes[$key] = $json.PHP_EOL;
        }

        $keys = array_values(array_unique(array_merge(array_keys($writes), $deleteKeys)));
        $previous = [];
        foreach ($keys as $key) {
            $previous[$key] = $this->contentStorage->exists($key)
                ? $this->contentStorage->read($key)
                : null;
        }

        $touched = [];
        try {
            foreach ($writes as $key => $contents) {
                $touched[] = $key;
                $this->contentStorage->write($key, $contents);
            }
            foreach ($deleteKeys as $key) {
                $touched[] = $key;
                if (!$this->contentStorage->delete($key)) {
                    throw new RuntimeException(sprintf('Could not delete %s.', $this->contentStorage->location($key)));
                }
            }
        } catch (RuntimeException $error) {
            foreach (array_reverse(array_values(array_unique($touched))) as $key) {
                try {
                    if (is_string($previous[$key])) {
                        $this->contentStorage->write($key, $previous[$key]);
                    } else {
                        $this->contentStorage->delete($key);
                    }
                } catch (RuntimeException) {
                }
            }
            throw $error;
        }
    }

    private function join(string ...$parts): string
    {
        return implode('/', array_map(
            static fn (string $part): string => trim($part, '/'),
            $parts,
        ));
    }
}
