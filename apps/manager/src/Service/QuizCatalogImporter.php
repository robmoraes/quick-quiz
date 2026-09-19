<?php

namespace App\Service;

use App\Repository\ManagerDatabase;
use App\Repository\QuizCatalogLock;
use PDO;
use RuntimeException;

/** Validates first, then replaces or inserts the entire quiz catalog in one transaction. */
final class QuizCatalogImporter
{
    public function __construct(
        private readonly QuizSourceCatalog $source,
        private readonly QuizProjectionRenderer $renderer,
        private readonly QuizContentComparator $comparator,
        private readonly ManagerDatabase $database,
    ) {
    }

    /** @return array<string,mixed> */
    public function run(bool $apply = false, bool $replace = false): array
    {
        $snapshot = $this->source->load();
        $sourceObjects = $snapshot['objects'];
        $current = $this->renderer->render();
        $comparison = $this->comparator->compare($sourceObjects, $current);
        $report = [
            'counts' => $snapshot['counts'],
            'breakdown' => $snapshot['breakdown'],
            'sourceChecksum' => $this->comparator->checksum($sourceObjects),
            'comparison' => $comparison,
            'applied' => false,
            'revision' => null,
        ];
        if (!$apply || $comparison['equal']) {
            return $report;
        }

        $revision = $this->database->transactional(function (PDO $db) use ($snapshot, $sourceObjects, $replace): ?int {
            QuizCatalogLock::transaction($db);
            $db->query('SELECT current_revision FROM quiz_catalog_state WHERE singleton=1 FOR UPDATE')->fetchColumn();
            $current = $this->renderer->render();
            if ($this->comparator->compare($sourceObjects, $current)['equal']) {
                return null;
            }
            $existing = (int) $db->query('SELECT COUNT(*) FROM quiz_themes')->fetchColumn();
            if ($existing > 0 && !$replace) {
                throw new RuntimeException('Quiz catalog conflicts with existing PostgreSQL content; use --replace explicitly.');
            }
            $tags = [];
            if ($replace) {
                $tags = $db->query('SELECT theme_id,topic_key,tag_slug FROM quiz_topic_tags')->fetchAll(PDO::FETCH_ASSOC);
                $db->exec('DELETE FROM quiz_themes');
            }
            $this->insertSnapshot($db, $snapshot);
            $restoreTags = $db->prepare('INSERT INTO quiz_topic_tags (theme_id,topic_key,tag_slug)
                SELECT theme_id,topic_key,:tag FROM quiz_topics WHERE theme_id=:theme AND topic_key=:topic');
            foreach ($tags as $tag) {
                $restoreTags->execute(['theme' => $tag['theme_id'], 'topic' => $tag['topic_key'], 'tag' => $tag['tag_slug']]);
            }
            $revision = (int) $db->query('UPDATE quiz_catalog_state
                SET current_revision=current_revision+1, updated_at=CURRENT_TIMESTAMP
                WHERE singleton=1 RETURNING current_revision')->fetchColumn();
            $this->insert($db, 'INSERT INTO quiz_publications (revision,status) VALUES (:revision,:status)', [
                'revision' => $revision, 'status' => 'pending',
            ]);
            return $revision;
        });
        $report['applied'] = $revision !== null;
        $report['revision'] = $revision;
        $report['comparison'] = $this->comparator->compare($sourceObjects, $this->renderer->render());
        return $report;
    }

    /** @param array<string,mixed> $snapshot */
    private function insertSnapshot(PDO $db, array $snapshot): void
    {
        $now = gmdate('c');
        foreach ($snapshot['themes'] as $theme) {
            $this->insert($db, 'INSERT INTO quiz_themes
                (id,name,description,weight,active,created_at,updated_at)
                VALUES (:id,:name,:description,:weight,:active,:created_at,:updated_at)', [
                'id' => $theme['id'], 'name' => $theme['name'],
                'description' => $theme['description'], 'weight' => $theme['weight'],
                'active' => $theme['active'] ? 'true' : 'false',
                'created_at' => $theme['createdAt'], 'updated_at' => $now,
            ]);
            $themeId = $theme['id'];
            foreach ($snapshot['topics'][$themeId] as $topic) {
                $this->insert($db, 'INSERT INTO quiz_topics
                    (theme_id,topic_key,name,description,weight,active,created_at,updated_at)
                    VALUES (:theme,:key,:name,:description,:weight,:active,:created_at,:updated_at)', [
                    'theme' => $themeId, 'key' => $topic['key'], 'name' => $topic['name'],
                    'description' => $topic['description'], 'weight' => $topic['weight'],
                    'active' => $topic['active'] ? 'true' : 'false',
                    'created_at' => $topic['created_at'], 'updated_at' => $now,
                ]);
            }
            foreach ($snapshot['localizedTopics'][$themeId] as $locale => $entries) {
                foreach ($entries as $topic) {
                    $this->insert($db, 'INSERT INTO quiz_topic_translations
                        (theme_id,topic_key,locale,name,description,updated_at)
                        VALUES (:theme,:key,:locale,:name,:description,:updated_at)', [
                        'theme' => $themeId, 'key' => $topic['key'], 'locale' => $locale,
                        'name' => $topic['name'], 'description' => $topic['description'],
                        'updated_at' => $now,
                    ]);
                }
            }
        }
        foreach ($snapshot['questions'] as $theme => $byTopic) {
            foreach ($byTopic as $topic => $byDifficulty) {
                foreach ($byDifficulty as $difficulty => $byId) {
                    foreach ($byId as $id => $translations) {
                        $identity = [
                            'theme' => $theme, 'topic' => $topic,
                            'difficulty' => $difficulty, 'id' => $id,
                        ];
                        $this->insert($db, 'INSERT INTO quiz_questions
                            (theme_id,topic_key,difficulty,question_id,created_at,updated_at)
                            VALUES (:theme,:topic,:difficulty,:id,:created_at,:updated_at)',
                            $identity + ['created_at' => $now, 'updated_at' => $now]);
                        foreach ($translations as $locale => $payload) {
                            $this->insert($db, 'INSERT INTO quiz_question_translations
                                (theme_id,topic_key,difficulty,question_id,locale,prompt,updated_at)
                                VALUES (:theme,:topic,:difficulty,:id,:locale,:prompt,:updated_at)',
                                $identity + ['locale' => $locale, 'prompt' => $payload['prompt'], 'updated_at' => $now]);
                            foreach (['correct' => $payload['correctOptions'], 'wrong' => $payload['wrongOptions']] as $kind => $answers) {
                                foreach ($answers as $position => $answer) {
                                    $this->insert($db, 'INSERT INTO quiz_answers
                                        (theme_id,topic_key,difficulty,question_id,locale,kind,answer_position,answer_text)
                                        VALUES (:theme,:topic,:difficulty,:id,:locale,:kind,:position,:answer)',
                                        $identity + [
                                            'locale' => $locale, 'kind' => $kind,
                                            'position' => $position, 'answer' => $answer,
                                        ]);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /** @param array<string,mixed> $params */
    private function insert(PDO $db, string $query, array $params): void
    {
        $statement = $db->prepare($query);
        $statement->execute($params);
    }
}
