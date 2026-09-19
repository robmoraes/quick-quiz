CREATE TABLE quiz_tags (
    slug TEXT PRIMARY KEY,
    CONSTRAINT quiz_tag_slug_valid CHECK (
        char_length(slug) BETWEEN 1 AND 50
        AND slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'
    )
);

CREATE TABLE quiz_topic_tags (
    theme_id TEXT NOT NULL,
    topic_key TEXT NOT NULL,
    tag_slug TEXT NOT NULL REFERENCES quiz_tags(slug),
    PRIMARY KEY (theme_id, topic_key, tag_slug),
    FOREIGN KEY (theme_id, topic_key)
        REFERENCES quiz_topics(theme_id, topic_key) ON DELETE CASCADE
);

CREATE INDEX quiz_topic_tags_slug_idx ON quiz_topic_tags(tag_slug);
