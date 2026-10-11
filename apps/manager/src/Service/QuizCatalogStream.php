<?php

namespace App\Service;

use App\Repository\ManagerDatabase;
use App\Repository\QuizCatalogLock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/** Explicit legacy projections: metadata plus at most 100 localized question packages in memory. */
final class QuizCatalogStream
{
    public function __construct(
        private readonly ManagerDatabase $database,
        private readonly QuizContentRules $rules,
        private readonly QuizRuntimeCatalog $runtime,
    ) {}

    /** @param callable(string,string):void|null $consume @return array<string,mixed> */
    public function inspect(int $expectedRevision, ?callable $consume = null): array
    {
        $db = $this->database->connection();
        $ownsTransaction = !$db->inTransaction();
        QuizCatalogLock::session($db);
        try {
            if ($ownsTransaction) {
                $db->beginTransaction();
                $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $state = $this->runtime->state($db);
            if ((int) $state['current_revision'] !== $expectedRevision) {
                throw new RuntimeException('Expected quiz revision does not match the current catalog.');
            }
            $this->runtime->assertIntegrity($db);
            $hash = hash_init('sha256');
            $objects = 0;
            foreach ($this->objects($db) as $key => $payload) {
                $canonical = is_string($payload) ? rtrim($payload, "\r\n")."\n" : $this->canonicalJson($payload);
                hash_update($hash, $key."\0".$canonical."\0");
                $contents = is_string($payload) ? $canonical
                    : json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                if ($consume !== null) {
                    $consume($key, $contents);
                }
                ++$objects;
            }
            $counts = [];
            foreach (['themes' => 'quiz_themes', 'topics' => 'quiz_topics', 'canonicalQuestions' => 'quiz_questions',
                'localizedQuestions' => 'quiz_question_translations', 'answers' => 'quiz_answers'] as $label => $table) {
                $counts[$label] = (int) $db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
            }
            $report = [
                'version' => 1, 'checksumAlgorithm' => 'quiz-legacy-stream-v1-sha256',
                'revision' => $expectedRevision, 'publishedRevision' => (int) $state['published_revision'],
                'checksum' => hash_final($hash), 'objectCount' => $objects, 'counts' => $counts,
                'fallbackLocale' => $this->rules->fallbackLocale(), 'supportedLocales' => $this->rules->supportedLocales(),
                'localeParity' => true, 'complete' => true,
            ];
            if ($ownsTransaction) {
                $db->commit();
            }
            return $report;
        } catch (Throwable $error) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        } finally {
            QuizCatalogLock::release($db);
        }
    }

