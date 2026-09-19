<?php

namespace App\Service;

use App\Repository\QuizContentRepository;
use App\Repository\QuizContentWriter;
use RuntimeException;

final class PostgresQuizAuthoringService implements QuizAuthoringService
{
    public function __construct(
        private readonly QuizContentRepository $repository,
        private readonly QuizContentWriter $writer,
        private readonly QuizContentStatistics $statistics,
        private readonly QuizContentRules $rules,
        private readonly QuizPublicationService $publication,
        private readonly ?ThemeContext $themeContext = null,
        private readonly ?string $fixedTheme = null,
    ) {
    }

    public function publicationInfo(): array
    {
        $status = $this->publication->status();
        return [
            'revision' => $status['currentRevision'],
            'status' => $status['status'],
            'checksum' => $status['checksum'],
            'objectCount' => $status['objectCount'],
            'apiReloadRequired' => $status['status'] === 'published',
            'reason' => 'The Quiz API loads quiz content during startup.',
        ];
    }

    public function publicationStatus(): array
    {
        return ['provider' => 'postgres'] + $this->publication->status();
    }

    public function retryPublication(): array
    {
        return $this->publication->publishPending();
    }

    public function contentRoot(): string
    {
        return 'PostgreSQL quiz catalog';
    }

    public function forTheme(string $theme): self
    {
        $theme = $this->rules->normalizeIdentifier($theme, 'theme ID');
        return new self($this->repository, $this->writer, $this->statistics, $this->rules, $this->publication,
            fixedTheme: $theme);
    }

    public function selectedTheme(): string
    {
        if ($this->fixedTheme !== null) {
            return $this->fixedTheme;
        }
        if ($this->themeContext !== null) {
            return $this->themeContext->requireSelectedTheme();
        }
        throw new RuntimeException('Select a theme before managing content.');
    }

    public function listThemes(): array
    {
        return $this->repository->themes();
    }

    public function theme(string $id): ?array
    {
        return $this->repository->theme($id);
    }

    public function selectedThemeMetadata(): ?array
    {
        return $this->theme($this->selectedTheme());
    }

    public function saveTheme(array $input): void
    {
        $id = $this->rules->normalizeIdentifier((string) ($input['id'] ?? ''), 'theme ID');
        $this->publication->mutate(
            fn (): int => $this->writer->saveTheme($input),
            fn (): array => ['keys' => array_merge(['themes.json', $id.'/index.json'], $this->localeIndexKeys($id))],
        );
    }

    public function deleteTheme(string $id, bool $recursive = false): array
    {
        $id = $this->rules->normalizeIdentifier($id, 'theme ID');
        $result = $this->publication->mutate(
            fn (): array => $this->writer->deleteTheme($id, $recursive),
            fn (): array => ['keys' => ['themes.json'], 'deletePrefixes' => [$id.'/']],
        );
        return $result['result'];
    }

    public function fallbackLocale(): string
    {
        return $this->rules->fallbackLocale();
    }

    public function supportedLocales(): array
    {
        return $this->rules->supportedLocales();
    }

    public function difficulties(): array
    {
        return $this->rules->difficulties();
    }

    public function readCentralCatalog(): array
    {
        $topics = array_map(static function (array $topic): array {
            unset($topic['questionCount']);
            return $topic;
        }, $this->listTopics());
        return ['topics' => $topics];
    }

    public function readLocalizedCatalog(string $locale): array
    {
        $this->rules->assertSupportedLocale($locale);
        return ['topics' => $this->repository->localizedTopics($this->selectedTheme(), $locale)];
    }

    public function listTopics(): array
    {
        return array_map(static function (array $topic): array {
            unset($topic['localizedName'], $topic['localizedDescription']);
            return $topic;
        }, $this->repository->topics($this->selectedTheme(), $this->fallbackLocale(), $this->fallbackLocale()));
    }

