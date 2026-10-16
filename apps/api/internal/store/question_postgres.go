package store

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strings"
	"sync/atomic"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgtype"
	"github.com/jackc/pgx/v5/pgxpool"
	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/i18n"
)

type PostgresQuestionStoreConfig struct {
	URL              string
	MaxConnections   int
	ConnectTimeout   time.Duration
	QueryTimeout     time.Duration
	MaxIdleTime      time.Duration
	FallbackLocale   string
	SupportedLocales []string
}

type PostgresQuestionStore struct {
	pool    *pgxpool.Pool
	timeout time.Duration
	locales i18n.Manager
	queries atomic.Uint64
}

type catalogState struct {
	current, published int64
	backend            string
	version            int
	fallback           string
	supported          []string
}

type queryCounter struct{ count *atomic.Uint64 }

func (q queryCounter) TraceQueryStart(ctx context.Context, _ *pgx.Conn, _ pgx.TraceQueryStartData) context.Context {
	q.count.Add(1)
	return ctx
}
func (q queryCounter) TraceQueryEnd(context.Context, *pgx.Conn, pgx.TraceQueryEndData) {}

func NewPostgresQuestionStore(ctx context.Context, cfg PostgresQuestionStoreConfig) (*PostgresQuestionStore, error) {
	if strings.TrimSpace(cfg.URL) == "" {
		return nil, errors.New("QUIZ_DATABASE_URL is required")
	}
	if cfg.MaxConnections <= 0 || int64(cfg.MaxConnections) > 2147483647 || cfg.ConnectTimeout <= 0 || cfg.QueryTimeout <= 0 || cfg.MaxIdleTime <= 0 {
		return nil, errors.New("invalid PostgreSQL pool configuration")
	}
	poolConfig, err := pgxpool.ParseConfig(cfg.URL)
	if err != nil {
		return nil, errors.New("invalid QUIZ_DATABASE_URL")
	}
	s := &PostgresQuestionStore{timeout: cfg.QueryTimeout, locales: i18n.NewManager(cfg.FallbackLocale, cfg.SupportedLocales)}
	poolConfig.MaxConns = int32(cfg.MaxConnections)
	poolConfig.MinConns = 0
	poolConfig.MaxConnIdleTime = cfg.MaxIdleTime
	poolConfig.ConnConfig.ConnectTimeout = cfg.ConnectTimeout
	poolConfig.ConnConfig.Tracer = queryCounter{&s.queries}
	poolConfig.ConnConfig.RuntimeParams["application_name"] = "quickquiz-api"
	pool, err := pgxpool.NewWithConfig(ctx, poolConfig)
	if err != nil {
		return nil, pgUnavailable("initialize")
	}
	s.pool = pool
	return s, nil
}

func (s *PostgresQuestionStore) Close() error             { s.pool.Close(); return nil }
func (s *PostgresQuestionStore) QueryCount() uint64       { return s.queries.Load() }
func (s *PostgresQuestionStore) PoolStats() *pgxpool.Stat { return s.pool.Stat() }
func pgUnavailable(operation string) error {
	return fmt.Errorf("%w: PostgreSQL %s failed", domain.ErrStorageUnavailable, operation)
}

// All question operations check state and read their rows in the same short snapshot.
func (s *PostgresQuestionStore) read(ctx context.Context, gate bool, work func(context.Context, pgx.Tx, catalogState) error) error {
	ctx, cancel := context.WithTimeout(ctx, s.timeout)
	defer cancel()
	tx, err := s.pool.BeginTx(ctx, pgx.TxOptions{IsoLevel: pgx.RepeatableRead, AccessMode: pgx.ReadOnly})
	if err != nil {
		return pgUnavailable("transaction")
	}
	defer tx.Rollback(ctx)
	var state catalogState
	if gate {
		err = tx.QueryRow(ctx, `SELECT current_revision,published_revision,publication_backend,contract_version,fallback_locale,supported_locales FROM quiz_runtime_v1.state`).Scan(&state.current, &state.published, &state.backend, &state.version, &state.fallback, &state.supported)
		if err != nil {
			return pgUnavailable("contract")
		}
		sort.Strings(state.supported)
		expected := s.locales.Supported()
		if state.backend != "postgres" || state.version != 1 || state.current != state.published || state.fallback != s.locales.Fallback() || !equalStrings(state.supported, expected) {
			return pgUnavailable("compatibility")
		}
	}
	if err = work(ctx, tx, state); err != nil {
		return err
	}
	if err = tx.Commit(ctx); err != nil {
		return pgUnavailable("commit")
	}
	return nil
}
func equalStrings(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}
	return true
}