    /** @return iterable<string,array<string,mixed>|string> */
    private function objects(PDO $db): iterable
    {
        $themes = $db->query('SELECT id,name,description,weight,active,created_at,
            ARRAY_TO_JSON(ARRAY(SELECT tag_slug FROM quiz_theme_tags WHERE theme_id=t.id ORDER BY tag_slug COLLATE "C")) AS tags
            FROM quiz_themes t ORDER BY weight,id COLLATE "C"')->fetchAll(PDO::FETCH_ASSOC);
        yield 'themes.json' => ['themes' => array_map(fn (array $t): array => [
            'id' => $t['id'], 'name' => $t['name'], 'description' => $t['description'], 'weight' => (int) $t['weight'],
            'active' => $this->boolean($t['active']), 'createdAt' => $this->utc($t['created_at']),
            'tags' => json_decode($t['tags'], true, flags: JSON_THROW_ON_ERROR),
        ], $themes)];
        foreach ($themes as $theme) {
            $topics = $db->prepare('SELECT topic_key,name,description,weight,active,created_at
                FROM quiz_topics WHERE theme_id=:theme ORDER BY weight,topic_key COLLATE "C"');
            $topics->execute(['theme' => $theme['id']]);
            yield $theme['id'].'/index.json' => ['topics' => array_map(fn (array $t): array => [
                'key' => $t['topic_key'], 'name' => $t['name'], 'description' => $t['description'],
                'weight' => (int) $t['weight'], 'active' => $this->boolean($t['active']), 'created_at' => $this->utc($t['created_at']),
            ], $topics->fetchAll(PDO::FETCH_ASSOC))];
            foreach ($this->rules->supportedLocales() as $locale) {
                $localized = $db->prepare('SELECT tr.topic_key AS key,tr.name,tr.description FROM quiz_topic_translations tr
                    JOIN quiz_topics t ON t.theme_id=tr.theme_id AND t.topic_key=tr.topic_key
                    WHERE tr.theme_id=:theme AND tr.locale=:locale ORDER BY t.weight,tr.topic_key COLLATE "C"');
                $localized->execute(['theme' => $theme['id'], 'locale' => $locale]);
                yield $theme['id'].'/'.$locale.'/index.json' => ['topics' => $localized->fetchAll(PDO::FETCH_ASSOC)];
            }
        }
        $last = null;
        do {
            $where = $last === null ? '' : 'WHERE (tr.theme_id,tr.topic_key,tr.difficulty,tr.question_id,tr.locale)
                > (:theme,:topic,:difficulty,:id,:locale)';
            $query = $db->prepare('SELECT tr.theme_id,tr.topic_key,tr.difficulty,tr.question_id,tr.locale,tr.prompt,
                COALESCE((SELECT json_agg(a.answer_text ORDER BY a.answer_position) FROM quiz_answers a
                    WHERE a.theme_id=tr.theme_id AND a.topic_key=tr.topic_key AND a.difficulty=tr.difficulty
                    AND a.question_id=tr.question_id AND a.locale=tr.locale AND a.kind=\'correct\'),\'[]\') AS correct_options,
                COALESCE((SELECT json_agg(a.answer_text ORDER BY a.answer_position) FROM quiz_answers a
                    WHERE a.theme_id=tr.theme_id AND a.topic_key=tr.topic_key AND a.difficulty=tr.difficulty
                    AND a.question_id=tr.question_id AND a.locale=tr.locale AND a.kind=\'wrong\'),\'[]\') AS wrong_options
                FROM quiz_question_translations tr '.$where.'
                ORDER BY tr.theme_id,tr.topic_key,tr.difficulty,tr.question_id,tr.locale LIMIT 100');
            $query->execute($last ?? []);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                yield sprintf('%s/%s/%s/%d/%s.json', $row['theme_id'], $row['locale'], $row['topic_key'], $row['difficulty'], $row['question_id']) => [
                    'prompt' => $row['prompt'], 'correctOptions' => json_decode($row['correct_options'], true, flags: JSON_THROW_ON_ERROR),
                    'wrongOptions' => json_decode($row['wrong_options'], true, flags: JSON_THROW_ON_ERROR),
                ];
                $last = ['theme' => $row['theme_id'], 'topic' => $row['topic_key'], 'difficulty' => $row['difficulty'],
                    'id' => $row['question_id'], 'locale' => $row['locale']];
            }
        } while (count($rows) === 100);
        $prompts = $db->query('SELECT theme,prompt_text FROM ai_prompts WHERE prompt_key=\'question_solution\' ORDER BY theme COLLATE "C"');
        while ($row = $prompts->fetch(PDO::FETCH_ASSOC)) {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $row['theme']) || trim($row['prompt_text']) === '') {
                throw new RuntimeException('Invalid solution instruction cannot be exported.');
            }
            yield $row['theme'].'/ai-prompts/question-solution-prompt.txt' => $row['prompt_text'];
        }
    }

    /** Preserve object/array types while normalizing JSON object key order. */
    public function canonicalJson(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if ($item instanceof \stdClass) {
                $properties = get_object_vars($item);
                ksort($properties);
                $object = new \stdClass();
                foreach ($properties as $key => $value) {
                    $object->{$key} = $sort($value);
                }
                return $object;
            }
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item);
            }
            return array_map($sort, $item);
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function utc(string $timestamp): string
    {
        return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    }

    private function boolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
