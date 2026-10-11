package main

import (
	"context"
	"encoding/json"

	"github.com/jackc/pgx/v5"
)

func queryPlans(ctx context.Context, cfg *pgx.ConnConfig) (map[string]json.RawMessage, error) {
	conn, err := pgx.ConnectConfig(ctx, cfg)
	if err != nil {
		return nil, safeError("read-only plan connection")
	}
	defer conn.Close(ctx)
	var base, views, temporary bool
	err = conn.QueryRow(ctx, `SELECT has_table_privilege(current_user,'public.quiz_questions','SELECT'),has_table_privilege(current_user,'quiz_runtime_v1.question_keys','SELECT'),has_database_privilege(current_user,current_database(),'TEMP')`).Scan(&base, &views, &temporary)
	if err != nil || base || !views || temporary {
		return nil, safeError("read-only fixture role privileges")
	}
	out := map[string]json.RawMessage{}
	queries := []struct {
		name, query string
		args        []any
	}{
		{"candidate_ids_hot_10000_limit_10", `SELECT question_id FROM quiz_runtime_v1.question_keys WHERE theme_id=$1 AND topic_key=$2 AND difficulty=$3 AND NOT(question_id=ANY($4::text[])) ORDER BY random() LIMIT $5`, []any{"bench-001", "topic-01", 1, []string{}, 10}},
		{"catalog_counts_with_consumed_id", `WITH consumed AS (SELECT DISTINCT topic,difficulty,question_id FROM jsonb_to_recordset($2::jsonb) AS x(topic text,difficulty smallint,question_id text)) SELECT q.topic_key,q.difficulty,COUNT(*)::integer,COUNT(*) FILTER(WHERE c.question_id IS NULL)::integer FROM quiz_runtime_v1.question_keys q LEFT JOIN consumed c ON c.topic=q.topic_key AND c.difficulty=q.difficulty AND c.question_id=q.question_id WHERE q.theme_id=$1 GROUP BY q.topic_key,q.difficulty`, []any{"bench-001", `[{"topic":"topic-01","difficulty":1,"question_id":"hot-0000001"}]`}},
		{"hydrate_10_packages_two_locales", `SELECT q.question_id,q.locale,q.prompt,a.kind,a.answer_position,a.answer_text FROM quiz_runtime_v1.question_translations q LEFT JOIN quiz_runtime_v1.answers a ON a.theme_id=q.theme_id AND a.topic_key=q.topic_key AND a.difficulty=q.difficulty AND a.question_id=q.question_id AND a.locale=q.locale WHERE q.theme_id=$1 AND q.topic_key=$2 AND q.difficulty=$3 AND q.question_id=ANY($4::text[]) AND q.locale=ANY($5::text[]) ORDER BY q.question_id,q.locale,a.kind,a.answer_position`, []any{"bench-001", "topic-01", 1, []string{"hot-0000001", "hot-0000002", "hot-0000003", "hot-0000004", "hot-0000005", "hot-0000006", "hot-0000007", "hot-0000008", "hot-0000009", "hot-0000010"}, []string{"en-US", "pt-BR"}}},
	}
	for _, q := range queries {
		var raw []byte
		if err := conn.QueryRow(ctx, "EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) "+q.query, q.args...).Scan(&raw); err != nil {
			return out, safeError("query plan " + q.name)
		}
		out[q.name] = json.RawMessage(raw)
	}
	return out, nil
}
