<?php

namespace App\Service;

/** Persistence-independent contract used by the Manager UI and administration API. */
interface QuizAuthoringService
{
    public function contentRoot(): string;
    public function publicationInfo(): array;
    public function publicationStatus(): array;
    public function retryPublication(): array;
    public function forTheme(string $theme): self;
    public function selectedTheme(): string;
    public function listThemes(): array;
    public function theme(string $id): ?array;
    public function selectedThemeMetadata(): ?array;
    public function saveTheme(array $input): void;
    public function deleteTheme(string $id, bool $recursive = false): array;
    public function fallbackLocale(): string;
    public function supportedLocales(): array;
    public function difficulties(): array;
    public function readCentralCatalog(): array;
    public function readLocalizedCatalog(string $locale): array;
    public function listTopics(): array;
    public function topicViews(string $locale): array;
    public function topic(string $key): ?array;
    /** @param array<string,mixed> $input @return array<string,mixed> Per-operation publication outcome. */
    public function saveTopic(array $input): array;
    /**
     * @param array<string,mixed> $input
     * @param array<string,array<string,mixed>> $localizations
     * @return array<string,mixed> Per-operation publication outcome.
     */
    public function saveTopicSet(array $input, array $localizations = []): array;
    public function deleteTopic(string $key): void;
    public function saveLocalizedTopic(string $locale, array $input): void;
    public function localizedTopic(string $locale, string $key): ?array;
    public function deleteTopicPackage(string $key, bool $recursive = false): array;
    public function listQuestions(string $locale, string $topic, int $difficulty): array;
    public function contentStats(): array;
    public function readQuestion(string $locale, string $topic, int $difficulty, string $questionId): array;
    public function nextQuestionId(string $topic, int $difficulty): string;
    public function createReplicatedQuestionSet(string $sourceLocale, string $topic, int $difficulty, string $questionId, array $input): string;
    public function readLocalizedQuestionSet(string $topic, int $difficulty, string $questionId): array;
    public function updateManualLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $localizedInputs): void;
    public function createLocalizedQuestionSets(string $topic, int $difficulty, array $items): array;
    public function prepareNewLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $input): array;
    public function saveLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $sourceQuestion, array $localizations, bool $copySourceAnswers = false): void;
    public function updateAiLocalizedQuestionSet(string $topic, int $difficulty, string $questionId, array $sourceQuestion, array $localizations, bool $copySourceAnswers = false): void;
    public function recommendationPrompts(string $locale, string $topic, int $difficulty): array;
    public function recommendationTopicMetadata(string $locale, string $topic): array;
    public function validateRecommendedQuestionDraft(string $locale, string $topic, int $difficulty, array $draft): array;
    public function validateRecommendedAnswerDraft(int $difficulty, string $prompt, array $draft): array;
    public function deleteQuestion(string $topic, int $difficulty, string $questionId): array;
    public function deleteLocalizedQuestionSet(string $topic, int $difficulty, string $questionId): array;
    public function validateAll(): array;
    public function activeTopicKeys(): array;
    public function topicKeys(): array;
    public function topicChoices(): array;
}
