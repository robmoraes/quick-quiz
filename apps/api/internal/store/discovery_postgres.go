package store

import (
	"context"
	"encoding/json"
	"strings"

	"github.com/jackc/pgx/v5"
	"quickquiz/api/internal/domain"
)

// Aggregates are performed in PostgreSQL over canonical keys, not question or
// answer payloads. The result limit applies before JSON reaches the API process.
// All supplied filters intersect, including filters used to construct tag facets.
const discoveryCTE = `WITH base_themes AS (
 SELECT t.* FROM quiz_runtime_v1.themes t
 WHERE ($4::text='' OR t.id=$4) AND ($5::text='' OR $5=ANY(t.tags))
 AND (NOT $9::boolean OR $3::text='' OR t.name ILIKE ('%'||$3||'%') ESCAPE E'\\' OR t.description ILIKE ('%'||$3||'%') ESCAPE E'\\')
), topic_metadata AS (
 SELECT t.theme_id,t.topic_key,COALESCE(l.name,f.name,t.name) AS label,
 COALESCE(l.description,f.description,t.description) AS description,t.weight,
 ARRAY(SELECT tag_slug FROM quiz_runtime_v1.topic_tags tags WHERE tags.theme_id=t.theme_id AND tags.topic_key=t.topic_key ORDER BY tag_slug COLLATE "C") AS tags
 FROM quiz_runtime_v1.topics t JOIN base_themes parent ON parent.id=t.theme_id
 LEFT JOIN quiz_runtime_v1.topic_translations l ON l.theme_id=t.theme_id AND l.topic_key=t.topic_key AND l.locale=$1
 LEFT JOIN quiz_runtime_v1.topic_translations f ON f.theme_id=t.theme_id AND f.topic_key=t.topic_key AND f.locale=$2
 WHERE ($8::text='' OR t.topic_key=$8)
 AND ($6::text='' OR EXISTS(SELECT 1 FROM quiz_runtime_v1.topic_tags tags WHERE tags.theme_id=t.theme_id AND tags.topic_key=t.topic_key AND tags.tag_slug=$6))
 AND ($9::boolean OR $3::text='' OR COALESCE(l.name,f.name,t.name) ILIKE ('%'||$3||'%') ESCAPE E'\\' OR COALESCE(l.description,f.description,t.description) ILIKE ('%'||$3||'%') ESCAPE E'\\')
), question_counts AS (
 SELECT q.theme_id,q.topic_key,q.difficulty,COUNT(*)::bigint AS question_count
 FROM quiz_runtime_v1.question_keys q JOIN topic_metadata t ON t.theme_id=q.theme_id AND t.topic_key=q.topic_key
 WHERE ($7::integer=0 OR q.difficulty=$7) GROUP BY q.theme_id,q.topic_key,q.difficulty
), topic_counts AS (
 SELECT theme_id,topic_key,SUM(question_count)::bigint AS question_count,
 jsonb_agg(jsonb_build_object('id',difficulty,'questionCount',question_count) ORDER BY difficulty) AS difficulties
 FROM question_counts GROUP BY theme_id,topic_key
), eligible_topics AS (
 SELECT t.*,COALESCE(c.question_count,0)::bigint AS question_count,COALESCE(c.difficulties,'[]'::jsonb) AS difficulties
 FROM topic_metadata t LEFT JOIN topic_counts c ON c.theme_id=t.theme_id AND c.topic_key=t.topic_key
 WHERE ($7::integer=0 OR c.question_count>0)
), eligible_themes AS (
 SELECT t.id,t.name,t.description,t.weight,t.tags,COUNT(top.topic_key)::bigint AS topic_count,
 COUNT(top.topic_key) FILTER(WHERE top.question_count>0)::bigint AS playable_topic_count,
 COALESCE(SUM(top.question_count),0)::bigint AS question_count
 FROM base_themes t LEFT JOIN eligible_topics top ON top.theme_id=t.id
 GROUP BY t.id,t.name,t.description,t.weight,t.tags
 HAVING ($6::text='' AND $7::integer=0) OR COUNT(top.topic_key)>0
) `

