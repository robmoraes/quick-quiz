<?php

namespace App\Service;

use App\Exception\AdminApiException;
use App\Exception\QuizPublicationException;
use App\Exception\QuizDatabaseException;
use PDOException;
use RuntimeException;

final class QuizAdministrationService
{
    public function __construct(private readonly QuizAuthoringService $packs)
    {
    }

    /** @return array<string,mixed> */
    public function catalog(string $locale = ''): array
    {
        $locale = $this->locale($locale);
        $themes = [];
        foreach ($this->packs->listThemes() as $theme) {
            $scoped = $this->packs->forTheme((string) $theme['id']);
            $themes[] = $theme + ['topics' => $this->topicList($scoped, $locale)];
        }

        return [
            'fallbackLocale' => $this->packs->fallbackLocale(),
            'supportedLocales' => $this->packs->supportedLocales(),
            'difficulties' => $this->packs->difficulties(),
            'themes' => $themes,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function themes(): array
    {
        return $this->packs->listThemes();
    }

    /** @return array<string,mixed> */
    public function theme(string $theme): array
    {
        return $this->requireTheme($theme);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createTheme(array $input): array
    {
        $id = trim((string) ($input['id'] ?? ''));
        if ($id !== '' && $this->packs->theme($id) !== null) {
            throw AdminApiException::conflict(sprintf('Theme "%s" already exists.', $id));
        }

        $input['active'] ??= true;
        $input['weight'] ??= 100;
        $input['createdAt'] ??= gmdate('c');
        try {
            $this->packs->saveTheme($input);
        } catch (RuntimeException $error) {
            if ($error instanceof QuizPublicationException || $error instanceof QuizDatabaseException || $error instanceof PDOException) {
                throw $error;
            }
            throw AdminApiException::validation($error->getMessage());
        }

        return [
            'theme' => $this->requireTheme($id),
            'publication' => $this->publication(),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function replaceTheme(string $theme, array $input): array
    {
        $this->requireTheme($theme);
        $input['id'] = $theme;
        try {
            $this->packs->saveTheme($input);
        } catch (RuntimeException $error) {
            if ($error instanceof QuizPublicationException || $error instanceof QuizDatabaseException || $error instanceof PDOException) {
                throw $error;
            }
            throw AdminApiException::validation($error->getMessage());
        }

        return [
            'theme' => $this->requireTheme($theme),
            'publication' => $this->publication(),
        ];
    }

    /** @return array<string,mixed> */
    public function deleteTheme(string $theme, bool $recursive): array
    {
        $this->requireTheme($theme);
        try {
            $result = $this->packs->deleteTheme($theme, $recursive);
        } catch (RuntimeException $error) {
            if ($error instanceof QuizPublicationException || $error instanceof QuizDatabaseException || $error instanceof PDOException) {
                throw $error;
            }
            if (str_contains($error->getMessage(), 'recursive deletion is required')) {
                throw AdminApiException::conflict($error->getMessage());
            }
            throw $error;
        }

        return $result + ['theme' => $theme, 'publication' => $this->publication()];
    }

    /** @return list<array<string,mixed>> */
    public function topics(string $theme, string $locale = ''): array
    {
        $this->requireTheme($theme);

        return $this->topicList($this->packs->forTheme($theme), $this->locale($locale));
    }

    /** @return array<string,mixed> */
    public function topic(string $theme, string $topic, string $locale = ''): array
    {
        $this->requireTheme($theme);
        $scoped = $this->packs->forTheme($theme);
        $central = $scoped->topic($topic);
        if ($central === null) {
            throw AdminApiException::notFound(sprintf('Topic "%s" was not found in theme "%s".', $topic, $theme));
        }

        return $this->topicView($scoped, $central, $this->locale($locale));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createTopic(string $theme, array $input): array
    {
        $this->requireTheme($theme);
        $scoped = $this->packs->forTheme($theme);
        $key = trim((string) ($input['key'] ?? ''));
        if ($key !== '' && $scoped->topic($key) !== null) {
            throw AdminApiException::conflict(sprintf('Topic "%s" already exists in theme "%s".', $key, $theme));
        }

        $input['active'] ??= true;
        $input['weight'] ??= 100;
        $input['created_at'] ??= gmdate('c');
        $localizations = $this->localizations($input);
        try {
            $scoped->saveTopicSet($input, $localizations);
        } catch (RuntimeException $error) {
            if ($error instanceof QuizPublicationException || $error instanceof QuizDatabaseException || $error instanceof PDOException) {
                throw $error;
            }
            throw AdminApiException::validation($error->getMessage());
        }

        return [
            'topic' => $this->topic($theme, $key),
            'publication' => $this->publication(),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function replaceTopic(string $theme, string $topic, array $input): array
    {
        $this->topic($theme, $topic);
        $scoped = $this->packs->forTheme($theme);
        $input['key'] = $topic;
        try {
            $scoped->saveTopicSet($input, $this->localizations($input));
        } catch (RuntimeException $error) {
            if ($error instanceof QuizPublicationException || $error instanceof QuizDatabaseException || $error instanceof PDOException) {
                throw $error;
            }
            throw AdminApiException::validation($error->getMessage());
        }

        return [
            'topic' => $this->topic($theme, $topic),
            'publication' => $this->publication(),
        ];
    }

    /** @return array<string,mixed> */
    public function deleteTopic(string $theme, string $topic, bool $recursive): array
    {
        $this->topic($theme, $topic);
        try {
            $result = $this->packs->forTheme($theme)->deleteTopicPackage($topic, $recursive);
        } catch (RuntimeException $error) {
            if ($error instanceof QuizPublicationException || $error instanceof QuizDatabaseException || $error instanceof PDOException) {
                throw $error;
            }
            if (str_contains($error->getMessage(), 'recursive deletion is required')) {
                throw AdminApiException::conflict($error->getMessage());
            }
            throw $error;
        }

        return $result + [
            'theme' => $theme,
            'topic' => $topic,
            'publication' => $this->publication(),
        ];
    }

    /** @return array<string,mixed> */
    public function questions(string $theme, string $topic, ?int $difficulty, string $locale = ''): array
    {
        $this->topic($theme, $topic);
        $locale = $this->locale($locale);
        $scoped = $this->packs->forTheme($theme);
        $difficulties = $difficulty === null ? array_keys($scoped->difficulties()) : [$this->difficulty($difficulty)];
        $questions = [];
        foreach ($difficulties as $level) {
            foreach ($scoped->listQuestions($locale, $topic, (int) $level) as $question) {
                unset($question['path']);
                $questions[] = ['difficulty' => (int) $level] + $question;
            }
        }

        return [
            'theme' => $theme,
            'topic' => $topic,
            'locale' => $locale,
            'questions' => $questions,
        ];
    }

    /** @return array<string,mixed> */
    public function question(string $theme, string $topic, int $difficulty, string $questionId): array
    {
        $this->topic($theme, $topic);
        $difficulty = $this->difficulty($difficulty);
        try {
            $translations = $this->packs->forTheme($theme)
                ->readLocalizedQuestionSet($topic, $difficulty, $questionId);
        } catch (RuntimeException $error) {
            if ($error instanceof QuizPublicationException || $error instanceof QuizDatabaseException || $error instanceof PDOException) {
                throw $error;
            }
            if (str_contains($error->getMessage(), ' is missing in locale ')) {
                throw AdminApiException::notFound($error->getMessage());
            }
            throw $error;
        }

        return [
            'theme' => $theme,
            'topic' => $topic,
            'difficulty' => $difficulty,
            'id' => $questionId,
            'translations' => $translations,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createQuestions(string $theme, string $topic, array $input): array
    {
        $this->topic($theme, $topic);
        $difficulty = $this->difficulty((int) ($input['difficulty'] ?? 0));
        $questions = $input['questions'] ?? null;
        if (!is_array($questions) || !array_is_list($questions)) {
            throw AdminApiException::badRequest('invalid_request', 'questions must be a JSON array.');
        }

        try {
            $ids = $this->packs->forTheme($theme)
                ->createLocalizedQuestionSets($topic, $difficulty, $questions);
        } catch (RuntimeException $error) {
            if ($error instanceof QuizPublicationException || $error instanceof QuizDatabaseException || $error instanceof PDOException) {
                throw $error;
            }
            if (str_contains($error->getMessage(), 'already exists')) {
                throw AdminApiException::conflict($error->getMessage());
            }
            throw AdminApiException::validation($error->getMessage());
        }

        return [
            'theme' => $theme,
            'topic' => $topic,
            'difficulty' => $difficulty,
            'questionIds' => $ids,
            'publication' => $this->publication(),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function replaceQuestion(string $theme, string $topic, int $difficulty, string $questionId, array $input): array
    {
        $this->question($theme, $topic, $difficulty, $questionId);
        $translations = $input['translations'] ?? null;
        if (!is_array($translations)) {
            throw AdminApiException::badRequest('invalid_request', 'translations must be a JSON object.');
        }

        $scoped = $this->packs->forTheme($theme);
        $this->assertExactLocales($scoped, $translations);
        try {
            $scoped->updateManualLocalizedQuestionSet($topic, $difficulty, $questionId, $translations);
        } catch (RuntimeException $error) {
            if ($error instanceof QuizPublicationException || $error instanceof QuizDatabaseException || $error instanceof PDOException) {
                throw $error;
            }
            throw AdminApiException::validation($error->getMessage());
        }

        return $this->question($theme, $topic, $difficulty, $questionId) + [
            'publication' => $this->publication(),
        ];
    }

    /** @return array<string,mixed> */
    public function deleteQuestion(string $theme, string $topic, int $difficulty, string $questionId): array
    {
        $this->question($theme, $topic, $difficulty, $questionId);
        $result = $this->packs->forTheme($theme)->deleteLocalizedQuestionSet($topic, $difficulty, $questionId);

        return $result + [
            'theme' => $theme,
            'topic' => $topic,
            'difficulty' => $difficulty,
            'id' => $questionId,
            'publication' => $this->publication(),
        ];
    }

    /** @return array<string,mixed> */
    public function publicationStatus(): array
    {
        return $this->packs->publicationStatus();
    }

    /** @return array<string,mixed> */
    public function retryPublication(): array
    {
        return $this->packs->retryPublication();
    }

    private function locale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return $this->packs->fallbackLocale();
        }
        if (!in_array($locale, $this->packs->supportedLocales(), true)) {
            throw AdminApiException::badRequest('invalid_locale', sprintf('Unsupported locale "%s".', $locale));
        }

        return $locale;
    }

    private function difficulty(int $difficulty): int
    {
        if (!array_key_exists($difficulty, $this->packs->difficulties())) {
            throw AdminApiException::badRequest('invalid_difficulty', 'difficulty must be an integer from 1 to 4.');
        }

        return $difficulty;
    }

    /** @return array<string,mixed> */
    private function requireTheme(string $theme): array
    {
        $found = $this->packs->theme($theme);
        if ($found === null) {
            throw AdminApiException::notFound(sprintf('Theme "%s" was not found.', $theme));
        }

        return $found;
    }

    /** @return list<array<string,mixed>> */
    private function topicList(QuizAuthoringService $scoped, string $locale): array
    {
        return $scoped->topicViews($locale);
    }

    /** @param array<string,mixed> $topic @return array<string,mixed> */
    private function topicView(QuizAuthoringService $scoped, array $topic, string $locale): array
    {
        $key = (string) $topic['key'];
        $localized = $scoped->localizedTopic($locale, $key);
        $questionCounts = [];
        foreach (array_keys($scoped->difficulties()) as $difficulty) {
            $questionCounts[(string) $difficulty] = count(
                $scoped->listQuestions($scoped->fallbackLocale(), $key, (int) $difficulty),
            );
        }

        return $topic + [
            'displayLocale' => $locale,
            'displayName' => trim((string) ($localized['name'] ?? '')) ?: (string) ($topic['name'] ?? ''),
            'displayDescription' => trim((string) ($localized['description'] ?? '')) ?: (string) ($topic['description'] ?? ''),
            'questionCounts' => $questionCounts,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,array<string,mixed>> */
    private function localizations(array $input): array
    {
        $localizations = $input['localizations'] ?? [];
        if (!is_array($localizations)) {
            throw AdminApiException::badRequest('invalid_request', 'localizations must be a JSON object.');
        }

        return $localizations;
    }

    /** @param array<string,mixed> $translations */
    private function assertExactLocales(QuizAuthoringService $scoped, array $translations): void
    {
        $provided = array_map('strval', array_keys($translations));
        $expected = $scoped->supportedLocales();
        sort($provided);
        sort($expected);
        if ($provided !== $expected) {
            throw AdminApiException::validation(sprintf(
                'translations must contain exactly: %s.',
                implode(', ', $expected),
            ));
        }
    }

    /** @return array{apiReloadRequired:bool,reason:string} */
    private function publication(): array
    {
        return $this->packs->publicationInfo();
    }
}
