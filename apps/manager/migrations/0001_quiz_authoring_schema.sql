CREATE TABLE quiz_themes (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    weight INTEGER NOT NULL,
    active BOOLEAN NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    CONSTRAINT quiz_themes_id_not_blank CHECK (btrim(id) <> ''),
    CONSTRAINT quiz_themes_name_not_blank CHECK (btrim(name) <> '')
);

CREATE INDEX quiz_themes_catalog_order_idx
    ON quiz_themes (active, weight, id);

CREATE TABLE quiz_topics (
    theme_id TEXT NOT NULL,
    topic_key TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    weight INTEGER NOT NULL,
    active BOOLEAN NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (theme_id, topic_key),
    CONSTRAINT quiz_topics_theme_fk
        FOREIGN KEY (theme_id) REFERENCES quiz_themes (id) ON DELETE CASCADE,
    CONSTRAINT quiz_topics_key_not_blank CHECK (btrim(topic_key) <> ''),
    CONSTRAINT quiz_topics_name_not_blank CHECK (btrim(name) <> '')
);

CREATE INDEX quiz_topics_catalog_order_idx
    ON quiz_topics (theme_id, active, weight, topic_key);

CREATE TABLE quiz_topic_translations (
    theme_id TEXT NOT NULL,
    topic_key TEXT NOT NULL,
    locale TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    updated_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (theme_id, topic_key, locale),
    CONSTRAINT quiz_topic_translations_topic_fk
        FOREIGN KEY (theme_id, topic_key)
        REFERENCES quiz_topics (theme_id, topic_key)
        ON DELETE CASCADE,
    CONSTRAINT quiz_topic_translations_locale_not_blank CHECK (btrim(locale) <> ''),
    CONSTRAINT quiz_topic_translations_name_not_blank CHECK (btrim(name) <> '')
);

CREATE INDEX quiz_topic_translations_locale_idx
    ON quiz_topic_translations (locale, theme_id, topic_key);

CREATE TABLE quiz_questions (
    theme_id TEXT NOT NULL,
    topic_key TEXT NOT NULL,
    difficulty SMALLINT NOT NULL,
    question_id TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (theme_id, topic_key, difficulty, question_id),
    CONSTRAINT quiz_questions_topic_fk
        FOREIGN KEY (theme_id, topic_key)
        REFERENCES quiz_topics (theme_id, topic_key)
        ON DELETE CASCADE,
    CONSTRAINT quiz_questions_difficulty_range CHECK (difficulty BETWEEN 1 AND 4),
    CONSTRAINT quiz_questions_id_not_blank CHECK (btrim(question_id) <> '')
);

CREATE TABLE quiz_question_translations (
    theme_id TEXT NOT NULL,
    topic_key TEXT NOT NULL,
    difficulty SMALLINT NOT NULL,
    question_id TEXT NOT NULL,
    locale TEXT NOT NULL,
    prompt TEXT NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (theme_id, topic_key, difficulty, question_id, locale),
    CONSTRAINT quiz_question_translations_question_fk
        FOREIGN KEY (theme_id, topic_key, difficulty, question_id)
        REFERENCES quiz_questions (theme_id, topic_key, difficulty, question_id)
        ON DELETE CASCADE,
    CONSTRAINT quiz_question_translations_locale_not_blank CHECK (btrim(locale) <> ''),
    CONSTRAINT quiz_question_translations_prompt_not_blank CHECK (btrim(prompt) <> '')
);

CREATE INDEX quiz_question_translations_locale_idx
    ON quiz_question_translations (locale, theme_id, topic_key, difficulty, question_id);

CREATE TABLE quiz_answers (
    theme_id TEXT NOT NULL,
    topic_key TEXT NOT NULL,
    difficulty SMALLINT NOT NULL,
    question_id TEXT NOT NULL,
    locale TEXT NOT NULL,
    kind TEXT NOT NULL,
    answer_position INTEGER NOT NULL,
    answer_text TEXT NOT NULL,
    PRIMARY KEY (theme_id, topic_key, difficulty, question_id, locale, kind, answer_position),
    CONSTRAINT quiz_answers_translation_fk
        FOREIGN KEY (theme_id, topic_key, difficulty, question_id, locale)
        REFERENCES quiz_question_translations (theme_id, topic_key, difficulty, question_id, locale)
        ON DELETE CASCADE,
    CONSTRAINT quiz_answers_kind CHECK (kind IN ('correct', 'wrong')),
    CONSTRAINT quiz_answers_position_non_negative CHECK (answer_position >= 0),
    CONSTRAINT quiz_answers_text_not_blank CHECK (btrim(answer_text) <> '')
);

CREATE TABLE quiz_catalog_state (
    singleton SMALLINT PRIMARY KEY DEFAULT 1,
    current_revision BIGINT NOT NULL DEFAULT 0,
    published_revision BIGINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT quiz_catalog_state_singleton CHECK (singleton = 1),
    CONSTRAINT quiz_catalog_state_current_non_negative CHECK (current_revision >= 0),
    CONSTRAINT quiz_catalog_state_published_non_negative CHECK (published_revision >= 0),
    CONSTRAINT quiz_catalog_state_publication_order CHECK (published_revision <= current_revision)
);

INSERT INTO quiz_catalog_state (singleton) VALUES (1);

CREATE TABLE quiz_publications (
    id BIGSERIAL PRIMARY KEY,
    revision BIGINT NOT NULL,
    affected_theme_id TEXT NULL,
    status TEXT NOT NULL,
    content_checksum CHAR(64) NULL,
    object_count INTEGER NULL,
    failure_summary TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMPTZ NULL,
    finished_at TIMESTAMPTZ NULL,
    CONSTRAINT quiz_publications_revision_non_negative CHECK (revision >= 0),
    CONSTRAINT quiz_publications_status CHECK (status IN ('pending', 'published', 'failed')),
    CONSTRAINT quiz_publications_checksum_length CHECK (
        content_checksum IS NULL OR char_length(content_checksum) = 64
    ),
    CONSTRAINT quiz_publications_object_count_non_negative CHECK (
        object_count IS NULL OR object_count >= 0
    )
);

CREATE INDEX quiz_publications_revision_idx
    ON quiz_publications (revision DESC, id DESC);

CREATE INDEX quiz_publications_status_idx
    ON quiz_publications (status, created_at DESC);
