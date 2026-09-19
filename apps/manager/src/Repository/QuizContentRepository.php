<?php

namespace App\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class QuizContentRepository
{
    public function __construct(private readonly ManagerDatabase $database)
    {
    }

    /** @return list<array{id:string,name:string,description:string,weight:int,createdAt:string,active:bool}> */
    public function themes(): array
    {
        $rows = $this->database->connection()->query(
            'SELECT id, name, description, weight, active, created_at
             FROM quiz_themes
             ORDER BY weight, id',
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->themeRow($row), $rows);
    }

    /** @return array{id:string,name:string,description:string,weight:int,createdAt:string,active:bool}|null */
    public function theme(string $id): ?array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT id, name, description, weight, active, created_at
             FROM quiz_themes
             WHERE id = :id',
        );
        $statement->execute(['id' => trim($id)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->themeRow($row) : null;
    }

    /**
     * @return list<array{
     *   key:string,name:string,description:string,weight:int,created_at:string,
     *   active:bool,questionCount:int,localizedName:string,localizedDescription:string
     * }>
     */
    public function topics(string $theme, string $locale, string $fallbackLocale): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT
                topic.topic_key,
                topic.name,
                topic.description,
                topic.weight,
                topic.active,
                topic.created_at,
                localized.name AS localized_name,
                localized.description AS localized_description,
                COALESCE(question_counts.question_count, 0) AS question_count
             FROM quiz_topics topic
             LEFT JOIN quiz_topic_translations localized
               ON localized.theme_id = topic.theme_id
              AND localized.topic_key = topic.topic_key
              AND localized.locale = :locale
             LEFT JOIN (
                SELECT question.theme_id, question.topic_key, COUNT(*) AS question_count
                FROM quiz_questions question
                INNER JOIN quiz_question_translations canonical
                  ON canonical.theme_id = question.theme_id
                 AND canonical.topic_key = question.topic_key
                 AND canonical.difficulty = question.difficulty
                 AND canonical.question_id = question.question_id
                 AND canonical.locale = :fallback_locale
                GROUP BY question.theme_id, question.topic_key
             ) question_counts
               ON question_counts.theme_id = topic.theme_id
              AND question_counts.topic_key = topic.topic_key
             WHERE topic.theme_id = :theme
             ORDER BY topic.weight, topic.topic_key',
        );
        $statement->execute([
            'theme' => trim($theme),
            'locale' => trim($locale),
            'fallback_locale' => trim($fallbackLocale),
        ]);

        return array_map(function (array $row): array {
            return [
                'key' => (string) $row['topic_key'],
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'weight' => (int) $row['weight'],
                'created_at' => $this->utcTimestamp((string) $row['created_at']),
                'active' => $this->boolean($row['active']),
                'questionCount' => (int) $row['question_count'],
                'localizedName' => (string) ($row['localized_name'] ?? ''),
                'localizedDescription' => (string) ($row['localized_description'] ?? ''),
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array{
     *   id:string,difficulty:int,prompt:string,correctCount:int,wrongCount:int
     * }>
     */
    public function questions(string $theme, string $topic, string $locale, ?int $difficulty = null): array
    {
        $sql = 'SELECT
                    question.question_id,
                    question.difficulty,
                    translation.prompt,
                    COUNT(answer.answer_position) FILTER (WHERE answer.kind = \'correct\') AS correct_count,
                    COUNT(answer.answer_position) FILTER (WHERE answer.kind = \'wrong\') AS wrong_count
                FROM quiz_questions question
                INNER JOIN quiz_question_translations translation
                  ON translation.theme_id = question.theme_id
                 AND translation.topic_key = question.topic_key
                 AND translation.difficulty = question.difficulty
                 AND translation.question_id = question.question_id
                 AND translation.locale = :locale
                LEFT JOIN quiz_answers answer
                  ON answer.theme_id = translation.theme_id
                 AND answer.topic_key = translation.topic_key
                 AND answer.difficulty = translation.difficulty
                 AND answer.question_id = translation.question_id
                 AND answer.locale = translation.locale
                WHERE question.theme_id = :theme
                  AND question.topic_key = :topic';
        $parameters = [
            'theme' => trim($theme),
            'topic' => trim($topic),
            'locale' => trim($locale),
        ];
        if ($difficulty !== null) {
            $sql .= ' AND question.difficulty = :difficulty';
            $parameters['difficulty'] = $difficulty;
        }
        $sql .= ' GROUP BY question.question_id, question.difficulty, translation.prompt
                  ORDER BY question.difficulty, question.question_id';

        $statement = $this->database->connection()->prepare($sql);
        $statement->execute($parameters);

        return array_map(static function (array $row): array {
            return [
                'id' => (string) $row['question_id'],
                'difficulty' => (int) $row['difficulty'],
                'prompt' => (string) $row['prompt'],
                'correctCount' => (int) $row['correct_count'],
                'wrongCount' => (int) $row['wrong_count'],
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string,array{prompt:string,correctOptions:list<string>,wrongOptions:list<string>}>
     */
    public function localizedQuestionSet(string $theme, string $topic, int $difficulty, string $questionId): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT
                translation.locale,
                translation.prompt,
                answer.kind,
                answer.answer_position,
                answer.answer_text
             FROM quiz_question_translations translation
             LEFT JOIN quiz_answers answer
               ON answer.theme_id = translation.theme_id
              AND answer.topic_key = translation.topic_key
              AND answer.difficulty = translation.difficulty
              AND answer.question_id = translation.question_id
              AND answer.locale = translation.locale
             WHERE translation.theme_id = :theme
               AND translation.topic_key = :topic
               AND translation.difficulty = :difficulty
               AND translation.question_id = :question_id
             ORDER BY
                translation.locale,
                CASE answer.kind WHEN \'correct\' THEN 0 WHEN \'wrong\' THEN 1 ELSE 2 END,
                answer.answer_position',
        );
        $statement->execute([
            'theme' => trim($theme),
            'topic' => trim($topic),
            'difficulty' => $difficulty,
            'question_id' => trim($questionId),
        ]);

        $questions = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $locale = (string) $row['locale'];
            $questions[$locale] ??= [
                'prompt' => (string) $row['prompt'],
                'correctOptions' => [],
                'wrongOptions' => [],
            ];
            if ($row['kind'] === 'correct') {
                $questions[$locale]['correctOptions'][] = (string) $row['answer_text'];
            } elseif ($row['kind'] === 'wrong') {
                $questions[$locale]['wrongOptions'][] = (string) $row['answer_text'];
            }
        }

        return $questions;
    }

    /** @return array<int,int> */
    public function questionCountsByDifficulty(string $theme, string $topic, string $fallbackLocale): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT question.difficulty, COUNT(*) AS question_count
             FROM quiz_questions question
             INNER JOIN quiz_question_translations canonical
               ON canonical.theme_id = question.theme_id
              AND canonical.topic_key = question.topic_key
              AND canonical.difficulty = question.difficulty
              AND canonical.question_id = question.question_id
              AND canonical.locale = :fallback_locale
             WHERE question.theme_id = :theme
               AND question.topic_key = :topic
             GROUP BY question.difficulty
             ORDER BY question.difficulty',
        );
        $statement->execute([
            'theme' => trim($theme),
            'topic' => trim($topic),
            'fallback_locale' => trim($fallbackLocale),
        ]);

        $counts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['difficulty']] = (int) $row['question_count'];
        }

        return $counts;
    }

    /** @param array<string,mixed> $row @return array{id:string,name:string,description:string,weight:int,createdAt:string,active:bool} */
    private function themeRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'weight' => (int) $row['weight'],
            'createdAt' => $this->utcTimestamp((string) $row['created_at']),
            'active' => $this->boolean($row['active']),
        ];
    }

    private function utcTimestamp(string $value): string
    {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:sP');
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