const discoveryThemeJSON = `jsonb_build_object(
 'id',e.id,'name',e.name,'description',e.description,'weight',e.weight,'tags',e.tags,
 'topicCount',e.topic_count,'playableTopicCount',e.playable_topic_count,'questionCount',e.question_count,'playable',e.question_count>0,
 'difficulties',COALESCE((SELECT jsonb_agg(jsonb_build_object('id',d.difficulty,'questionCount',d.question_count) ORDER BY d.difficulty)
 FROM (SELECT q.difficulty,SUM(q.question_count)::bigint AS question_count FROM question_counts q
 JOIN eligible_topics top ON top.theme_id=q.theme_id AND top.topic_key=q.topic_key WHERE q.theme_id=e.id GROUP BY q.difficulty) d),'[]'::jsonb))`

const discoveryTopicJSON = `jsonb_build_object(
 'theme',jsonb_build_object('id',parent.id,'name',parent.name,'description',parent.description,'weight',parent.weight,'tags',parent.tags),
 'id',e.topic_key,'label',e.label,'description',e.description,'weight',e.weight,'tags',e.tags,
 'questionCount',e.question_count,'difficulties',e.difficulties,'playable',e.question_count>0)`

func (s *PostgresQuestionStore) discoveryArgs(query domain.DiscoveryQuery, themeSearch bool) []any {
	// Escape LIKE's metacharacters before adding the surrounding substring wildcards.
	search := strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(query.Search)
	return []any{query.Locale, s.locales.Fallback(), search, query.Theme, query.ThemeTag, query.TopicTag, int(query.Difficulty), query.Topic, themeSearch}
}

func discoveryPagination(query domain.DiscoveryQuery, total int64) domain.DiscoveryPagination {
	return domain.DiscoveryPagination{Page: query.Page, PageSize: query.PageSize, Total: total, TotalPages: (total + int64(query.PageSize) - 1) / int64(query.PageSize)}
}

func (s *PostgresQuestionStore) DiscoverThemes(ctx context.Context, query domain.DiscoveryQuery) (domain.DiscoveryThemePage, error) {
	result := domain.DiscoveryThemePage{Locale: query.Locale, FallbackLocale: s.locales.Fallback(), Themes: []domain.DiscoveryTheme{}}
	err := s.read(ctx, true, func(ctx context.Context, tx pgx.Tx, _ catalogState) error {
		args := append(s.discoveryArgs(query, true), query.PageSize, (query.Page-1)*query.PageSize)
		var total int64
		var payload []byte
		err := tx.QueryRow(ctx, discoveryCTE+`SELECT (SELECT COUNT(*) FROM eligible_themes),
 COALESCE((SELECT jsonb_agg(item ORDER BY weight,id COLLATE "C") FROM (
 SELECT e.weight,e.id,`+discoveryThemeJSON+` AS item FROM eligible_themes e ORDER BY e.weight,e.id COLLATE "C" LIMIT $10 OFFSET $11
 ) page),'[]'::jsonb)`, args...).Scan(&total, &payload)
		if err != nil {
			return pgUnavailable("theme discovery")
		}
		if err = json.Unmarshal(payload, &result.Themes); err != nil {
			return pgUnavailable("discovery decode")
		}
		result.Pagination = discoveryPagination(query, total)
		return nil
	})
	return result, err
}

func (s *PostgresQuestionStore) DiscoveryTheme(ctx context.Context, query domain.DiscoveryQuery) (domain.DiscoveryThemeDetail, error) {
	result := domain.DiscoveryThemeDetail{Locale: query.Locale, FallbackLocale: s.locales.Fallback()}
	err := s.read(ctx, true, func(ctx context.Context, tx pgx.Tx, _ catalogState) error {
		var payload []byte
		err := tx.QueryRow(ctx, discoveryCTE+`SELECT (SELECT `+discoveryThemeJSON+` FROM eligible_themes e)`, s.discoveryArgs(query, true)...).Scan(&payload)
		if err != nil {
			return pgUnavailable("theme detail")
		}
		if payload == nil {
			return domain.ErrThemeNotFound
		}
		if err = json.Unmarshal(payload, &result.Theme); err != nil {
			return pgUnavailable("discovery decode")
		}
		return nil
	})
	return result, err
}

