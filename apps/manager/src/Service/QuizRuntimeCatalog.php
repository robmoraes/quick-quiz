<?php

namespace App\Service;

use PDO;
use RuntimeException;

/** Lightweight state and SQL integrity checks; never renders question payloads. */
final class QuizRuntimeCatalog
{
    public function __construct(private readonly QuizContentRules $rules)
    {
    }

    /** @return array<string,mixed> */
    public function state(PDO $db, bool $lock = false): array
    {
        $row = $db->query('SELECT current_revision,published_revision,publication_backend,contract_version,
            fallback_locale,array_to_json(supported_locales) AS supported_locales
            FROM quiz_catalog_state WHERE singleton=1'.($lock ? ' FOR UPDATE' : ''))->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Quiz catalog state is missing; run database migrations.');
        }
        return $row;
    }

    public function assertBackend(PDO $db, string $provider): void
    {
        $state = $this->state($db);
        if ($state['publication_backend'] !== $provider) {
            throw new RuntimeException('Configured quiz publication backend disagrees with stored catalog state.');
        }
        if ($provider === 'postgres') {
            $locales = json_decode((string) $state['supported_locales'], true);
            $expected = $this->rules->supportedLocales();
            if (is_array($locales)) {
                sort($locales);
            }
            sort($expected);
            if ((int) $state['contract_version'] !== 1
                || (int) $state['current_revision'] !== (int) $state['published_revision']
                || $state['fallback_locale'] !== $this->rules->fallbackLocale() || $locales !== $expected) {
                throw new RuntimeException('PostgreSQL quiz runtime contract, revisions, or declared locales are incompatible.');
            }
        }
    }

    /** Validate only eligible descendants when activating; exports validate all authored packages. */
    public function assertIntegrity(PDO $db, ?string $theme = null, ?string $topic = null, bool $activeOnly = false): void
    {
        $where = [];
        $params = ['locales' => json_encode($this->rules->supportedLocales(), JSON_THROW_ON_ERROR),
            'locale_count' => count($this->rules->supportedLocales())];
        if ($theme !== null) {
            $where[] = 'q.theme_id=:theme';
            $params['theme'] = $theme;
        }
        if ($topic !== null) {
            $where[] = 'q.topic_key=:topic';
            $params['topic'] = $topic;
        }
        if ($activeOnly) {
            $where[] = 'theme.active AND topic.active';
        }
        $statement = $db->prepare('WITH expected AS (
                SELECT jsonb_array_elements_text(CAST(:locales AS jsonb)) AS locale
            ), packages AS (
                SELECT q.theme_id,q.topic_key,q.difficulty,q.question_id,tr.locale,
                    COUNT(a.answer_position) FILTER (WHERE a.kind=\'correct\') AS correct_count,
                    COUNT(a.answer_position) FILTER (WHERE a.kind=\'wrong\') AS wrong_count
                FROM quiz_questions q JOIN quiz_themes theme ON theme.id=q.theme_id
                JOIN quiz_topics topic ON topic.theme_id=q.theme_id AND topic.topic_key=q.topic_key
                LEFT JOIN quiz_question_translations tr ON tr.theme_id=q.theme_id AND tr.topic_key=q.topic_key
                    AND tr.difficulty=q.difficulty AND tr.question_id=q.question_id
                LEFT JOIN quiz_answers a ON a.theme_id=tr.theme_id AND a.topic_key=tr.topic_key
                    AND a.difficulty=tr.difficulty AND a.question_id=tr.question_id AND a.locale=tr.locale
                '.($where === [] ? '' : 'WHERE '.implode(' AND ', $where)).'
                GROUP BY q.theme_id,q.topic_key,q.difficulty,q.question_id,tr.locale
            ) SELECT theme_id,topic_key,difficulty,question_id FROM packages
            GROUP BY theme_id,topic_key,difficulty,question_id
            HAVING COUNT(locale) <> :locale_count
                OR COUNT(locale) FILTER (WHERE locale IN (SELECT locale FROM expected)) <> :locale_count
                OR MIN(correct_count)<1
                OR MIN(wrong_count)<CASE difficulty WHEN 1 THEN 2 WHEN 2 THEN 4 ELSE 6 END
                OR MIN(correct_count)<>MAX(correct_count) OR MIN(wrong_count)<>MAX(wrong_count)
            LIMIT 1');
        $statement->execute($params);
        if ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('Quiz content has incomplete locale packages or inconsistent answer counts.');
        }
    }
}
