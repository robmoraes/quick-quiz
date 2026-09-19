<?php

namespace App\Service;

use App\Repository\ManagerDatabase;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/** Builds the existing published JSON layout from one database snapshot. */
final class QuizProjectionRenderer
{
    public function __construct(private readonly ManagerDatabase $database, private readonly QuizContentRules $rules)
    {
    }

    /** @return array<string,array<string,mixed>> */
    public function render(): array
    {
        $db = $this->database->connection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        }
        try {
            $objects = $this->renderSnapshot($db);
            if ($ownsTransaction) {
                $db->commit();
            }
            return $objects;
        } catch (Throwable $error) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function renderSnapshot(PDO $db): array
    {
        $objects = [];
        $themeRows = $db->query('SELECT id,name,description,weight,active,created_at
            FROM quiz_themes ORDER BY weight,id')->fetchAll(PDO::FETCH_ASSOC);
        $themes = [];
        foreach ($themeRows as $row) {
            $themes[] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'weight' => (int) $row['weight'],
                'createdAt' => $this->utc((string) $row['created_at']),
                'active' => $this->boolean($row['active']),
            ];
            $theme = (string) $row['id'];
            $objects[$theme.'/index.json'] = ['topics' => []];
            foreach ($this->rules->supportedLocales() as $locale) {
                $objects[$theme.'/'.$locale.'/index.json'] = ['topics' => []];
            }
        }
        $objects['themes.json'] = ['themes' => $themes];

        $topics = $db->query('SELECT theme_id,topic_key,name,description,weight,active,created_at
            FROM quiz_topics ORDER BY theme_id,weight,topic_key')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($topics as $row) {
            $objects[$row['theme_id'].'/index.json']['topics'][] = [
                'key' => (string) $row['topic_key'],
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'weight' => (int) $row['weight'],
                'created_at' => $this->utc((string) $row['created_at']),
                'active' => $this->boolean($row['active']),
            ];
        }
        $localized = $db->query('SELECT translation.theme_id,translation.topic_key,translation.locale,
                translation.name,translation.description
            FROM quiz_topic_translations translation
            INNER JOIN quiz_topics topic ON topic.theme_id=translation.theme_id
                AND topic.topic_key=translation.topic_key
            ORDER BY translation.theme_id,translation.locale,topic.weight,translation.topic_key'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($localized as $row) {
            $path = $row['theme_id'].'/'.$row['locale'].'/index.json';
            $this->rules->assertSupportedLocale((string) $row['locale']);
            $objects[$path]['topics'][] = [
                'key' => (string) $row['topic_key'],
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
            ];
        }

        $rows = $db->query('SELECT translation.theme_id,translation.topic_key,translation.difficulty,
                translation.question_id,translation.locale,translation.prompt,
                answer.kind,answer.answer_position,answer.answer_text
            FROM quiz_question_translations translation
            LEFT JOIN quiz_answers answer ON answer.theme_id=translation.theme_id
                AND answer.topic_key=translation.topic_key
                AND answer.difficulty=translation.difficulty
                AND answer.question_id=translation.question_id
                AND answer.locale=translation.locale
            ORDER BY translation.theme_id,translation.locale,translation.topic_key,
                translation.difficulty,translation.question_id,
                CASE answer.kind WHEN \'correct\' THEN 0 WHEN \'wrong\' THEN 1 ELSE 2 END,
                answer.answer_position')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $this->rules->assertSupportedLocale((string) $row['locale']);
            $path = sprintf('%s/%s/%s/%d/%s.json',
                $row['theme_id'], $row['locale'], $row['topic_key'], $row['difficulty'], $row['question_id']);
            $objects[$path] ??= [
                'prompt' => (string) $row['prompt'],
                'correctOptions' => [],
                'wrongOptions' => [],
            ];
            if ($row['kind'] === 'correct') {
                $objects[$path]['correctOptions'][] = (string) $row['answer_text'];
            } elseif ($row['kind'] === 'wrong') {
                $objects[$path]['wrongOptions'][] = (string) $row['answer_text'];
            }
        }
        ksort($objects);
        return $objects;
    }

    private function utc(string $timestamp): string
    {
        return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
