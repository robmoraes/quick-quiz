ALTER TABLE quiz_catalog_state
    ADD COLUMN publication_backend TEXT NOT NULL DEFAULT 'content_storage',
    ADD COLUMN contract_version INTEGER NOT NULL DEFAULT 1,
    ADD COLUMN fallback_locale TEXT NULL,
    ADD COLUMN supported_locales TEXT[] NULL,
    ADD CONSTRAINT quiz_catalog_state_backend CHECK (publication_backend IN ('content_storage', 'postgres')),
    ADD CONSTRAINT quiz_catalog_state_contract CHECK (contract_version = 1),
    ADD CONSTRAINT quiz_catalog_state_runtime_locales CHECK (
        publication_backend <> 'postgres' OR (
            fallback_locale IS NOT NULL AND btrim(fallback_locale) <> ''
            AND supported_locales IS NOT NULL AND cardinality(supported_locales) > 0
            AND fallback_locale = ANY(supported_locales)
            AND current_revision = published_revision
        )
    );

-- The support table may not have been initialized on a new database yet.
CREATE TABLE IF NOT EXISTS ai_prompts (
    theme TEXT NOT NULL,
    prompt_key TEXT NOT NULL,
    prompt_text TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (theme, prompt_key)
);

CREATE SCHEMA quiz_runtime_v1;
REVOKE ALL ON SCHEMA quiz_runtime_v1 FROM PUBLIC;

CREATE VIEW quiz_runtime_v1.state AS
SELECT current_revision, published_revision, publication_backend, contract_version,
    COALESCE(fallback_locale, '') AS fallback_locale,
    COALESCE(supported_locales, ARRAY[]::TEXT[]) AS supported_locales
FROM quiz_catalog_state WHERE singleton = 1;

CREATE VIEW quiz_runtime_v1.themes AS
SELECT t.id, t.name, t.description, t.weight, t.active, t.created_at,
    ARRAY(SELECT tt.tag_slug FROM quiz_theme_tags tt WHERE tt.theme_id = t.id
          ORDER BY tt.tag_slug COLLATE "C") AS tags
FROM quiz_themes t WHERE t.active;

CREATE VIEW quiz_runtime_v1.topics AS
SELECT t.theme_id, t.topic_key, t.name, t.description, t.weight, t.active, t.created_at
FROM quiz_topics t JOIN quiz_themes parent ON parent.id = t.theme_id
WHERE t.active AND parent.active;

CREATE VIEW quiz_runtime_v1.topic_translations AS
SELECT tr.theme_id, tr.topic_key, tr.locale, tr.name, tr.description
FROM quiz_topic_translations tr JOIN quiz_runtime_v1.topics t
ON t.theme_id = tr.theme_id AND t.topic_key = tr.topic_key;

CREATE VIEW quiz_runtime_v1.question_keys AS
SELECT q.theme_id, q.topic_key, q.difficulty, q.question_id
FROM quiz_questions q JOIN quiz_runtime_v1.topics t
ON t.theme_id = q.theme_id AND t.topic_key = q.topic_key;

CREATE VIEW quiz_runtime_v1.question_translations AS
SELECT tr.theme_id, tr.topic_key, tr.difficulty, tr.question_id, tr.locale, tr.prompt
FROM quiz_question_translations tr JOIN quiz_runtime_v1.question_keys q
ON q.theme_id = tr.theme_id AND q.topic_key = tr.topic_key
AND q.difficulty = tr.difficulty AND q.question_id = tr.question_id;

CREATE VIEW quiz_runtime_v1.answers AS
SELECT a.theme_id, a.topic_key, a.difficulty, a.question_id, a.locale,
    a.kind, a.answer_position, a.answer_text
FROM quiz_answers a JOIN quiz_runtime_v1.question_keys q
ON q.theme_id = a.theme_id AND q.topic_key = a.topic_key
AND q.difficulty = a.difficulty AND q.question_id = a.question_id;

-- Instructions survive retirement/deletion of their theme; no FK or active filter.
CREATE VIEW quiz_runtime_v1.solution_prompts AS
SELECT theme, prompt_key, prompt_text, updated_at
FROM ai_prompts WHERE prompt_key = 'question_solution';

REVOKE ALL ON ALL TABLES IN SCHEMA quiz_runtime_v1 FROM PUBLIC;
