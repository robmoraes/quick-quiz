package store_test

import (
	"context"
	"errors"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/jackc/pgx/v5"
	"quickquiz/api/internal/app"
	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/i18n"
	"quickquiz/api/internal/store"
)

// The URL is opt-in and must name the disposable test database. Never run these
// migrations against a development/production bank or shared preview database.
func TestPostgresRuntimeIntegration(t *testing.T) {
	adminURL := os.Getenv("QUICKQUIZ_POSTGRES_TEST_URL")
	if adminURL == "" {
		t.Skip("set QUICKQUIZ_POSTGRES_TEST_URL for disposable PostgreSQL integration")
	}
	ctx := context.Background()
	admin, err := pgx.Connect(ctx, adminURL)
	if err != nil {
		t.Fatal("connect disposable PostgreSQL:", err)
	}
	defer admin.Close(ctx)
	if admin.Config().Database != "quickquiz_runtime_test" {
		t.Fatal("integration requires disposable database quickquiz_runtime_test")
	}
	for _, query := range []string{`DROP SCHEMA IF EXISTS quiz_runtime_v1 CASCADE`, `DROP SCHEMA public CASCADE`, `CREATE SCHEMA public`} {
		if _, err = admin.Exec(ctx, query); err != nil {
			t.Fatal(err)
		}
	}
	migrations, err := filepath.Glob("../../../manager/migrations/*.sql")
	if err != nil || len(migrations) < 4 {
		t.Fatal("runtime migrations unavailable", err)
	}
	for _, path := range migrations {
		content, err := os.ReadFile(path)
		if err != nil {
			t.Fatal(err)
		}
		if _, err = admin.Exec(ctx, string(content)); err != nil {
			t.Fatalf("migration %s: %v", filepath.Base(path), err)
		}
	}
	seed := `
 INSERT INTO quiz_themes(id,name,description,weight,active,created_at,updated_at) VALUES
 ('literature','Literature','Books',1,true,now(),now()),('dev','Development','Code',1,true,now(),now()),
 ('dslab','DSLab','Fixed client',1,true,now(),now()),('agronomy','Agronomy','Draft',4,false,now(),now());
 INSERT INTO quiz_tags(slug) VALUES('arts'),('dev'),('agro');
 INSERT INTO quiz_theme_tags(theme_id,tag_slug) VALUES('literature','arts'),('dev','dev'),('agronomy','agro');
 INSERT INTO quiz_topics(theme_id,topic_key,name,weight,active,created_at,updated_at)
 SELECT id,'essentials','Essentials',1,true,now(),now() FROM quiz_themes;
 INSERT INTO quiz_topics(theme_id,topic_key,name,weight,active,created_at,updated_at) VALUES('literature','draft','Draft',2,false,now(),now());
 INSERT INTO quiz_topic_translations(theme_id,topic_key,locale,name,description,updated_at)
 SELECT theme_id,topic_key,'pt-BR','Essencial','Descricao',now() FROM quiz_topics;
 INSERT INTO quiz_questions(theme_id,topic_key,difficulty,question_id,created_at,updated_at)
 SELECT theme_id,topic_key,d::smallint,'q'||lpad(n::text,3,'0'),now(),now() FROM quiz_topics CROSS JOIN generate_series(1,4)d CROSS JOIN generate_series(1,12)n;
 INSERT INTO quiz_question_translations(theme_id,topic_key,difficulty,question_id,locale,prompt,updated_at)
 SELECT theme_id,topic_key,difficulty,question_id,l,'Question '||question_id||' '||l,now() FROM quiz_questions CROSS JOIN unnest(ARRAY['en-US','pt-BR'])l;
 INSERT INTO quiz_answers(theme_id,topic_key,difficulty,question_id,locale,kind,answer_position,answer_text)
 SELECT theme_id,topic_key,difficulty,question_id,locale,CASE WHEN a=0 THEN 'correct' ELSE 'wrong' END,CASE WHEN a=0 THEN 0 ELSE a-1 END,
 CASE WHEN a=0 THEN 'Correct ' ELSE 'Alternative ' END||a||' '||question_id FROM quiz_question_translations CROSS JOIN generate_series(0,6)a;
 INSERT INTO ai_prompts(theme,prompt_key,prompt_text,created_at,updated_at) VALUES('literature','question_solution','Explain this literary question','now','now');
 CREATE TABLE users(id text PRIMARY KEY,password text);
 UPDATE quiz_catalog_state SET current_revision=1,published_revision=1,publication_backend='postgres',fallback_locale='en-US',supported_locales=ARRAY['en-US','pt-BR'];
 `
	if _, err = admin.Exec(ctx, seed); err != nil {
		t.Fatal(err)
	}
	if _, err = admin.Exec(ctx, `DO $$BEGIN IF NOT EXISTS(SELECT FROM pg_roles WHERE rolname='runtime_reader_test') THEN CREATE ROLE runtime_reader_test LOGIN PASSWORD 'reader-test-only' NOINHERIT; END IF; END$$;
 GRANT USAGE ON SCHEMA quiz_runtime_v1 TO runtime_reader_test;
 GRANT SELECT ON quiz_runtime_v1.state,quiz_runtime_v1.themes,quiz_runtime_v1.topics,quiz_runtime_v1.topic_translations,quiz_runtime_v1.topic_tags,quiz_runtime_v1.question_keys,quiz_runtime_v1.question_translations,quiz_runtime_v1.answers,quiz_runtime_v1.solution_prompts TO runtime_reader_test;
 REVOKE TEMP ON DATABASE quickquiz_runtime_test FROM PUBLIC;`); err != nil {
		t.Fatal(err)
	}
	readerConfig := admin.Config().Copy()
	readerConfig.User = "runtime_reader_test"
	readerConfig.Password = "reader-test-only"
	readerAddress, err := url.Parse(adminURL)
	if err != nil {
		t.Fatal(err)
	}
	readerAddress.User = url.UserPassword("runtime_reader_test", "reader-test-only")
	readerURL := readerAddress.String()
	reader, err := store.NewPostgresQuestionStore(ctx, store.PostgresQuestionStoreConfig{URL: readerURL, MaxConnections: 4, ConnectTimeout: 5 * time.Second, QueryTimeout: 2 * time.Second, MaxIdleTime: time.Minute, FallbackLocale: "en-US", SupportedLocales: []string{"en-US", "pt-BR"}})
	if err != nil {
		t.Fatal(err)
	}
	defer reader.Close()
	if err = reader.Ready(ctx); err != nil {
		t.Fatal(err)
	}
	t.Run("real reader privileges", func(t *testing.T) {
		conn, err := pgx.ConnectConfig(ctx, readerConfig)
		if err != nil {
			t.Fatal(err)
		}
		defer conn.Close(ctx)
		for _, query := range []string{`SELECT * FROM public.quiz_themes`, `SELECT * FROM public.users`, `UPDATE quiz_runtime_v1.themes SET name='unsafe'`, `CREATE TABLE public.unsafe(id int)`, `CREATE TEMP TABLE unsafe_temp(id int)`} {
			_, err = conn.Exec(ctx, query)
			var pgerr interface{ SQLState() string }
			if err == nil || !errors.As(err, &pgerr) || pgerr.SQLState() != "42501" {
				t.Fatalf("query did not fail permission check: %s: %v", query, err)
			}
		}
	})
	t.Run("generic discovery and bounded counts", func(t *testing.T) {
		tag := "arts"
		themes, err := reader.ListThemesByTag(ctx, &tag)
		if err != nil || len(themes) != 1 || themes[0].ID != "literature" {
			t.Fatalf("themes=%v err=%v", themes, err)
		}
		all, err := reader.ListThemes(ctx)
		if err != nil || len(all) != 3 {
			t.Fatalf("active themes=%v err=%v", all, err)
		}
		if all[0].ID != "dev" || all[1].ID != "dslab" || all[2].ID != "literature" {
			t.Fatalf("theme order must remain weight then ID: %v", all)
		}
		before := reader.QueryCount()
		result, err := reader.ReadCatalog(ctx, domain.CatalogReadInput{Theme: "literature", Locale: "pt-BR", Consumption: domain.QuestionConsumption{"essentials": {1: {"q001", "q001", "deleted-id"}}}})
		if err != nil {
			t.Fatal(err)
		}
		if len(result.Metadata.Topics) != 1 || result.Metadata.Topics[0].Label != "Essencial" || result.AvailableCounts["essentials"][1] != 11 {
			t.Fatalf("bad catalog: %+v", result)
		}
		if calls := reader.QueryCount() - before; calls > 6 {
			t.Fatalf("catalog query amplification: %d", calls)
		}
	})
	t.Run("incompatible empty view fails readiness", func(t *testing.T) {
		_, err := admin.Exec(ctx, `ALTER VIEW quiz_runtime_v1.themes RENAME TO themes_valid;
    CREATE VIEW quiz_runtime_v1.themes AS SELECT id,name,description,weight::text AS weight,active,created_at,ARRAY[]::text[] AS tags FROM quiz_themes WHERE false;
    GRANT SELECT ON quiz_runtime_v1.themes TO runtime_reader_test;`)
		if err != nil {
			t.Fatal(err)
		}
		t.Cleanup(func() {
			if _, err := admin.Exec(ctx, `DROP VIEW quiz_runtime_v1.themes; ALTER VIEW quiz_runtime_v1.themes_valid RENAME TO themes;`); err != nil {
				t.Error(err)
			}
		})
		if err := reader.Ready(ctx); !errors.Is(err, domain.ErrStorageUnavailable) {
			t.Fatal("incompatible view was ready", err)
		}
	})
	t.Run("small complete run packages", func(t *testing.T) {
		selected, err := reader.SelectRunPackages(ctx, domain.RunPackageSelection{Theme: "literature", Topic: "essentials", Locale: "pt-BR", Difficulty: 1, Limit: 5, UsedQuestionIDs: map[string]bool{"q001": true}, SupportedLocales: []string{"en-US", "pt-BR"}})
		if err != nil || len(selected.Packages) != 5 || selected.Revision == nil || *selected.Revision != 1 {
			t.Fatalf("selection failed: %+v %v", selected, err)
		}
		for _, snapshot := range selected.Packages {
			if snapshot.Key.QuestionID == "q001" || len(snapshot.Translations) != 2 || snapshot.Digest == "" {
				t.Fatalf("invalid package: %+v", snapshot)
			}
		}
		question, err := reader.LookupQuestion(ctx, domain.QuestionLookup{QuestionKey: domain.QuestionKey{Theme: "literature", Topic: "essentials", Difficulty: 1, QuestionID: "q001"}, Locale: "pt-BR"})
		if err != nil || question.Prompt != "Question q001 pt-BR" {
			t.Fatal(question, err)
		}
	})
	t.Run("runs continue after edits retirement and dependency failure", func(t *testing.T) {
		runs := store.NewMemoryRunStore(time.Hour)
		service := app.NewRunService(reader, runs, 5, i18n.NewManager("en-US", []string{"en-US", "pt-BR"}))
		created, err := service.CreateRun(ctx, app.CreateRunInput{SessionID: "synthetic-retirement", Theme: "literature", Topic: "essentials", Locale: "en-US", Difficulty: 1})
		if err != nil {
			t.Fatal(err)
		}
		retained, err := runs.Get(ctx, created.RunID)
		if err != nil || retained.Version != 2 {
			t.Fatal("snapshot missing", err)
		}
		if _, err = admin.Exec(ctx, `BEGIN;DELETE FROM quiz_questions WHERE theme_id='literature';UPDATE quiz_themes SET active=false WHERE id='literature';UPDATE quiz_catalog_state SET current_revision=2,published_revision=2;COMMIT;`); err != nil {
			t.Fatal(err)
		}
		prompt, err := reader.Load(ctx, "literature")
		if err != nil || prompt != "Explain this literary question" {
			t.Fatal("retirement lost custom instruction", err)
		}
		availability, err := service.SessionTopics(ctx, app.SessionTopicsInput{SessionID: "synthetic-retirement", Theme: "literature", Locale: "en-US"})
		if err != nil || len(availability.Topics) != 0 {
			t.Fatal("retired availability", availability, err)
		}
		if _, err = admin.Exec(ctx, `REVOKE SELECT ON quiz_runtime_v1.state FROM runtime_reader_test`); err != nil {
			t.Fatal(err)
		}
		if _, err = reader.ListThemes(ctx); !errors.Is(err, domain.ErrStorageUnavailable) {
			t.Fatal("permission outage became absence", err)
		}
		for i := 0; i < 5; i++ {
			state, err := service.RunState(ctx, "literature", created.RunID)
			if err != nil || state.Question == nil || !strings.HasPrefix(state.Question.Prompt, "Question ") {
				t.Fatal("captured state lost", state, err)
			}
			run, _ := runs.Get(ctx, created.RunID)
			answer, err := service.Answer(ctx, "literature", created.RunID, state.Question.ID, run.CurrentQuestion.CorrectOptionID)
			if err != nil || !answer.Correct {
				t.Fatal("captured grading failed", answer, err)
			}
		}
		result, err := service.Result(ctx, "literature", created.RunID)
		if err != nil || result.Stats.Correct != 5 {
			t.Fatal("captured result failed", result, err)
		}
		if _, err = admin.Exec(ctx, `GRANT SELECT ON quiz_runtime_v1.state TO runtime_reader_test`); err != nil {
			t.Fatal(err)
		}
	})
	t.Run("configuration mismatch fails readiness", func(t *testing.T) {
		if _, err = admin.Exec(ctx, `UPDATE quiz_catalog_state SET supported_locales=ARRAY['en-US']`); err != nil {
			t.Fatal(err)
		}
		if err = reader.Ready(ctx); !errors.Is(err, domain.ErrStorageUnavailable) {
			t.Fatal("locale mismatch was accepted", err)
		}
		if _, err = admin.Exec(ctx, `UPDATE quiz_catalog_state SET supported_locales=ARRAY['en-US','pt-BR']`); err != nil {
			t.Fatal(err)
		}
	})
	t.Run("public web discovery", func(t *testing.T) { testPostgresDiscovery(t, ctx, admin, reader) })
	t.Logf("PostgreSQL integration completed; queries=%d poolMax=%d", reader.QueryCount(), reader.PoolStats().MaxConns())
}

func TestPostgresConfigurationDoesNotExposeDSN(t *testing.T) {
	_, err := store.NewPostgresQuestionStore(context.Background(), store.PostgresQuestionStoreConfig{URL: "postgres://reader:private-password@%invalid/quiz", MaxConnections: 4, ConnectTimeout: time.Second, QueryTimeout: time.Second, MaxIdleTime: time.Minute})
	if err == nil || strings.Contains(fmt.Sprint(err), "private-password") {
		t.Fatal("expected safe configuration error")
	}
}