func (s *PostgresQuestionStore) DiscoverTopics(ctx context.Context, query domain.DiscoveryQuery) (domain.DiscoveryTopicPage, error) {
	result := domain.DiscoveryTopicPage{Locale: query.Locale, FallbackLocale: s.locales.Fallback(), Topics: []domain.DiscoveryTopic{}}
	err := s.read(ctx, true, func(ctx context.Context, tx pgx.Tx, _ catalogState) error {
		args := append(s.discoveryArgs(query, false), query.PageSize, (query.Page-1)*query.PageSize)
		var total int64
		var payload []byte
		err := tx.QueryRow(ctx, discoveryCTE+`SELECT (SELECT COUNT(*) FROM eligible_topics),
 COALESCE((SELECT jsonb_agg(item ORDER BY weight,theme_id COLLATE "C",topic_key COLLATE "C") FROM (
 SELECT e.weight,e.theme_id,e.topic_key,`+discoveryTopicJSON+` AS item FROM eligible_topics e JOIN base_themes parent ON parent.id=e.theme_id
 ORDER BY e.weight,e.theme_id COLLATE "C",e.topic_key COLLATE "C" LIMIT $10 OFFSET $11) page),'[]'::jsonb)`, args...).Scan(&total, &payload)
		if err != nil {
			return pgUnavailable("topic discovery")
		}
		if err = json.Unmarshal(payload, &result.Topics); err != nil {
			return pgUnavailable("discovery decode")
		}
		result.Pagination = discoveryPagination(query, total)
		return nil
	})
	return result, err
}

func (s *PostgresQuestionStore) DiscoveryTopic(ctx context.Context, query domain.DiscoveryQuery) (domain.DiscoveryTopicDetail, error) {
	result := domain.DiscoveryTopicDetail{Locale: query.Locale, FallbackLocale: s.locales.Fallback()}
	err := s.read(ctx, true, func(ctx context.Context, tx pgx.Tx, _ catalogState) error {
		var parentExists bool
		var payload []byte
		err := tx.QueryRow(ctx, discoveryCTE+`SELECT EXISTS(SELECT 1 FROM base_themes),
 (SELECT `+discoveryTopicJSON+` FROM eligible_topics e JOIN base_themes parent ON parent.id=e.theme_id)`, s.discoveryArgs(query, false)...).Scan(&parentExists, &payload)
		if err != nil {
			return pgUnavailable("topic detail")
		}
		if !parentExists {
			return domain.ErrThemeNotFound
		}
		if payload == nil {
			return domain.ErrTopicNotFound
		}
		if err = json.Unmarshal(payload, &result.Topic); err != nil {
			return pgUnavailable("discovery decode")
		}
		return nil
	})
	return result, err
}

func (s *PostgresQuestionStore) DiscoverTags(ctx context.Context, query domain.DiscoveryQuery) (domain.DiscoveryTagPage, error) {
	result := domain.DiscoveryTagPage{Locale: query.Locale, FallbackLocale: s.locales.Fallback(), Scope: query.Scope, Tags: []domain.DiscoveryTag{}}
	err := s.read(ctx, true, func(ctx context.Context, tx pgx.Tx, _ catalogState) error {
		args := append(s.discoveryArgs(query, query.Scope == "theme"), query.PageSize, (query.Page-1)*query.PageSize)
		// These fragments are constants selected by the validated association scope,
		// never SQL interpolated from a user parameter.
		facets := `SELECT tag.slug,COUNT(DISTINCT e.theme_id)::bigint AS theme_count,COUNT(*)::bigint AS topic_count,SUM(e.question_count)::bigint AS question_count
 FROM eligible_topics e CROSS JOIN LATERAL unnest(e.tags) tag(slug) GROUP BY tag.slug`
		if query.Scope == "theme" {
			facets = `SELECT tag.slug,COUNT(*)::bigint AS theme_count,SUM(e.topic_count)::bigint AS topic_count,SUM(e.question_count)::bigint AS question_count
 FROM eligible_themes e CROSS JOIN LATERAL unnest(e.tags) tag(slug) GROUP BY tag.slug`
		}
		var total int64
		var payload []byte
		err := tx.QueryRow(ctx, discoveryCTE+`, facets AS (`+facets+`)
 SELECT (SELECT COUNT(*) FROM facets),COALESCE((SELECT jsonb_agg(item ORDER BY slug COLLATE "C") FROM (
 SELECT slug,jsonb_build_object('slug',slug,'themeCount',theme_count,'topicCount',topic_count,'questionCount',question_count) AS item
 FROM facets ORDER BY slug COLLATE "C" LIMIT $10 OFFSET $11) page),'[]'::jsonb)`, args...).Scan(&total, &payload)
		if err != nil {
			return pgUnavailable("tag discovery")
		}
		if err = json.Unmarshal(payload, &result.Tags); err != nil {
			return pgUnavailable("discovery decode")
		}
		result.Pagination = discoveryPagination(query, total)
		return nil
	})
	return result, err
}
