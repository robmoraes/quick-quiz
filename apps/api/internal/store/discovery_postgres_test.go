package store_test

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/jackc/pgx/v5"
	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/store"
)

// Runs inside the existing isolated runtime integration fixture, not against the
// local preview bank. This avoids a second destructive schema-resetting test.
func testPostgresDiscovery(t *testing.T, ctx context.Context, admin *pgx.Conn, reader *store.PostgresQuestionStore) {
	t.Helper()
	seed := `
 INSERT INTO quiz_themes(id,name,description,weight,active,created_at,updated_at) VALUES
 ('web-one','One canonical theme','Alpha',20,true,now(),now()),('web-two','Two canonical theme','Beta',20,true,now(),now()),
 ('web-empty','Empty theme','No topics',30,true,now(),now()),('web-retired','Retired theme','Hidden',5,false,now(),now());
 INSERT INTO quiz_tags(slug) VALUES('web'),('web-theme-shared'),('web-security'),('web-network'),('web-empty-tag'),('web-only-draft'),('web-orphan'),('web-retired-tag');
 INSERT INTO quiz_theme_tags(theme_id,tag_slug) VALUES('web-one','web'),('web-one','web-theme-shared'),('web-two','web'),('web-empty','web'),('web-retired','web'),('web-retired','web-retired-tag');
 INSERT INTO quiz_topics(theme_id,topic_key,name,description,weight,active,created_at,updated_at) VALUES
 ('web-one','shared','Canonical shared','Canonical fallback',2,true,now(),now()),('web-two','shared','Second canonical','Canonical second fallback',2,true,now(),now()),
 ('web-one','empty','Empty 100%_topic','Literal percentage',2,true,now(),now()),('web-one','inactive','Hidden topic','Hidden',1,false,now(),now()),
 ('web-retired','shared','Hidden parent topic','Hidden',1,true,now(),now());
 INSERT INTO quiz_topic_translations(theme_id,topic_key,locale,name,description,updated_at) VALUES
 ('web-one','shared','pt-BR','Segurança em redes','',now()),('web-one','shared','en-US','Network security','Fallback description',now()),
 ('web-two','shared','en-US','Security basics','Concepts',now());
 INSERT INTO quiz_topic_tags(theme_id,topic_key,tag_slug) VALUES
 ('web-one','shared','web-security'),('web-one','shared','web-network'),('web-two','shared','web-security'),('web-one','empty','web-empty-tag'),
 ('web-one','inactive','web-security'),('web-one','inactive','web-only-draft'),('web-retired','shared','web-security'),('web-retired','shared','web-retired-tag');
 INSERT INTO quiz_questions(theme_id,topic_key,difficulty,question_id,created_at,updated_at) VALUES
 ('web-one','shared',1,'same-q1',now(),now()),('web-one','shared',1,'q2',now(),now()),('web-one','shared',3,'hard-q1',now(),now()),
 ('web-two','shared',1,'same-q1',now(),now()),('web-one','inactive',1,'hidden-q1',now(),now()),('web-retired','shared',1,'hidden-q1',now(),now());
 INSERT INTO quiz_question_translations(theme_id,topic_key,difficulty,question_id,locale,prompt,updated_at)
 SELECT theme_id,topic_key,difficulty,question_id,l,'Synthetic discovery question',now() FROM quiz_questions CROSS JOIN LATERAL unnest(ARRAY['en-US','pt-BR']) l WHERE theme_id LIKE 'web-%';
 `
	if _, err := admin.Exec(ctx, seed); err != nil {
		t.Fatal(err)
	}
	base := domain.DiscoveryQuery{Locale: "pt-BR", ThemeTag: "web", Page: 1, PageSize: 24}

	t.Run("active empty themes and exact canonical aggregates", func(t *testing.T) {
		before := reader.QueryCount()
		page, err := reader.DiscoverThemes(ctx, base)
		if err != nil {
			t.Fatal(err)
		}
		if len(page.Themes) != 3 || page.Pagination.Total != 3 || page.Pagination.TotalPages != 1 {
			t.Fatalf("page=%+v", page)
		}
		one, two, empty := page.Themes[0], page.Themes[1], page.Themes[2]
		if one.ID != "web-one" || two.ID != "web-two" || empty.ID != "web-empty" {
			t.Fatalf("unstable order=%+v", page.Themes)
		}
		if one.TopicCount != 2 || one.PlayableTopicCount != 1 || one.QuestionCount != 3 || len(one.Difficulties) != 2 || one.Difficulties[0].QuestionCount != 2 || one.Difficulties[1].ID != 3 {
			t.Fatalf("canonical counts=%+v", one)
		}
		if two.QuestionCount != 1 || empty.QuestionCount != 0 || empty.TopicCount != 0 || empty.Playable || empty.Difficulties == nil {
			t.Fatalf("empty/counts=%+v %+v", two, empty)
		}
		if calls := reader.QueryCount() - before; calls > 6 {
			t.Fatalf("discovery query amplification: %d", calls)
		}
	})
	t.Run("intersection and difficulty scope", func(t *testing.T) {
		query := base
		query.TopicTag = "web-security"
		query.Difficulty = 1
		page, err := reader.DiscoverThemes(ctx, query)
		if err != nil || len(page.Themes) != 2 || page.Themes[0].TopicCount != 1 || page.Themes[0].QuestionCount != 2 || len(page.Themes[0].Difficulties) != 1 || page.Themes[1].QuestionCount != 1 {
			t.Fatalf("filtered=%+v err=%v", page, err)
		}
		query.ThemeTag = "not-known"
		page, err = reader.DiscoverThemes(ctx, query)
		if err != nil || len(page.Themes) != 0 || page.Themes == nil || page.Pagination.Total != 0 {
			t.Fatalf("unknown=%+v %v", page, err)
		}
	})
	t.Run("localized search fallback literals and parent identity", func(t *testing.T) {
		query := base
		query.Search = "SEGURANÇA"
		page, err := reader.DiscoverTopics(ctx, query)
		if err != nil || len(page.Topics) != 1 || page.Topics[0].Theme.ID != "web-one" || page.Topics[0].Label != "Segurança em redes" || page.Topics[0].Description != "" {
			t.Fatalf("translation=%+v %v", page, err)
		}
		query.Search = "security"
		page, err = reader.DiscoverTopics(ctx, query)
		if err != nil || len(page.Topics) != 1 || page.Topics[0].Theme.ID != "web-two" || page.Topics[0].Label != "Security basics" {
			t.Fatalf("fallback=%+v %v", page, err)
		}
		query.Search = "100%_"
		page, err = reader.DiscoverTopics(ctx, query)
		if err != nil || len(page.Topics) != 1 || page.Topics[0].ID != "empty" || page.Topics[0].Playable || page.Topics[0].Difficulties == nil {
			t.Fatalf("literal search=%+v %v", page, err)
		}
		query.Search = "%_notpresent"
		page, err = reader.DiscoverTopics(ctx, query)
		if err != nil || len(page.Topics) != 0 {
			t.Fatalf("wildcards expanded=%+v %v", page, err)
		}
		query.Search = "One canonical"
		themes, err := reader.DiscoverThemes(ctx, query)
		if err != nil || len(themes.Themes) != 1 || themes.Themes[0].ID != "web-one" {
			t.Fatalf("theme search=%+v %v", themes, err)
		}
	})
	t.Run("bounded stable pagination and empty out of range page", func(t *testing.T) {
		query := base
		query.PageSize = 1
		query.Page = 2
		page, err := reader.DiscoverTopics(ctx, query)
		if err != nil || len(page.Topics) != 1 || page.Topics[0].ID != "shared" || page.Topics[0].Theme.ID != "web-one" || page.Pagination.Total != 3 || page.Pagination.TotalPages != 3 {
			t.Fatalf("pagination=%+v %v", page, err)
		}
		query.Page = 1000
		page, err = reader.DiscoverTopics(ctx, query)
		if err != nil || len(page.Topics) != 0 || page.Topics == nil || page.Pagination.Total != 3 || page.Pagination.Page != 1000 {
			t.Fatalf("beyond end=%+v %v", page, err)
		}
	})
	t.Run("detail empty resources and missing inactive resources", func(t *testing.T) {
		query := domain.DiscoveryQuery{Locale: "pt-BR", Theme: "web-empty", Page: 1, PageSize: 24}
		theme, err := reader.DiscoveryTheme(ctx, query)
		if err != nil || theme.Theme.Playable || theme.Theme.QuestionCount != 0 || theme.Theme.Difficulties == nil {
			t.Fatalf("empty detail=%+v %v", theme, err)
		}
		query.Theme = "web-one"
		query.Topic = "empty"
		topic, err := reader.DiscoveryTopic(ctx, query)
		if err != nil || topic.Topic.Playable || topic.Topic.Label != "Empty 100%_topic" {
			t.Fatalf("empty topic=%+v %v", topic, err)
		}
		query.Topic = "shared"
		one, err := reader.DiscoveryTopic(ctx, query)
		if err != nil {
			t.Fatal(err)
		}
		query.Theme = "web-two"
		two, err := reader.DiscoveryTopic(ctx, query)
		if err != nil || one.Topic.QuestionCount != 3 || two.Topic.QuestionCount != 1 || one.Topic.Theme.ID == two.Topic.Theme.ID {
			t.Fatalf("parent collision=%+v %+v %v", one, two, err)
		}
		query.Theme = "web-one"
		query.Topic = "inactive"
		if _, err := reader.DiscoveryTopic(ctx, query); !errors.Is(err, domain.ErrTopicNotFound) {
			t.Fatalf("inactive topic=%v", err)
		}
		query.Theme = "web-retired"
		query.Topic = "shared"
		if _, err := reader.DiscoveryTopic(ctx, query); !errors.Is(err, domain.ErrThemeNotFound) {
			t.Fatalf("inactive parent=%v", err)
		}
		if _, err := reader.DiscoveryTheme(ctx, query); !errors.Is(err, domain.ErrThemeNotFound) {
			t.Fatalf("inactive theme=%v", err)
		}
	})
	t.Run("separate tag scopes intersecting facets and hidden orphans", func(t *testing.T) {
		query := base
		query.Scope = "topic"
		page, err := reader.DiscoverTags(ctx, query)
		if err != nil || len(page.Tags) != 3 {
			t.Fatalf("tags=%+v %v", page, err)
		}
		if page.Tags[0].Slug != "web-empty-tag" || page.Tags[1].Slug != "web-network" || page.Tags[2].Slug != "web-security" {
			t.Fatalf("tag order=%+v", page.Tags)
		}
		security := page.Tags[2]
		if security.ThemeCount != 2 || security.TopicCount != 2 || security.QuestionCount != 4 {
			t.Fatalf("facet counts=%+v", security)
		}
		query.TopicTag = "web-network"
		page, err = reader.DiscoverTags(ctx, query)
		if err != nil || len(page.Tags) != 2 || page.Tags[1].Slug != "web-security" || page.Tags[1].ThemeCount != 1 || page.Tags[1].QuestionCount != 3 {
			t.Fatalf("self filter ignored=%+v %v", page, err)
		}
		query = base
		query.Scope = "theme"
		page, err = reader.DiscoverTags(ctx, query)
		if err != nil || len(page.Tags) != 2 || page.Tags[0].Slug != "web" || page.Tags[0].ThemeCount != 3 || page.Tags[0].TopicCount != 3 || page.Tags[0].QuestionCount != 4 {
			t.Fatalf("theme scopes=%+v %v", page, err)
		}
		query.TopicTag = "web-security"
		query.Difficulty = 1
		page, err = reader.DiscoverTags(ctx, query)
		if err != nil || page.Tags[0].ThemeCount != 2 || page.Tags[0].TopicCount != 2 || page.Tags[0].QuestionCount != 3 {
			t.Fatalf("scoped facets=%+v %v", page, err)
		}
	})
	t.Run("topic tag commit visible without gameplay revision", func(t *testing.T) {
		var before, after int64
		if err := admin.QueryRow(ctx, `SELECT current_revision FROM quiz_catalog_state`).Scan(&before); err != nil {
			t.Fatal(err)
		}
		if _, err := admin.Exec(ctx, `INSERT INTO quiz_topic_tags(theme_id,topic_key,tag_slug) VALUES('web-one','empty','web-security')`); err != nil {
			t.Fatal(err)
		}
		query := base
		query.TopicTag = "web-security"
		page, err := reader.DiscoverTopics(ctx, query)
		if err != nil || len(page.Topics) != 3 {
			t.Fatalf("fresh tags=%+v %v", page, err)
		}
		if err := admin.QueryRow(ctx, `SELECT current_revision FROM quiz_catalog_state`).Scan(&after); err != nil {
			t.Fatal(err)
		}
		if before != after {
			t.Fatal("tags unexpectedly advanced revision")
		}
	})
	t.Run("metadata payload omits prompts answers and session information", func(t *testing.T) {
		page, err := reader.DiscoverTopics(ctx, base)
		if err != nil {
			t.Fatal(err)
		}
		payload, _ := json.Marshal(page)
		for _, forbidden := range []string{"Synthetic discovery question", "prompt", "correctOptions", "wrongOptions", "answer_text", "sessionId", "runId"} {
			if strings.Contains(string(payload), forbidden) {
				t.Fatalf("discovery leaked %s", forbidden)
			}
		}
	})
	t.Run("topic tag view permission and type failures fail readiness", func(t *testing.T) {
		if _, err := admin.Exec(ctx, `REVOKE SELECT ON quiz_runtime_v1.topic_tags FROM runtime_reader_test`); err != nil {
			t.Fatal(err)
		}
		if err := reader.Ready(ctx); !errors.Is(err, domain.ErrStorageUnavailable) {
			t.Fatal("missing tag grant was ready", err)
		}
		if _, err := reader.DiscoverTopics(ctx, base); !errors.Is(err, domain.ErrStorageUnavailable) {
			t.Fatal("missing tag grant became empty", err)
		}
		if _, err := admin.Exec(ctx, `GRANT SELECT ON quiz_runtime_v1.topic_tags TO runtime_reader_test;
 ALTER VIEW quiz_runtime_v1.topic_tags RENAME TO topic_tags_valid;
 CREATE VIEW quiz_runtime_v1.topic_tags AS SELECT theme_id,topic_key,1::integer AS tag_slug FROM quiz_topic_tags WHERE false;
 GRANT SELECT ON quiz_runtime_v1.topic_tags TO runtime_reader_test;`); err != nil {
			t.Fatal(err)
		}
		t.Cleanup(func() {
			if _, err := admin.Exec(ctx, `DROP VIEW quiz_runtime_v1.topic_tags;ALTER VIEW quiz_runtime_v1.topic_tags_valid RENAME TO topic_tags;`); err != nil {
				t.Error(err)
			}
		})
		if err := reader.Ready(ctx); !errors.Is(err, domain.ErrStorageUnavailable) {
			t.Fatal("incompatible empty tag view was ready", err)
		}
	})
}