    public function topicViews(string $locale): array
    {
        $this->rules->assertSupportedLocale($locale);
        $theme = $this->selectedTheme();
        $topics = $this->repository->topics($theme, $locale, $this->fallbackLocale());
        $counts = $this->repository->topicDifficultyCounts($theme, $this->fallbackLocale());
        return array_map(function (array $topic) use ($locale, $counts): array {
            $key = $topic['key'];
            $name = trim($topic['localizedName']) ?: $topic['name'];
            $description = trim($topic['localizedDescription']) ?: $topic['description'];
            unset($topic['localizedName'], $topic['localizedDescription']);
            $difficultyCounts = [];
            foreach (array_keys($this->difficulties()) as $difficulty) {
                $difficultyCounts[(string) $difficulty] = $counts[$key][$difficulty] ?? 0;
            }
            return $topic + [
                'displayLocale' => $locale,
                'displayName' => $name,
                'displayDescription' => $description,
                'questionCounts' => $difficultyCounts,
            ];
        }, $topics);
    }

    public function topic(string $key): ?array
    {
        foreach ($this->listTopics() as $topic) {
            if ($topic['key'] === trim($key)) {
                return $topic;
            }
        }
        return null;
    }

    public function saveTopic(array $input): void
    {
        $this->saveTopicSet($input);
    }

    public function saveTopicSet(array $input, array $localizations = []): void
    {
        $theme = $this->selectedTheme();
        $this->publication->mutate(
            fn (): int => $this->writer->saveTopicSet($theme, $input, $localizations),
            fn (): array => ['keys' => array_merge([$theme.'/index.json'], $this->localeIndexKeys($theme))],
        );
    }

    public function deleteTopic(string $key): void
    {
        $this->deleteTopicPackage($key);
    }

    public function saveLocalizedTopic(string $locale, array $input): void
    {
        $this->rules->assertSupportedLocale($locale);
        $key = (string) ($input['key'] ?? '');
        $topic = $this->topic($key);
        if ($topic === null) {
            throw new RuntimeException(sprintf('Topic "%s" is not defined in central catalog.', $key));
        }
        $theme = $this->selectedTheme();
        $this->publication->mutate(
            fn (): int => $this->writer->saveTopicSet($theme, $topic, [$locale => $input]),
            fn (): array => ['keys' => [$theme.'/'.$locale.'/index.json']],
        );
    }

    public function localizedTopic(string $locale, string $key): ?array
    {
        $this->rules->assertSupportedLocale($locale);
        foreach ($this->repository->localizedTopics($this->selectedTheme(), $locale) as $localized) {
            if ($localized['key'] === trim($key)) {
                return $localized;
            }
        }
        return null;
    }

    public function deleteTopicPackage(string $key, bool $recursive = false): array
    {
        $key = $this->rules->normalizeIdentifier($key, 'topic key');
        $theme = $this->selectedTheme();
        $prefixes = array_map(static fn (string $locale): string => $theme.'/'.$locale.'/'.$key.'/', $this->supportedLocales());
        $result = $this->publication->mutate(
            fn (): array => $this->writer->deleteTopicPackage($theme, $key, $recursive),
            fn (): array => [
                'keys' => array_merge([$theme.'/index.json'], $this->localeIndexKeys($theme)),
                'deletePrefixes' => $prefixes,
            ],
        );
        return $result['result'];
    }

    public function listQuestions(string $locale, string $topic, int $difficulty): array
    {
        $this->rules->assertSupportedLocale($locale);
        $this->rules->assertDifficulty($difficulty);
        return array_map(static function (array $question): array {
            unset($question['difficulty']);
            return $question;
        }, $this->repository->questions($this->selectedTheme(), $topic, $locale, $difficulty));
    }

    public function contentStats(): array
    {
        return $this->statistics->forTheme($this->selectedTheme());
    }

    public function readQuestion(string $locale, string $topic, int $difficulty, string $questionId): array
    {
        $this->rules->assertSupportedLocale($locale);
        $this->rules->assertDifficulty($difficulty);
        return $this->repository->localizedQuestionSet($this->selectedTheme(), $topic, $difficulty, $questionId)[$locale]
            ?? ['prompt' => '', 'correctOptions' => [], 'wrongOptions' => []];
    }

    public function nextQuestionId(string $topic, int $difficulty): string
    {
        if ($this->topic($topic) === null) {
            throw new RuntimeException(sprintf('Topic "%s" is not defined in central catalog.', $topic));
        }
        return $this->rules->nextQuestionId($topic, $difficulty,
            $this->repository->questionIds($this->selectedTheme(), $topic, $difficulty));
    }

