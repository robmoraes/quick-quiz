-- Additive discovery metadata; existing runtime view shapes and contract stay v1.
-- Publication remains atomic and tags do not change gameplay revisions.
CREATE VIEW quiz_runtime_v1.topic_tags AS
SELECT tags.theme_id, tags.topic_key, tags.tag_slug
FROM quiz_topic_tags tags
JOIN quiz_runtime_v1.topics topic
    ON topic.theme_id = tags.theme_id AND topic.topic_key = tags.topic_key;

-- Existing readers receive access only through explicit operator reconciliation.
REVOKE ALL ON quiz_runtime_v1.topic_tags FROM PUBLIC;
