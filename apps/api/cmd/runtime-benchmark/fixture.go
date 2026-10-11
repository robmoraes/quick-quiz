package main

import (
	"context"
	"os"
	"path/filepath"
	"sort"
	"time"

	"github.com/jackc/pgx/v5"
)

type fixtureInfo struct {
	CanonicalQuestions int64   `json:"canonical_questions"`
	Translations       int64   `json:"translations"`
	Answers            int64   `json:"answers"`
	Themes             int64   `json:"themes"`
	Topics             int64   `json:"topics"`
	HotScope           int64   `json:"hot_scope_questions"`
	DatabaseBytes      int64   `json:"database_bytes"`
	IndexBytes         int64   `json:"index_bytes"`
	PromptBytes        int     `json:"prompt_bytes"`
	AnswerBytes        int     `json:"answer_bytes"`
	SeedSeconds        float64 `json:"seed_seconds,omitempty"`
}

func seedFixture(ctx context.Context, o options) (*fixtureInfo, error) {
	started := time.Now()
	conn, err := pgx.Connect(ctx, o.databaseURL)
	if err != nil {
		return nil, safeError("connect disposable fixture database")
	}
	defer conn.Close(ctx)
	if conn.Config().Database != databaseName {
		return nil, safeError("database guard")
	}
	var exists bool
	if err = conn.QueryRow(ctx, `SELECT to_regclass('public._runtime_benchmark_fixture') IS NOT NULL`).Scan(&exists); err != nil {
		return nil, safeError("fixture guard")
	}
	var previous int
	if exists {
		var marker string
		var promptBytes, answerBytes int
		err = conn.QueryRow(ctx, `SELECT marker,canonical_count,prompt_bytes,answer_bytes FROM _runtime_benchmark_fixture WHERE singleton=1`).Scan(&marker, &previous, &promptBytes, &answerBytes)
		if err != nil || marker != fixtureMarker || promptBytes != o.promptBytes || answerBytes != o.answerBytes || o.questions < previous {
			return nil, safeError("existing fixture identity or extension guard")
		}
	} else {
		var tables int
		err = conn.QueryRow(ctx, `SELECT count(*) FROM pg_tables WHERE schemaname NOT IN('pg_catalog','information_schema')`).Scan(&tables)
		if err != nil || tables != 0 {
			return nil, safeError("refuse nonempty database without synthetic fixture marker")
		}
		paths, err := filepath.Glob(filepath.Join(o.migrationsDir, "*.sql"))
		if err != nil || len(paths) < 4 {
			return nil, safeError("versioned migrations unavailable")
		}
		sort.Strings(paths)
		tx, err := conn.Begin(ctx)
		if err != nil {
			return nil, safeError("begin fixture migrations")
		}
		defer tx.Rollback(ctx)
		for _, path := range paths {
			content, readErr := os.ReadFile(path)
			if readErr != nil {
				return nil, safeError("read versioned migration")
			}
			if _, err = tx.Exec(ctx, string(content)); err != nil {
				return nil, safeError("apply versioned migration " + filepath.Base(path))
			}
		}
		_, err = tx.Exec(ctx, `CREATE TABLE _runtime_benchmark_fixture(singleton integer PRIMARY KEY CHECK(singleton=1),marker text NOT NULL,canonical_count integer NOT NULL,prompt_bytes integer NOT NULL,answer_bytes integer NOT NULL)`)
		if err == nil {
			_, err = tx.Exec(ctx, `INSERT INTO _runtime_benchmark_fixture VALUES(1,'quickquiz-synthetic-runtime-v1',0,$1,$2)`, o.promptBytes, o.answerBytes)
		}
		if err != nil {
			return nil, safeError("create synthetic fixture marker")
		}
		if err = tx.Commit(ctx); err != nil {
			return nil, safeError("commit fixture migrations")
		}
	}

	if previous != o.questions {
		tx, err := conn.Begin(ctx)
		if err != nil {
			return nil, safeError("begin synthetic seed")
		}
		defer tx.Rollback(ctx)
		if previous == 0 {
			if _, err = tx.Exec(ctx, seedMetadataSQL); err != nil {
				return nil, safeError("seed themes and topics")
			}
			if _, err = tx.Exec(ctx, seedBaseKeysSQL); err != nil {
				return nil, safeError("seed 100k canonical identities")
			}
		}
		if o.questions > 100000 {
			first := previous - 100000 + 1
			if first < 1 {
				first = 1
			}
			if _, err = tx.Exec(ctx, seedExtraKeysSQL, first, o.questions-100000); err != nil {
				return nil, safeError("extend unrelated canonical identities")
			}
		}
		if _, err = tx.Exec(ctx, seedTranslationsSQL, o.promptBytes); err != nil {
			return nil, safeError("seed two locales")
		}
		if _, err = tx.Exec(ctx, seedAnswersSQL, o.answerBytes); err != nil {
			return nil, safeError("seed seven alternatives per translation")
		}
		if _, err = tx.Exec(ctx, `UPDATE _runtime_benchmark_fixture SET canonical_count=$1`, o.questions); err != nil {
			return nil, safeError("record synthetic fixture count")
		}
		if _, err = tx.Exec(ctx, `UPDATE quiz_catalog_state SET current_revision=$1,published_revision=$1,publication_backend='postgres',fallback_locale='en-US',supported_locales=ARRAY['en-US','pt-BR']`, int64(o.questions)); err != nil {
			return nil, safeError("publish synthetic fixture revision")
		}
		if err = tx.Commit(ctx); err != nil {
			return nil, safeError("commit synthetic seed")
		}
	}
	// The role is deliberately fixture-only and has the same view-only grants as
	// the application. Its fixed credential is not a usable project secret.
	if _, err = conn.Exec(ctx, `DO $$BEGIN IF NOT EXISTS(SELECT FROM pg_roles WHERE rolname='runtime_benchmark_reader') THEN CREATE ROLE runtime_benchmark_reader LOGIN PASSWORD 'synthetic-benchmark-only' NOINHERIT; END IF; END$$;
GRANT USAGE ON SCHEMA quiz_runtime_v1 TO runtime_benchmark_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA quiz_runtime_v1 TO runtime_benchmark_reader;
REVOKE TEMP ON DATABASE quickquiz_runtime_benchmark_test FROM PUBLIC;`); err != nil {
		return nil, safeError("provision fixture read-only role")
	}
	if _, err = conn.Exec(ctx, `VACUUM (ANALYZE)`); err != nil {
		return nil, safeError("analyze synthetic fixture")
	}
	info, err := readFixtureInfo(ctx, conn)
	if err != nil {
		return nil, err
	}
	info.SeedSeconds = time.Since(started).Seconds()
	if info.CanonicalQuestions != int64(o.questions) || info.Themes < 200 || info.Topics < 2000 || info.HotScope != 10000 || info.Translations != info.CanonicalQuestions*2 || info.Answers != info.CanonicalQuestions*14 {
		return info, safeError("fixture count and locale parity assertions")
	}
	return info, nil
}

