<?php

namespace App\Repository;

use App\Exception\QuizDatabaseException;
use App\Service\QuizContentRules;
use App\Service\TopicTags;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;

/** Transactional quiz authoring. Publication consumes the pending revision separately. */
final class QuizContentWriter
{
    public function __construct(private readonly ManagerDatabase $database, private readonly QuizContentRules $rules)
    {
    }

    /** @param array<string,mixed> $input */
    public function saveTheme(array $input): int
    {
        $id = $this->rules->normalizeIdentifier((string) ($input['id'] ?? ''), 'theme ID');
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Theme name is required.');
        }
        return $this->mutate($id, function (PDO $db) use ($id, $name, $input): void {
            $old = $this->one($db, 'SELECT created_at FROM quiz_themes WHERE id = :id', ['id' => $id]);
            $this->sql($db, 'INSERT INTO quiz_themes (id,name,description,weight,active,created_at,updated_at)
                VALUES (:id,:name,:description,:weight,:active,:created_at,:updated_at)
                ON CONFLICT (id) DO UPDATE SET name=EXCLUDED.name, description=EXCLUDED.description,
                    weight=EXCLUDED.weight, active=EXCLUDED.active, created_at=EXCLUDED.created_at,
                    updated_at=EXCLUDED.updated_at', [
                'id' => $id,
                'name' => $name,
                'description' => trim((string) ($input['description'] ?? '')),
                'weight' => (int) ($input['weight'] ?? 0),
                'active' => $this->bool($input['active'] ?? false),
                'created_at' => $this->createdAt($input['createdAt'] ?? null, $old['created_at'] ?? null),
                'updated_at' => $this->now(),
            ]);
        });
    }

    /** @return array{deletedObjects:int,revision:int} */
    public function deleteTheme(string $theme, bool $recursive = false): array
    {
        $theme = $this->rules->normalizeIdentifier($theme, 'theme ID');
        $deletedObjects = 0;
        $revision = $this->mutate($theme, function (PDO $db) use ($theme, $recursive, &$deletedObjects): void {
            $this->requireTheme($db, $theme);
            $topics = $this->count($db, 'SELECT COUNT(*) FROM quiz_topics WHERE theme_id=:theme', ['theme' => $theme]);
            if ($topics > 0 && !$recursive) {
                throw new RuntimeException(sprintf('Theme "%s" contains content; recursive deletion is required.', $theme));
            }
            $questions = $this->count($db, 'SELECT COUNT(*) FROM quiz_question_translations WHERE theme_id=:theme', ['theme' => $theme]);
            $indexes = $this->count($db, 'SELECT COUNT(DISTINCT locale) FROM quiz_topic_translations WHERE theme_id=:theme', ['theme' => $theme]);
            $deletedObjects = $questions + $indexes + ($topics > 0 ? 1 : 0);
            $this->sql($db, 'DELETE FROM quiz_themes WHERE id=:theme', ['theme' => $theme]);
        });
        return ['deletedObjects' => $deletedObjects, 'revision' => $revision];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,array<string,mixed>> $localizations
     * @return array{revision:?int,publicationRequired:bool}
     */
    public function saveTopicSet(string $theme, array $input, array $localizations = []): array
    {
        $theme = $this->rules->normalizeIdentifier($theme, 'theme ID');
        $key = $this->rules->normalizeIdentifier((string) ($input['key'] ?? ''), 'topic key');
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Topic name is required.');
        }
        $tags = array_key_exists('tags', $input) ? TopicTags::normalize($input['tags']) : null;
        foreach ($localizations as $locale => $localized) {
            $this->rules->assertSupportedLocale((string) $locale);
            if (!is_array($localized) || trim((string) ($localized['name'] ?? '')) === '') {
                throw new RuntimeException(sprintf('Localization %s must contain a name.', $locale));
            }
        }
        return $this->lockedTransaction(function (PDO $db) use ($theme, $key, $name, $input, $localizations, $tags): array {
            $this->requireTheme($db, $theme);
            $identity = ['theme' => $theme, 'key' => $key];
            $old = $this->one($db, 'SELECT name,description,weight,active,created_at FROM quiz_topics
                WHERE theme_id=:theme AND topic_key=:key', $identity);
            $fields = [
                'name' => $name,
                'description' => trim((string) ($input['description'] ?? '')),
                'weight' => (int) ($input['weight'] ?? 0),
                'active' => $this->bool($input['active'] ?? false),
                'created_at' => $this->createdAt($input['created_at'] ?? null, $old['created_at'] ?? null),
            ];
            $unchanged = $old !== null && $fields === [
                'name' => (string) $old['name'],
                'description' => (string) $old['description'],
                'weight' => (int) $old['weight'],
                'active' => $this->bool(in_array($old['active'], [true, 1, '1', 't', 'true'], true)),
                'created_at' => $this->createdAt(null, $old['created_at']),
            ];
            if ($tags !== null && $unchanged) {
                foreach ($localizations as $locale => $localized) {
                    $previous = $this->one($db, 'SELECT name,description FROM quiz_topic_translations
                        WHERE theme_id=:theme AND topic_key=:key AND locale=:locale', $identity + ['locale' => $locale]);
                    if ($previous === null || $previous['name'] !== trim((string) $localized['name'])
                        || $previous['description'] !== trim((string) ($localized['description'] ?? ''))) {
                        $unchanged = false;
                        break;
                    }
                }
                if ($unchanged) {
                    $this->replaceTopicTags($db, $identity, $tags);
                    return ['revision' => null, 'publicationRequired' => false];
                }
            }
            $this->sql($db, 'INSERT INTO quiz_topics
                    (theme_id,topic_key,name,description,weight,active,created_at,updated_at)
                VALUES (:theme,:key,:name,:description,:weight,:active,:created_at,:updated_at)
                ON CONFLICT (theme_id,topic_key) DO UPDATE SET name=EXCLUDED.name,
                    description=EXCLUDED.description, weight=EXCLUDED.weight, active=EXCLUDED.active,
                    created_at=EXCLUDED.created_at, updated_at=EXCLUDED.updated_at',
                $identity + $fields + ['updated_at' => $this->now()]);
            foreach ($localizations as $locale => $localized) {
                $this->sql($db, 'INSERT INTO quiz_topic_translations
                        (theme_id,topic_key,locale,name,description,updated_at)
                    VALUES (:theme,:key,:locale,:name,:description,:updated_at)
                    ON CONFLICT (theme_id,topic_key,locale) DO UPDATE SET name=EXCLUDED.name,
                        description=EXCLUDED.description, updated_at=EXCLUDED.updated_at', $identity + [
                    'locale' => (string) $locale,
                    'name' => trim((string) $localized['name']),
                    'description' => trim((string) ($localized['description'] ?? '')),
                    'updated_at' => $this->now(),
                ]);
            }
            if ($tags !== null) {
                $this->replaceTopicTags($db, $identity, $tags);
            }
            return ['revision' => $this->recordRevision($db, $theme), 'publicationRequired' => true];
        });
    }

    /** @param array{theme:string,key:string} $identity @param list<string> $tags */
    private function replaceTopicTags(PDO $db, array $identity, array $tags): void
    {
        $this->sql($db, 'DELETE FROM quiz_topic_tags WHERE theme_id=:theme AND topic_key=:key', $identity);
        foreach ($tags as $tag) {
            $this->sql($db, 'INSERT INTO quiz_tags (slug) VALUES (:tag) ON CONFLICT DO NOTHING', ['tag' => $tag]);
            $this->sql($db, 'INSERT INTO quiz_topic_tags (theme_id,topic_key,tag_slug) VALUES (:theme,:key,:tag)',
                $identity + ['tag' => $tag]);
        }
    }

    /** @return array{deletedQuestionFiles:int,revision:int} */
    public function deleteTopicPackage(string $theme, string $topic, bool $recursive = false): array
    {
        $theme = $this->rules->normalizeIdentifier($theme, 'theme ID');
        $topic = $this->rules->normalizeIdentifier($topic, 'topic key');
        $deletedQuestionFiles = 0;
        $revision = $this->mutate($theme, function (PDO $db) use ($theme, $topic, $recursive, &$deletedQuestionFiles): void {
            $this->requireTopic($db, $theme, $topic);
            $questionCount = $this->count($db, 'SELECT COUNT(*) FROM quiz_questions WHERE theme_id=:theme AND topic_key=:topic', ['theme' => $theme, 'topic' => $topic]);
            if ($questionCount > 0 && !$recursive) {
                throw new RuntimeException(sprintf('Topic "%s" contains questions; recursive deletion is required.', $topic));
            }
            $deletedQuestionFiles = $this->count($db, 'SELECT COUNT(*) FROM quiz_question_translations WHERE theme_id=:theme AND topic_key=:topic', ['theme' => $theme, 'topic' => $topic]);
            $this->sql($db, 'DELETE FROM quiz_topics WHERE theme_id=:theme AND topic_key=:topic', ['theme' => $theme, 'topic' => $topic]);
        });
        return ['deletedQuestionFiles' => $deletedQuestionFiles, 'revision' => $revision];
    }

    /** @param list<array<string,mixed>> $items @return array{questionIds:list<string>,revision:int} */
    public function createLocalizedQuestionSets(string $theme, string $topic, int $difficulty, array $items): array
    {
        $theme = $this->rules->normalizeIdentifier($theme, 'theme ID');
        $topic = $this->rules->normalizeIdentifier($topic, 'topic key');
        $this->rules->assertDifficulty($difficulty);
        if ($items === [] || count($items) > 50) {
            throw new RuntimeException('Question batch must contain between 1 and 50 items.');
        }
        $ids = [];
        $revision = $this->mutate($theme, function (PDO $db) use ($theme, $topic, $difficulty, $items, &$ids): void {
            $this->requireTopic($db, $theme, $topic);
            $existing = $this->sql($db, 'SELECT question_id FROM quiz_questions WHERE theme_id=:theme AND topic_key=:topic AND difficulty=:difficulty', [
                'theme' => $theme, 'topic' => $topic, 'difficulty' => $difficulty,
            ])->fetchAll(PDO::FETCH_COLUMN);
            $reserved = array_fill_keys($existing, true);
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException('Every question batch item must be an object.');
                }
                $id = trim((string) ($item['id'] ?? ''));
                if ($id !== '') {
                    $id = $this->rules->normalizeIdentifier($id, 'question ID');
                    if (isset($reserved[$id])) {
                        throw new RuntimeException(sprintf('Question ID "%s" already exists or is duplicated in the batch.', $id));
                    }
                    $reserved[$id] = true;
                }
            }
            $prefix = sprintf('%s-%d-', $topic, $difficulty);
            $nextSequence = (int) substr($this->rules->nextQuestionId($topic, $difficulty, $existing), strlen($prefix));
            $prepared = [];
            foreach ($items as $item) {
                $id = trim((string) ($item['id'] ?? ''));
                if ($id === '') {
                    do {
                        $id = sprintf('%s-%d-%03d', $topic, $difficulty, $nextSequence++);
                    } while (isset($reserved[$id]));
                    $reserved[$id] = true;
                }
                $prepared[] = [$id, $this->validatedTranslations($item['translations'] ?? null, $difficulty, $id)];
            }
            foreach ($prepared as [$id, $translations]) {
                $identity = ['theme' => $theme, 'topic' => $topic, 'difficulty' => $difficulty, 'id' => $id];
                $this->sql($db, 'INSERT INTO quiz_questions (theme_id,topic_key,difficulty,question_id,created_at,updated_at)
                    VALUES (:theme,:topic,:difficulty,:id,:created_at,:updated_at)', $identity + [
                    'created_at' => $this->now(), 'updated_at' => $this->now(),
                ]);
                $this->insertTranslations($db, $identity, $translations);
                $ids[] = $id;
            }
        });
        return ['questionIds' => $ids, 'revision' => $revision];
    }

    /** @param array<string,mixed> $translations */
    public function replaceLocalizedQuestionSet(string $theme, string $topic, int $difficulty, string $id, array $translations): int
    {
        $theme = $this->rules->normalizeIdentifier($theme, 'theme ID');
        $topic = $this->rules->normalizeIdentifier($topic, 'topic key');
        $id = $this->rules->normalizeIdentifier($id, 'question ID');
        $validated = $this->validatedTranslations($translations, $difficulty, $id);
        return $this->mutate($theme, function (PDO $db) use ($theme, $topic, $difficulty, $id, $validated): void {
            $identity = ['theme' => $theme, 'topic' => $topic, 'difficulty' => $difficulty, 'id' => $id];
            if ($this->one($db, 'SELECT 1 FROM quiz_questions WHERE theme_id=:theme AND topic_key=:topic AND difficulty=:difficulty AND question_id=:id', $identity) === null) {
                throw new RuntimeException(sprintf('Question "%s" does not exist.', $id));
            }
            $this->sql($db, 'DELETE FROM quiz_question_translations WHERE theme_id=:theme AND topic_key=:topic AND difficulty=:difficulty AND question_id=:id', $identity);
            $this->insertTranslations($db, $identity, $validated);
            $this->sql($db, 'UPDATE quiz_questions SET updated_at=:updated_at WHERE theme_id=:theme AND topic_key=:topic AND difficulty=:difficulty AND question_id=:id',
                $identity + ['updated_at' => $this->now()]);
        });
    }

    /** @return array{deletedLocales:list<string>,missingLocales:list<string>,revision:int} */
    public function deleteLocalizedQuestionSet(string $theme, string $topic, int $difficulty, string $id): array
    {
        $theme = $this->rules->normalizeIdentifier($theme, 'theme ID');
        $topic = $this->rules->normalizeIdentifier($topic, 'topic key');
        $id = $this->rules->normalizeIdentifier($id, 'question ID');
        $this->rules->assertDifficulty($difficulty);
        $locales = [];
        $revision = $this->mutate($theme, function (PDO $db) use ($theme, $topic, $difficulty, $id, &$locales): void {
            $identity = ['theme' => $theme, 'topic' => $topic, 'difficulty' => $difficulty, 'id' => $id];
            $locales = $this->sql($db, 'SELECT locale FROM quiz_question_translations WHERE theme_id=:theme AND topic_key=:topic AND difficulty=:difficulty AND question_id=:id ORDER BY locale', $identity)->fetchAll(PDO::FETCH_COLUMN);
            $deleted = $this->sql($db, 'DELETE FROM quiz_questions WHERE theme_id=:theme AND topic_key=:topic AND difficulty=:difficulty AND question_id=:id', $identity);
            if ($deleted->rowCount() === 0) {
                throw new RuntimeException(sprintf('Question "%s" does not exist.', $id));
            }
        });
        return ['deletedLocales' => $locales, 'missingLocales' => array_values(array_diff($this->rules->supportedLocales(), $locales)), 'revision' => $revision];
    }

    /** @param mixed $input @return array<string,array{prompt:string,correctOptions:list<string>,wrongOptions:list<string>}> */
    private function validatedTranslations(mixed $input, int $difficulty, string $id): array
    {
        $this->rules->assertDifficulty($difficulty);
        if (!is_array($input)) {
            throw new RuntimeException(sprintf('Question "%s" translations must be an object.', $id));
        }
        $provided = array_map('strval', array_keys($input));
        $expected = $this->rules->supportedLocales();
        sort($provided);
        sort($expected);
        if ($provided !== $expected) {
            throw new RuntimeException(sprintf('Question "%s" translations must contain exactly: %s.', $id, implode(', ', $expected)));
        }
        $result = [];
        foreach ($this->rules->supportedLocales() as $locale) {
            if (!is_array($input[$locale])) {
                throw new RuntimeException(sprintf('Question "%s" translation %s must be an object.', $id, $locale));
            }
            $result[$locale] = $this->rules->questionPayload($input[$locale], $difficulty);
        }
        $canonical = $result[$this->rules->fallbackLocale()];
        foreach ($result as $locale => $question) {
            if (count($question['correctOptions']) !== count($canonical['correctOptions']) || count($question['wrongOptions']) !== count($canonical['wrongOptions'])) {
                throw new RuntimeException(sprintf('Localization %s changed the number of answers for question "%s".', $locale, $id));
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $identity @param array<string,array{prompt:string,correctOptions:list<string>,wrongOptions:list<string>}> $translations */
    private function insertTranslations(PDO $db, array $identity, array $translations): void
    {
        foreach ($translations as $locale => $question) {
            $this->sql($db, 'INSERT INTO quiz_question_translations
                (theme_id,topic_key,difficulty,question_id,locale,prompt,updated_at)
                VALUES (:theme,:topic,:difficulty,:id,:locale,:prompt,:updated_at)', $identity + [
                'locale' => $locale, 'prompt' => $question['prompt'], 'updated_at' => $this->now(),
            ]);
            foreach (['correct' => $question['correctOptions'], 'wrong' => $question['wrongOptions']] as $kind => $answers) {
                foreach ($answers as $position => $answer) {
                    $this->sql($db, 'INSERT INTO quiz_answers
                        (theme_id,topic_key,difficulty,question_id,locale,kind,answer_position,answer_text)
                        VALUES (:theme,:topic,:difficulty,:id,:locale,:kind,:position,:answer)', $identity + [
                        'locale' => $locale, 'kind' => $kind, 'position' => $position, 'answer' => $answer,
                    ]);
                }
            }
        }
    }

    /** @param callable(PDO):void $operation */
    private function mutate(?string $theme, callable $operation): int
    {
        return $this->lockedTransaction(function (PDO $db) use ($theme, $operation): int {
            $operation($db);
            return $this->recordRevision($db, $theme);
        });
    }

    private function recordRevision(PDO $db, ?string $theme): int
    {
        $revision = (int) $db->query('UPDATE quiz_catalog_state SET current_revision=current_revision+1,
            updated_at=CURRENT_TIMESTAMP WHERE singleton=1 RETURNING current_revision')->fetchColumn();
        $this->sql($db, 'INSERT INTO quiz_publications (revision,affected_theme_id,status) VALUES (:revision,:theme,:status)', [
            'revision' => $revision, 'theme' => $theme, 'status' => 'pending',
        ]);
        return $revision;
    }

    private function lockedTransaction(callable $operation): mixed
    {
        try {
            return $this->database->transactional(function (PDO $db) use ($operation): mixed {
                QuizCatalogLock::transaction($db);
                $db->query('SELECT current_revision FROM quiz_catalog_state WHERE singleton=1 FOR UPDATE')->fetchColumn();
                return $operation($db);
            });
        } catch (PDOException) {
            throw new QuizDatabaseException();
        }
    }

    private function requireTheme(PDO $db, string $id): void
    {
        if ($this->one($db, 'SELECT 1 FROM quiz_themes WHERE id=:id', ['id' => $id]) === null) {
            throw new RuntimeException(sprintf('Theme "%s" is not defined.', $id));
        }
    }

    private function requireTopic(PDO $db, string $theme, string $topic): void
    {
        if ($this->one($db, 'SELECT 1 FROM quiz_topics WHERE theme_id=:theme AND topic_key=:topic', ['theme' => $theme, 'topic' => $topic]) === null) {
            throw new RuntimeException(sprintf('Topic "%s" is not defined in central catalog.', $topic));
        }
    }

    /** @param array<string,mixed> $params @return array<string,mixed>|null */
    private function one(PDO $db, string $query, array $params): ?array
    {
        $row = $this->sql($db, $query, $params)->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $params */
    private function count(PDO $db, string $query, array $params): int
    {
        return (int) $this->sql($db, $query, $params)->fetchColumn();
    }

    /** @param array<string,mixed> $params */
    private function sql(PDO $db, string $query, array $params): \PDOStatement
    {
        $statement = $db->prepare($query);
        $statement->execute($params);
        return $statement;
    }

    private function createdAt(mixed $provided, mixed $existing): string
    {
        $provided = trim((string) ($provided ?? ''));
        if ($provided !== '') {
            return $this->rules->normalizeCreatedAtUtc($provided);
        }
        if ($existing !== null) {
            return (new DateTimeImmutable((string) $existing))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
        }
        return $this->now();
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
    }

    private function bool(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) ? 'true' : 'false';
    }
}
