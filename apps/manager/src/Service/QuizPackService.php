<?php

namespace App\Service;

use RuntimeException;

final class QuizPackService
{
    public const RECOMMENDATION_PROMPT_LIMIT = 50;

    private const DIFFICULTIES = [
        1 => ['label' => 'easy', 'optionCount' => 3, 'wrongRequired' => 2],
        2 => ['label' => 'normal', 'optionCount' => 5, 'wrongRequired' => 4],
        3 => ['label' => 'hard', 'optionCount' => 7, 'wrongRequired' => 6],
        4 => ['label' => 'hardcore', 'optionCount' => 7, 'wrongRequired' => 6],
    ];

    /** @var list<string> */
    private array $supportedLocales;

    public function __construct(
        private readonly string $contentRoot,
        private readonly string $fallbackLocale,
        string $supportedLocales,
        private readonly ?ThemeContext $themeContext = null,
        private readonly ?string $fixedTheme = null,
    ) {
        $this->supportedLocales = $this->parseSupportedLocales($supportedLocales, $fallbackLocale);
    }

    public function contentRoot(): string
    {
        return $this->contentRoot;
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
        return self::DIFFICULTIES;
    }

    /** @return array{topics:list<array<string,mixed>>} */
    public function readCentralCatalog(): array
    {
        $path = $this->join($this->themeRoot(), 'index.json');
        if (!is_file($path)) {
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
        if (!is_file($path)) {
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

    /** @param array<string,mixed> $input */
    public function saveTopic(array $input): void
    {
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

    /** @return list<array{id:string,path:string,prompt:string,correctCount:int,wrongCount:int}> */
    public function listQuestions(string $locale, string $topic, int $difficulty): array
    {
        $this->assertSupportedLocale($locale);
        $this->assertDifficulty($difficulty);
        $topic = $this->normalizeKey($topic);
        $dir = $this->join($this->themeRoot(), $locale, $topic, (string) $difficulty);
        if (!is_dir($dir)) {
            return [];
        }

        $questions = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.json')) {
                continue;
            }
            $path = $this->join($dir, $entry);
            if (!is_file($path)) {
                continue;
            }
            $question = $this->readJson($path);
            $questions[] = [
                'id' => basename($entry, '.json'),
                'path' => $path,
                'prompt' => (string) ($question['prompt'] ?? ''),
                'correctCount' => count($question['correctOptions'] ?? []),
                'wrongCount' => count($question['wrongOptions'] ?? []),
            ];
        }

        usort($questions, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        return $questions;
    }

    /** @return array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} */
    public function readQuestion(string $locale, string $topic, int $difficulty, string $questionId): array
    {
        $path = $this->questionPath($locale, $topic, $difficulty, $questionId);
        if (!is_file($path)) {
            return ['prompt' => '', 'correctOptions' => [], 'wrongOptions' => []];
        }

        $question = $this->readJson($path);
        return $this->normalizeQuestionPayload($question);
    }

    public function nextQuestionId(string $topic, int $difficulty): string
    {
        $this->assertCentralTopicExists($topic);
        $this->assertDifficulty($difficulty);

        $topic = $this->normalizeKey($topic);
        $highest = 0;
        foreach ($this->listQuestions($this->fallbackLocale, $topic, $difficulty) as $question) {
            $id = $question['id'];
            $prefix = sprintf('%s-%d-', $topic, $difficulty);
            if (!str_starts_with($id, $prefix)) {
                continue;
            }
            $suffix = substr($id, strlen($prefix));
            if (ctype_digit($suffix)) {
                $highest = max($highest, (int) $suffix);
            }
        }

        return sprintf('%s-%d-%03d', $topic, $difficulty, $highest + 1);
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
    public function saveLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $sourceQuestion, array $localizations): void
    {
        $this->assertCentralTopicExists($topic);
        $this->assertDifficulty($difficulty);
        $this->assertSafeIdentifier($questionId, 'question ID');
        $this->assertQuestionIdAvailableInAllLocales($topic, $difficulty, $questionId);

        $expectedLocales = $this->supportedLocales;
        $missing = array_values(array_diff($expectedLocales, array_keys($localizations)));
        if ($missing !== []) {
            throw new RuntimeException(sprintf('Missing localizations for locale(s): %s.', implode(', ', $missing)));
        }

        $payloads = [];
        foreach ($expectedLocales as $locale) {
            $payload = $this->validatedQuestionPayload($localizations[$locale] ?? [], $difficulty);
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

    public function deleteQuestion(string $locale, string $topic, int $difficulty, string $questionId): void
    {
        $this->assertQuestionPackageAllowed($locale, $topic, $difficulty, $questionId, allowMissingFallback: false);
        $path = $this->questionPath($locale, $topic, $difficulty, $questionId);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Could not delete question file.');
        }
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

    /** @return list<string> */
    private function parseSupportedLocales(string $supportedLocales, string $fallbackLocale): array
    {
        $locales = [$fallbackLocale];
        foreach (explode(',', $supportedLocales) as $locale) {
            $locale = trim($locale);
            if ($locale !== '') {
                $locales[] = $locale;
            }
        }
        return array_values(array_unique($locales));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function topicPayload(array $input, string $key): array
    {
        return [
            'key' => $key,
            'name' => trim((string) ($input['name'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'weight' => (int) ($input['weight'] ?? 0),
            'created_at' => trim((string) ($input['created_at'] ?? '')),
            'active' => filter_var($input['active'] ?? false, FILTER_VALIDATE_BOOL),
        ];
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
            foreach (array_keys(self::DIFFICULTIES) as $difficulty) {
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
    private function validateLocalizedCatalog(string $locale, array $catalog, bool $throw = true): array
    {
        $errors = [];
        $centralKeys = array_flip(array_map(
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
            $topicDir = $this->join($this->themeRoot(), $locale, $topic);
            if (!is_dir($topicDir)) {
                continue;
            }
            foreach (scandir($topicDir) ?: [] as $difficultyDir) {
                if ($difficultyDir === '.' || $difficultyDir === '..') {
                    continue;
                }
                $difficultyPath = $this->join($topicDir, $difficultyDir);
                if (!is_dir($difficultyPath)) {
                    continue;
                }
                if (!ctype_digit($difficultyDir) || !isset(self::DIFFICULTIES[(int) $difficultyDir])) {
                    $errors[] = sprintf('Invalid difficulty directory: %s', $difficultyPath);
                    continue;
                }
                foreach ($this->listQuestions($locale, $topic, (int) $difficultyDir) as $question) {
                    $payload = $this->readQuestion($locale, $topic, (int) $difficultyDir, $question['id']);
                    foreach ($this->validateQuestionPayload($payload, (int) $difficultyDir) as $error) {
                        $errors[] = sprintf('%s/%s/%s/%s/%s.json: %s', $this->selectedTheme(), $locale, $topic, $difficultyDir, $question['id'], $error);
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
            foreach (array_keys(self::DIFFICULTIES) as $difficulty) {
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
        $errors = [];
        if (trim($question['prompt'] ?? '') === '') {
            $errors[] = 'prompt is required.';
        }
        if (($question['correctOptions'] ?? []) === []) {
            $errors[] = 'correctOptions must contain at least one option.';
        }
        $wrongRequired = self::DIFFICULTIES[$difficulty]['wrongRequired'];
        if (count($question['wrongOptions'] ?? []) < $wrongRequired) {
            $errors[] = sprintf('wrongOptions must contain at least %d options for difficulty %d.', $wrongRequired, $difficulty);
        }
        return $errors;
    }

    /** @param array<string,mixed> $input @return array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} */
    private function validatedQuestionPayload(array $input, int $difficulty): array
    {
        $question = $this->normalizeQuestionPayload($input);
        $errors = $this->validateQuestionPayload($question, $difficulty);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
        return $question;
    }

    /** @param array<string,mixed> $input @return array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} */
    private function normalizeQuestionPayload(array $input): array
    {
        return [
            'prompt' => trim((string) ($input['prompt'] ?? '')),
            'correctOptions' => $this->normalizeOptions($input['correctOptions'] ?? []),
            'wrongOptions' => $this->normalizeOptions($input['wrongOptions'] ?? []),
        ];
    }

    /** @param mixed $options @return list<string> */
    private function normalizeOptions(mixed $options): array
    {
        if (is_string($options)) {
            $options = preg_split('/\R/', $options) ?: [];
        }
        if (!is_array($options)) {
            return [];
        }
        $normalized = [];
        foreach ($options as $option) {
            $option = trim((string) $option);
            if ($option !== '') {
                $normalized[] = $option;
            }
        }
        return array_values(array_unique($normalized));
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
        if (!$allowMissingFallback || !is_file($fallbackPath)) {
            if (!is_file($fallbackPath)) {
                throw new RuntimeException('Translated locales must replicate canonical fallback question packages.');
            }
        }
    }

    private function assertQuestionIdAvailableInAllLocales(string $topic, int $difficulty, string $questionId): void
    {
        foreach ($this->supportedLocales as $locale) {
            $path = $this->questionPath($locale, $topic, $difficulty, $questionId);
            if (is_file($path)) {
                throw new RuntimeException(sprintf('Question path already exists in locale %s.', $locale));
            }
        }
    }

    private function assertSupportedLocale(string $locale): void
    {
        if (!in_array($locale, $this->supportedLocales, true)) {
            throw new RuntimeException(sprintf('Unsupported locale "%s".', $locale));
        }
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
        if (!isset(self::DIFFICULTIES[$difficulty])) {
            throw new RuntimeException(sprintf('Invalid difficulty "%d".', $difficulty));
        }
    }

    private function assertSafeIdentifier(string $value, string $label): void
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $value)) {
            throw new RuntimeException(sprintf('Invalid %s.', $label));
        }
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
        return $this->join($this->contentRoot, $theme);
    }

    private function themeIndexPath(): string
    {
        return $this->join($this->contentRoot, 'themes.json');
    }

    /** @return array{themes:list<array{id:string,name:string,description:string,weight:int,createdAt:string,active:bool}>} */
    private function readThemeIndex(): array
    {
        $path = $this->themeIndexPath();
        if (!is_file($path)) {
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

    /** @param array<string,mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create directory %s.', $dir));
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode JSON.');
        }

        $tmp = $path.'.tmp';
        if (file_put_contents($tmp, $json.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Could not write %s.', $tmp));
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Could not replace %s.', $path));
        }
    }

    /** @param array<string,array<string,mixed>> $payloads */
    private function writeJsonSet(array $payloads): void
    {
        $tmpPaths = [];
        $writtenPaths = [];

        try {
            foreach ($payloads as $path => $data) {
                $dir = dirname($path);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new RuntimeException(sprintf('Could not create directory %s.', $dir));
                }

                $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($json)) {
                    throw new RuntimeException('Could not encode JSON.');
                }

                $tmp = $path.'.tmp';
                if (file_put_contents($tmp, $json.PHP_EOL, LOCK_EX) === false) {
                    throw new RuntimeException(sprintf('Could not write %s.', $tmp));
                }
                $tmpPaths[$path] = $tmp;
            }

            foreach ($tmpPaths as $path => $tmp) {
                if (!rename($tmp, $path)) {
                    throw new RuntimeException(sprintf('Could not replace %s.', $path));
                }
                $writtenPaths[] = $path;
            }
        } catch (RuntimeException $error) {
            foreach ($tmpPaths as $tmp) {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
            foreach ($writtenPaths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            throw $error;
        }
    }

    private function join(string ...$parts): string
    {
        return implode(DIRECTORY_SEPARATOR, array_map(
            fn (string $part): string => rtrim($part, DIRECTORY_SEPARATOR),
            $parts,
        ));
    }
}