func readFixtureInfo(ctx context.Context, conn *pgx.Conn) (*fixtureInfo, error) {
	var info fixtureInfo
	var marker string
	err := conn.QueryRow(ctx, `SELECT marker,prompt_bytes,answer_bytes FROM _runtime_benchmark_fixture WHERE singleton=1`).Scan(&marker, &info.PromptBytes, &info.AnswerBytes)
	if err != nil || marker != fixtureMarker {
		return nil, safeError("fixture marker read")
	}
	err = conn.QueryRow(ctx, `SELECT (SELECT count(*) FROM quiz_questions),(SELECT count(*) FROM quiz_question_translations),(SELECT count(*) FROM quiz_answers),(SELECT count(*) FROM quiz_themes),(SELECT count(*) FROM quiz_topics),(SELECT count(*) FROM quiz_questions WHERE theme_id='bench-001' AND topic_key='topic-01' AND difficulty=1),pg_database_size(current_database()),(SELECT coalesce(sum(pg_indexes_size(oid)),0)::bigint FROM pg_class WHERE relnamespace='public'::regnamespace AND relkind='r')`).Scan(&info.CanonicalQuestions, &info.Translations, &info.Answers, &info.Themes, &info.Topics, &info.HotScope, &info.DatabaseBytes, &info.IndexBytes)
	if err != nil {
		return nil, safeError("fixture counts")
	}
	return &info, nil
}

func readerConfig(ctx context.Context, o options) (*pgx.ConnConfig, *fixtureInfo, error) {
	cfg, err := pgx.ParseConfig(o.databaseURL)
	if err != nil || cfg.Database != databaseName {
		return nil, nil, safeError("database guard")
	}
	admin, err := pgx.ConnectConfig(ctx, cfg)
	if err != nil {
		return nil, nil, safeError("fixture verification connection")
	}
	defer admin.Close(ctx)
	info, err := readFixtureInfo(ctx, admin)
	if err != nil {
		return nil, nil, err
	}
	if info.CanonicalQuestions != int64(o.questions) {
		return nil, nil, safeError("requested fixture count mismatch")
	}
	cfg.User, cfg.Password = readerName, readerPassword
	return cfg, info, nil
}