    public function createReplicatedQuestionSet(string $sourceLocale, string $topic, int $difficulty, string $questionId, array $input): string
    {
        $this->rules->assertSupportedLocale($sourceLocale);
        $payload = $this->rules->questionPayload($input, $difficulty);
        $translations = array_fill_keys($this->supportedLocales(), $payload);
        $ids = $this->createLocalizedQuestionSets($topic, $difficulty, [[
            'id' => $questionId, 'translations' => $translations,
        ]]);
        return $ids[0];
    }

    public function readLocalizedQuestionSet(string $topic, int $difficulty, string $questionId): array
    {
        $questions = $this->repository->localizedQuestionSet($this->selectedTheme(), $topic, $difficulty, $questionId);
        foreach ($this->supportedLocales() as $locale) {
            if (!isset($questions[$locale])) {
                throw new RuntimeException(sprintf('Question "%s" is missing in locale %s.', $questionId, $locale));
            }
        }
        return $questions;
    }

    public function updateManualLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $localizedInputs): void
    {
        $topic = $this->rules->normalizeIdentifier($topic, 'topic key');
        $questionId = $this->rules->normalizeIdentifier($questionId, 'question ID');
        $theme = $this->selectedTheme();
        $this->publication->mutate(
            fn (): int => $this->writer->replaceLocalizedQuestionSet($theme, $topic, $difficulty, $questionId, $localizedInputs),
            fn (): array => ['keys' => $this->questionKeys($theme, $topic, $difficulty, $questionId)],
        );
    }

    public function createLocalizedQuestionSets(string $topic, int $difficulty, array $items): array
    {
        $topic = $this->rules->normalizeIdentifier($topic, 'topic key');
        $theme = $this->selectedTheme();
        $result = $this->publication->mutate(
            fn (): array => $this->writer->createLocalizedQuestionSets($theme, $topic, $difficulty, $items),
            fn (array $written): array => ['keys' => array_merge(...array_map(
                fn (string $id): array => $this->questionKeys($theme, $topic, $difficulty, $id),
                $written['questionIds'],
            ))],
        );
        return $result['result']['questionIds'];
    }

    public function prepareNewLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $input): array
    {
        $questionId = trim($questionId) ?: $this->nextQuestionId($topic, $difficulty);
        $this->rules->normalizeIdentifier($questionId, 'question ID');
        if (in_array($questionId, $this->repository->questionIds($this->selectedTheme(), $topic, $difficulty), true)) {
            throw new RuntimeException(sprintf('Question ID "%s" already exists.', $questionId));
        }
        return ['questionId' => $questionId, 'question' => $this->rules->questionPayload($input, $difficulty)];
    }

    public function saveLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $sourceQuestion, array $localizations, bool $copySourceAnswers = false): void
    {
        $source = $this->rules->questionPayload($sourceQuestion, $difficulty);
        $translations = $this->withSourceAnswers($source, $localizations, $copySourceAnswers);
        $this->createLocalizedQuestionSets($topic, $difficulty, [[
            'id' => $questionId, 'translations' => $translations,
        ]]);
    }

    public function updateAiLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $sourceQuestion, array $localizations, bool $copySourceAnswers = false): void
    {
        $source = $this->rules->questionPayload($sourceQuestion, $difficulty);
        $this->updateManualLocalizedQuestionSet($topic, $difficulty, $questionId,
            $this->withSourceAnswers($source, $localizations, $copySourceAnswers));
    }

    public function recommendationPrompts(string $locale, string $topic, int $difficulty): array
    {
        return array_slice(array_values(array_filter(array_column($this->listQuestions($locale, $topic, $difficulty), 'prompt'))), 0, 50);
    }

    public function recommendationTopicMetadata(string $locale, string $topic): array
    {
        $this->rules->assertSupportedLocale($locale);
        $central = $this->topic($topic);
        if ($central === null) {
            throw new RuntimeException(sprintf('Topic "%s" is not defined in central catalog.', $topic));
        }
        $localized = $this->localizedTopic($locale, $topic);
        return [
            'theme' => $this->selectedTheme(), 'key' => $topic,
            'name' => trim((string) ($localized['name'] ?? '')) ?: $central['name'],
            'description' => trim((string) ($localized['description'] ?? '')) ?: $central['description'],
        ];
    }

    public function validateRecommendedQuestionDraft(string $locale, string $topic, int $difficulty, array $draft): array
    {
        $question = $this->rules->questionPayload($draft, $difficulty);
        if (in_array($question['prompt'], $this->recommendationPrompts($locale, $topic, $difficulty), true)) {
            throw new RuntimeException('Recommended prompt duplicates an existing question.');
        }
        return $question;
    }

    public function validateRecommendedAnswerDraft(int $difficulty, string $prompt, array $draft): array
    {
        return $this->rules->questionPayload([
            'prompt' => $prompt,
            'correctOptions' => $draft['correctOptions'] ?? [],
            'wrongOptions' => $draft['wrongOptions'] ?? [],
        ], $difficulty);
    }

    public function deleteQuestion(string $topic, int $difficulty, string $questionId): array
    {
        return $this->deleteLocalizedQuestionSet($topic, $difficulty, $questionId);
    }

    public function deleteLocalizedQuestionSet(string $topic, int $difficulty, string $questionId): array
    {
        $topic = $this->rules->normalizeIdentifier($topic, 'topic key');
        $questionId = $this->rules->normalizeIdentifier($questionId, 'question ID');
        $theme = $this->selectedTheme();
        $result = $this->publication->mutate(
            fn (): array => $this->writer->deleteLocalizedQuestionSet($theme, $topic, $difficulty, $questionId),
            fn (): array => ['keys' => $this->questionKeys($theme, $topic, $difficulty, $questionId)],
        );
        return $result['result'];
    }

    public function validateAll(): array
    {
        $theme = $this->selectedTheme();
        $errors = $this->repository->localeParityIssues($theme, $this->fallbackLocale(), $this->supportedLocales());
        foreach ($this->listTopics() as $topic) {
            foreach ($this->supportedLocales() as $locale) {
                foreach ($this->difficulties() as $difficulty => $metadata) {
                    foreach ($this->listQuestions($locale, $topic['key'], $difficulty) as $question) {
                        if ($question['correctCount'] < 1 || $question['wrongCount'] < $metadata['wrongRequired']) {
                            $errors[] = sprintf('%s/%s/%s/%d/%s.json: invalid answer counts.',
                                $theme, $locale, $topic['key'], $difficulty, $question['id']);
                        }
                    }
                }
            }
        }
        return $errors;
    }

    public function activeTopicKeys(): array
    {
        $keys = array_column(array_filter($this->listTopics(), static fn (array $topic): bool => $topic['active']), 'key');
        sort($keys);
        return $keys;
    }

    public function topicKeys(): array
    {
        $keys = array_column($this->listTopics(), 'key');
        sort($keys);
        return $keys;
    }

    public function topicChoices(): array
    {
        $choices = array_map(static fn (array $topic): array => [
            'key' => $topic['key'], 'active' => $topic['active'],
        ], $this->listTopics());
        usort($choices, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);
        return $choices;
    }

    /** @return list<string> */
    private function localeIndexKeys(string $theme): array
    {
        return array_map(static fn (string $locale): string => $theme.'/'.$locale.'/index.json', $this->supportedLocales());
    }

    /** @return list<string> */
    private function questionKeys(string $theme, string $topic, int $difficulty, string $id): array
    {
        return array_map(static fn (string $locale): string => sprintf('%s/%s/%s/%d/%s.json',
            $theme, $locale, $topic, $difficulty, $id), $this->supportedLocales());
    }

    /** @param array<string,mixed> $source @param array<string,array<string,mixed>> $localizations @return array<string,array<string,mixed>> */
    private function withSourceAnswers(array $source, array $localizations, bool $copySourceAnswers): array
    {
        if ($copySourceAnswers) {
            foreach ($localizations as &$localized) {
                if (is_array($localized)) {
                    $localized['correctOptions'] = $source['correctOptions'];
                    $localized['wrongOptions'] = $source['wrongOptions'];
                }
            }
            unset($localized);
        }
        return $localizations;
    }
}