// Readiness checks the actual wire types as well as column names and grants.
// It must reject incompatible views even when the catalog is empty.
func validateView(ctx context.Context, tx pgx.Tx, query string, types ...uint32) error {
	rows, err := tx.Query(ctx, query)
	if err != nil {
		return pgUnavailable("view contract")
	}
	defer rows.Close()
	fields := rows.FieldDescriptions()
	if len(fields) != len(types) {
		return pgUnavailable("view contract")
	}
	for i, field := range fields {
		if field.DataTypeOID != types[i] {
			return pgUnavailable("view contract")
		}
	}
	if rows.Next() || rows.Err() != nil {
		return pgUnavailable("view contract")
	}
	return nil
}

func (s *PostgresQuestionStore) Ready(ctx context.Context) error {
	return s.read(ctx, true, func(ctx context.Context, tx pgx.Tx, _ catalogState) error {
		// LIMIT 0 validates the contract without reading any bank payload.
		views := []struct {
			query string
			types []uint32
		}{
			{`SELECT current_revision,published_revision,publication_backend,contract_version,fallback_locale,supported_locales FROM quiz_runtime_v1.state LIMIT 0`, []uint32{pgtype.Int8OID, pgtype.Int8OID, pgtype.TextOID, pgtype.Int4OID, pgtype.TextOID, pgtype.TextArrayOID}},
			{`SELECT id,name,description,weight,active,created_at,tags FROM quiz_runtime_v1.themes LIMIT 0`, []uint32{pgtype.TextOID, pgtype.TextOID, pgtype.TextOID, pgtype.Int4OID, pgtype.BoolOID, pgtype.TimestamptzOID, pgtype.TextArrayOID}},
			{`SELECT theme_id,topic_key,name,description,weight,active,created_at FROM quiz_runtime_v1.topics LIMIT 0`, []uint32{pgtype.TextOID, pgtype.TextOID, pgtype.TextOID, pgtype.TextOID, pgtype.Int4OID, pgtype.BoolOID, pgtype.TimestamptzOID}},
			{`SELECT theme_id,topic_key,locale,name,description FROM quiz_runtime_v1.topic_translations LIMIT 0`, []uint32{pgtype.TextOID, pgtype.TextOID, pgtype.TextOID, pgtype.TextOID, pgtype.TextOID}},
			{`SELECT theme_id,topic_key,tag_slug FROM quiz_runtime_v1.topic_tags LIMIT 0`, []uint32{pgtype.TextOID, pgtype.TextOID, pgtype.TextOID}},
			{`SELECT theme_id,topic_key,difficulty,question_id FROM quiz_runtime_v1.question_keys LIMIT 0`, []uint32{pgtype.TextOID, pgtype.TextOID, pgtype.Int2OID, pgtype.TextOID}},
			{`SELECT theme_id,topic_key,difficulty,question_id,locale,prompt FROM quiz_runtime_v1.question_translations LIMIT 0`, []uint32{pgtype.TextOID, pgtype.TextOID, pgtype.Int2OID, pgtype.TextOID, pgtype.TextOID, pgtype.TextOID}},
			{`SELECT theme_id,topic_key,difficulty,question_id,locale,kind,answer_position,answer_text FROM quiz_runtime_v1.answers LIMIT 0`, []uint32{pgtype.TextOID, pgtype.TextOID, pgtype.Int2OID, pgtype.TextOID, pgtype.TextOID, pgtype.TextOID, pgtype.Int4OID, pgtype.TextOID}},
		}
		for _, view := range views {
			if err := validateView(ctx, tx, view.query, view.types...); err != nil {
				return err
			}
		}
		return nil
	})
}
func (s *PostgresQuestionStore) PromptReady(ctx context.Context) error {
	return s.read(ctx, false, func(ctx context.Context, tx pgx.Tx, _ catalogState) error {
		return validateView(ctx, tx, `SELECT theme,prompt_key,prompt_text,updated_at FROM quiz_runtime_v1.solution_prompts LIMIT 0`, pgtype.TextOID, pgtype.TextOID, pgtype.TextOID, pgtype.TextOID)
	})
}
func (s *PostgresQuestionStore) Load(ctx context.Context, theme string) (string, error) {
	var prompt string
	err := s.read(ctx, false, func(ctx context.Context, tx pgx.Tx, _ catalogState) error {
		err := tx.QueryRow(ctx, `SELECT prompt_text FROM quiz_runtime_v1.solution_prompts WHERE theme=$1 AND prompt_key='question_solution'`, theme).Scan(&prompt)
		if errors.Is(err, pgx.ErrNoRows) {
			return nil
		}
		if err != nil {
			return pgUnavailable("prompt read")
		}
		return nil
	})
	return prompt, err
}
func (s *PostgresQuestionStore) Theme(ctx context.Context, id string) (domain.Theme, error) {
	var theme domain.Theme
	err := s.read(ctx, true, func(ctx context.Context, tx pgx.Tx, _ catalogState) error {
		var created time.Time
		var tags []string
		err := tx.QueryRow(ctx, `SELECT id,name,description,weight,active,created_at,tags FROM quiz_runtime_v1.themes WHERE id=$1`, id).Scan(&theme.ID, &theme.Name, &theme.Description, &theme.Weight, &theme.Active, &created, &tags)
		if errors.Is(err, pgx.ErrNoRows) {
			return domain.ErrThemeNotFound
		}
		if err != nil {
			return pgUnavailable("theme read")
		}
		theme.CreatedAt = created.UTC().Format(time.RFC3339)
		theme.Tags = append(domain.ThemeTags{}, tags...)
		return nil
	})
	return theme, err
}
func (s *PostgresQuestionStore) ListThemes(ctx context.Context) ([]domain.Theme, error) {
	return s.ListThemesByTag(ctx, nil)
}
func (s *PostgresQuestionStore) ListThemesByTag(ctx context.Context, tag *string) ([]domain.Theme, error) {
	themes := make([]domain.Theme, 0)
	err := s.read(ctx, true, func(ctx context.Context, tx pgx.Tx, _ catalogState) error {
		rows, err := tx.Query(ctx, `SELECT id,name,description,weight,active,created_at,tags FROM quiz_runtime_v1.themes WHERE ($1::text IS NULL OR $1=ANY(tags)) ORDER BY weight,id COLLATE "C"`, tag)
		if err != nil {
			return pgUnavailable("theme discovery")
		}
		defer rows.Close()
		for rows.Next() {
			var theme domain.Theme
			var created time.Time
			var tags []string
			if err = rows.Scan(&theme.ID, &theme.Name, &theme.Description, &theme.Weight, &theme.Active, &created, &tags); err != nil {
				return pgUnavailable("theme decode")
			}
			theme.CreatedAt = created.UTC().Format(time.RFC3339)
			theme.Tags = append(domain.ThemeTags{}, tags...)
			themes = append(themes, theme)
		}
		if rows.Err() != nil {
			return pgUnavailable("theme discovery")
		}
		return nil
	})
	return themes, err
}
func (s *PostgresQuestionStore) requireTheme(ctx context.Context, tx pgx.Tx, id string) error {
	var exists bool
	if err := tx.QueryRow(ctx, `SELECT EXISTS(SELECT 1 FROM quiz_runtime_v1.themes WHERE id=$1)`, id).Scan(&exists); err != nil {
		return pgUnavailable("theme visibility")
	}
	if !exists {
		return domain.ErrThemeNotFound
	}
	return nil
}
func (s *PostgresQuestionStore) ListMetadata(ctx context.Context, theme, locale, fallback string) (domain.CatalogMetadata, error) {
	result, err := s.ReadCatalog(ctx, domain.CatalogReadInput{Theme: theme, Locale: locale, FallbackLocale: fallback})
	return result.Metadata, err
}
func (s *PostgresQuestionStore) ReadCatalog(ctx context.Context, input domain.CatalogReadInput) (domain.CatalogReadResult, error) {
	result := domain.CatalogReadResult{
		Metadata:        domain.CatalogMetadata{Theme: input.Theme, Locale: s.locales.Resolve(input.Locale, ""), FallbackLocale: s.locales.Fallback(), Topics: []domain.TopicOption{}, Difficulties: []domain.DifficultyInfo{}},
		AvailableCounts: map[string]map[domain.Difficulty]int{},
	}
	type consumedKey struct {
		Topic      string            `json:"topic"`
		Difficulty domain.Difficulty `json:"difficulty"`
		ID         string            `json:"question_id"`
	}
	consumed := make([]consumedKey, 0)
	for topic, difficulties := range input.Consumption {
		for difficulty, ids := range difficulties {
			for _, id := range ids {
				consumed = append(consumed, consumedKey{topic, difficulty, id})
			}
		}
	}
	payload, err := json.Marshal(consumed)
	if err != nil {
		return result, pgUnavailable("consumption encode")
	}
	err = s.read(ctx, true, func(ctx context.Context, tx pgx.Tx, _ catalogState) error {
		if err := s.requireTheme(ctx, tx, input.Theme); err != nil {
			return err
		}
		rows, err := tx.Query(ctx, `SELECT t.topic_key,COALESCE(l.name,f.name,t.name),COALESCE(l.description,f.description,t.description),t.weight,t.created_at
   FROM quiz_runtime_v1.topics t
   LEFT JOIN quiz_runtime_v1.topic_translations l ON l.theme_id=t.theme_id AND l.topic_key=t.topic_key AND l.locale=$2
   LEFT JOIN quiz_runtime_v1.topic_translations f ON f.theme_id=t.theme_id AND f.topic_key=t.topic_key AND f.locale=$3
   WHERE t.theme_id=$1 ORDER BY t.weight,COALESCE(l.name,f.name,t.name) COLLATE "C",t.topic_key COLLATE "C"`, input.Theme, result.Metadata.Locale, result.Metadata.FallbackLocale)
		if err != nil {
			return pgUnavailable("topic metadata")
		}
		for rows.Next() {
			var topic domain.TopicOption
			var created time.Time
			if err = rows.Scan(&topic.ID, &topic.Label, &topic.Description, &topic.Weight, &created); err != nil {
				rows.Close()
				return pgUnavailable("topic decode")
			}
			topic.Theme = input.Theme
			topic.Locale = result.Metadata.Locale
			topic.CreatedAt = created.UTC().Format(time.RFC3339)
			result.Metadata.Topics = append(result.Metadata.Topics, topic)
		}
		rows.Close()
		if rows.Err() != nil {
			return pgUnavailable("topic metadata")
		}
		rows, err = tx.Query(ctx, `WITH consumed AS (
   SELECT DISTINCT topic,difficulty,question_id FROM jsonb_to_recordset($2::jsonb) AS x(topic text,difficulty smallint,question_id text)
   ) SELECT q.topic_key,q.difficulty,COUNT(*)::integer,COUNT(*) FILTER(WHERE c.question_id IS NULL)::integer
   FROM quiz_runtime_v1.question_keys q LEFT JOIN consumed c ON c.topic=q.topic_key AND c.difficulty=q.difficulty AND c.question_id=q.question_id
   WHERE q.theme_id=$1 GROUP BY q.topic_key,q.difficulty`, input.Theme, string(payload))
		if err != nil {
			return pgUnavailable("catalog counts")
		}
		defer rows.Close()
		counts := map[string]map[domain.Difficulty]int{}
		global := map[domain.Difficulty]int{}
		for rows.Next() {
			var topic string
			var difficulty domain.Difficulty
			var total, available int
			if err = rows.Scan(&topic, &difficulty, &total, &available); err != nil {
				return pgUnavailable("counts decode")
			}
			if counts[topic] == nil {
				counts[topic] = map[domain.Difficulty]int{}
				result.AvailableCounts[topic] = map[domain.Difficulty]int{}
			}
			counts[topic][difficulty] = total
			result.AvailableCounts[topic][difficulty] = available
			global[difficulty] += total
		}
		if rows.Err() != nil {
			return pgUnavailable("catalog counts")
		}
		for i := range result.Metadata.Topics {
			topic := &result.Metadata.Topics[i]
			topic.Difficulties = difficultiesWithQuestions(counts[topic.ID])
			if result.AvailableCounts[topic.ID] == nil {
				result.AvailableCounts[topic.ID] = map[domain.Difficulty]int{}
			}
		}
		result.Metadata.Difficulties = allDifficulties(global)
		return nil
	})
	return result, err
}
func (s *PostgresQuestionStore) SelectRunPackages(ctx context.Context, input domain.RunPackageSelection) (domain.SelectedRunPackages, error) {
	result := domain.SelectedRunPackages{Locale: s.locales.Resolve(input.Locale, ""), Provider: "postgres", Packages: []domain.QuestionSnapshot{}}
	if !input.Difficulty.Valid() || input.Limit <= 0 {
		return result, domain.ErrInvalidQuestion
	}
	if len(input.SupportedLocales) > 0 {
		locales := append([]string{}, input.SupportedLocales...)
		sort.Strings(locales)
		if !equalStrings(locales, s.locales.Supported()) {
			return result, pgUnavailable("locale configuration")
		}
	}
	used := make([]string, 0, len(input.UsedQuestionIDs))
	for id, yes := range input.UsedQuestionIDs {
		if yes {
			used = append(used, id)
		}
	}
	err := s.read(ctx, true, func(ctx context.Context, tx pgx.Tx, state catalogState) error {
		if err := s.requireTheme(ctx, tx, input.Theme); err != nil {
			return err
		}
		revision := state.published
		result.Revision = &revision
		rows, err := tx.Query(ctx, `SELECT question_id FROM quiz_runtime_v1.question_keys WHERE theme_id=$1 AND topic_key=$2 AND difficulty=$3 AND NOT(question_id=ANY($4::text[])) ORDER BY random() LIMIT $5`, input.Theme, input.Topic, int(input.Difficulty), used, input.Limit)
		if err != nil {
			return pgUnavailable("candidate selection")
		}
		ids := make([]string, 0, input.Limit)
		for rows.Next() {
			var id string
			if err = rows.Scan(&id); err != nil {
				rows.Close()
				return pgUnavailable("candidate decode")
			}
			ids = append(ids, id)
		}
		rows.Close()
		if rows.Err() != nil {
			return pgUnavailable("candidate selection")
		}
		if len(ids) == 0 {
			return nil
		}
		result.Packages, err = s.hydrate(ctx, tx, input.Theme, input.Topic, input.Difficulty, ids)
		return err
	})
	return result, err
}
func (s *PostgresQuestionStore) hydrate(ctx context.Context, tx pgx.Tx, theme, topic string, difficulty domain.Difficulty, ids []string) ([]domain.QuestionSnapshot, error) {
	rows, err := tx.Query(ctx, `SELECT q.question_id,q.locale,q.prompt,a.kind,a.answer_position,a.answer_text
  FROM quiz_runtime_v1.question_translations q LEFT JOIN quiz_runtime_v1.answers a
   ON a.theme_id=q.theme_id AND a.topic_key=q.topic_key AND a.difficulty=q.difficulty AND a.question_id=q.question_id AND a.locale=q.locale
  WHERE q.theme_id=$1 AND q.topic_key=$2 AND q.difficulty=$3 AND q.question_id=ANY($4::text[]) AND q.locale=ANY($5::text[])
  ORDER BY q.question_id,q.locale,a.kind,a.answer_position`, theme, topic, int(difficulty), ids, s.locales.Supported())
	if err != nil {
		return nil, pgUnavailable("package hydration")
	}
	defer rows.Close()
	packages := make(map[string]*domain.QuestionSnapshot, len(ids))
	for _, id := range ids {
		packages[id] = &domain.QuestionSnapshot{Key: domain.QuestionKey{Theme: theme, Topic: topic, Difficulty: difficulty, QuestionID: id}, Translations: map[string]domain.Question{}}
	}
	for rows.Next() {
		var id, locale, prompt string
		var kind, text *string
		var position *int
		if err = rows.Scan(&id, &locale, &prompt, &kind, &position, &text); err != nil {
			return nil, pgUnavailable("package decode")
		}
		snapshot := packages[id]
		q, ok := snapshot.Translations[locale]
		if !ok {
			q = domain.Question{ID: id, Theme: theme, Topic: topic, Difficulty: difficulty, Locale: locale, Prompt: prompt, CorrectOptions: []string{}, WrongOptions: []string{}}
		}
		if kind != nil && text != nil {
			switch *kind {
			case "correct":
				q.CorrectOptions = append(q.CorrectOptions, *text)
			case "wrong":
				q.WrongOptions = append(q.WrongOptions, *text)
			default:
				return nil, pgUnavailable("answer validity")
			}
		}
		snapshot.Translations[locale] = q
	}
	if rows.Err() != nil {
		return nil, pgUnavailable("package hydration")
	}
	result := make([]domain.QuestionSnapshot, 0, len(ids))
	for _, id := range ids {
		snapshot := packages[id]
		for _, locale := range s.locales.Supported() {
			q, ok := snapshot.Translations[locale]
			if !ok || q.Validate() != nil {
				return nil, pgUnavailable("package integrity")
			}
		}
		snapshot.Digest = domain.SnapshotDigest(*snapshot)
		result = append(result, *snapshot)
	}
	return result, nil
}
func (s *PostgresQuestionStore) LookupQuestion(ctx context.Context, input domain.QuestionLookup) (domain.Question, error) {
	var question domain.Question
	err := s.read(ctx, true, func(ctx context.Context, tx pgx.Tx, _ catalogState) error {
		var exists bool
		if err := tx.QueryRow(ctx, `SELECT EXISTS(SELECT 1 FROM quiz_runtime_v1.question_keys WHERE theme_id=$1 AND topic_key=$2 AND difficulty=$3 AND question_id=$4)`, input.Theme, input.Topic, int(input.Difficulty), input.QuestionID).Scan(&exists); err != nil {
			return pgUnavailable("question identity")
		}
		if !exists {
			return domain.ErrQuestionNotFound
		}
		packages, err := s.hydrate(ctx, tx, input.Theme, input.Topic, input.Difficulty, []string{input.QuestionID})
		if err != nil {
			return err
		}
		question = packages[0].Translations[s.locales.Resolve(input.Locale, "")]
		return nil
	})
	return question, err
}

// Legacy broad reads are deliberately unavailable in PostgreSQL mode. The
// application must use RuntimeQuestionReader; retaining this method permits a
// staged transition of the old QuestionRepository interface without a preload.
func (s *PostgresQuestionStore) ListByThemeLocaleTopicAndDifficulty(context.Context, string, string, string, domain.Difficulty) ([]domain.Question, error) {
	return nil, pgUnavailable("legacy broad read")
}
