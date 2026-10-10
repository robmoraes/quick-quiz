CREATE TABLE quiz_theme_tags (
    theme_id TEXT NOT NULL REFERENCES quiz_themes(id) ON DELETE CASCADE,
    tag_slug TEXT NOT NULL REFERENCES quiz_tags(slug),
    PRIMARY KEY (theme_id, tag_slug)
);

CREATE INDEX quiz_theme_tags_slug_idx ON quiz_theme_tags(tag_slug);