const seedMetadataSQL = `
INSERT INTO quiz_themes(id,name,description,weight,active,created_at,updated_at)
SELECT 'bench-'||lpad(n::text,3,'0'),'Synthetic theme '||n,'Disposable synthetic capacity fixture',n,true,now(),now() FROM generate_series(1,200)n;
INSERT INTO quiz_tags(slug) VALUES('benchmark');
INSERT INTO quiz_theme_tags(theme_id,tag_slug) SELECT id,'benchmark' FROM quiz_themes;
INSERT INTO quiz_topics(theme_id,topic_key,name,description,weight,active,created_at,updated_at)
SELECT id,'topic-'||lpad(n::text,2,'0'),'Synthetic topic '||n,'Disposable synthetic topic',n,true,now(),now() FROM quiz_themes CROSS JOIN generate_series(1,10)n;
INSERT INTO quiz_topic_translations(theme_id,topic_key,locale,name,description,updated_at)
SELECT theme_id,topic_key,l,'Synthetic localized topic '||topic_key||' '||l,'Synthetic description',now() FROM quiz_topics CROSS JOIN unnest(ARRAY['en-US','pt-BR'])l;`

const seedBaseKeysSQL = `
INSERT INTO quiz_questions(theme_id,topic_key,difficulty,question_id,created_at,updated_at)
SELECT 'bench-001','topic-01',1,'hot-'||lpad(n::text,7,'0'),now(),now() FROM generate_series(1,10000)n;
INSERT INTO quiz_questions(theme_id,topic_key,difficulty,question_id,created_at,updated_at)
SELECT 'bench-'||lpad((((m-1)/10)+1)::text,3,'0'),'topic-'||lpad((((m-1)%10)+1)::text,2,'0'),((((n-1)/1999)%4)+1)::smallint,'base-'||lpad(n::text,7,'0'),now(),now()
FROM (SELECT n,((n-1)%1999)+2 AS m FROM generate_series(1,90000)n)s;`

const seedExtraKeysSQL = `
INSERT INTO quiz_questions(theme_id,topic_key,difficulty,question_id,created_at,updated_at)
SELECT 'bench-'||lpad((((m-1)/10)+1)::text,3,'0'),'topic-'||lpad((((m-1)%10)+1)::text,2,'0'),((((n-1)/1990)%4)+1)::smallint,'extra-'||lpad(n::text,9,'0'),now(),now()
FROM (SELECT n,((n-1)%1990)+11 AS m FROM generate_series($1::integer,$2::integer)n)s;`

const seedTranslationsSQL = `
INSERT INTO quiz_question_translations(theme_id,topic_key,difficulty,question_id,locale,prompt,updated_at)
SELECT q.theme_id,q.topic_key,q.difficulty,q.question_id,l,rpad('Synthetic question '||q.question_id||' '||l||'. Choose the recorded synthetic alternative. ',$1::integer,'Synthetic capacity text. '),now()
FROM quiz_questions q CROSS JOIN unnest(ARRAY['en-US','pt-BR'])l
WHERE NOT EXISTS(SELECT 1 FROM quiz_question_translations tr WHERE tr.theme_id=q.theme_id AND tr.topic_key=q.topic_key AND tr.difficulty=q.difficulty AND tr.question_id=q.question_id AND tr.locale=l);`

const seedAnswersSQL = `
INSERT INTO quiz_answers(theme_id,topic_key,difficulty,question_id,locale,kind,answer_position,answer_text)
SELECT q.theme_id,q.topic_key,q.difficulty,q.question_id,q.locale,CASE WHEN a=0 THEN 'correct' ELSE 'wrong' END,CASE WHEN a=0 THEN 0 ELSE a-1 END,
rpad('Synthetic alternative '||a||' for '||q.question_id||' '||q.locale||'. ',$1::integer,'Capacity answer text. ')
FROM quiz_question_translations q CROSS JOIN generate_series(0,6)a
WHERE NOT EXISTS(SELECT 1 FROM quiz_answers ans WHERE ans.theme_id=q.theme_id AND ans.topic_key=q.topic_key AND ans.difficulty=q.difficulty AND ans.question_id=q.question_id AND ans.locale=q.locale);`
