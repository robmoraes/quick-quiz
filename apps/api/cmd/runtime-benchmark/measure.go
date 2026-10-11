package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/redis/go-redis/v9"
	"quickquiz/api/internal/app"
	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/httpapi"
	"quickquiz/api/internal/i18n"
	"quickquiz/api/internal/store"
)

type observedQuestions struct {
	*store.PostgresQuestionStore
	packages, snapshotBytes, locales atomic.Int64
}

func (q *observedQuestions) SelectRunPackages(ctx context.Context, in domain.RunPackageSelection) (domain.SelectedRunPackages, error) {
	out, err := q.PostgresQuestionStore.SelectRunPackages(ctx, in)
	if err == nil {
		atomicMax(&q.packages, int64(len(out.Packages)))
		payload, _ := json.Marshal(out.Packages)
		atomicMax(&q.snapshotBytes, int64(len(payload)))
		for _, p := range out.Packages {
			atomicMax(&q.locales, int64(len(p.Translations)))
		}
	}
	return out, err
}

func atomicMax(v *atomic.Int64, n int64) {
	for old := v.Load(); n > old; old = v.Load() {
		if v.CompareAndSwap(old, n) {
			return
		}
	}
}

type phaseResult struct {
	RunLimit               int              `json:"run_question_limit"`
	Endpoint               string           `json:"endpoint"`
	Operations             int              `json:"operations"`
	Warmup                 int              `json:"warmup_operations"`
	Clients                int              `json:"clients"`
	Errors                 map[string]int   `json:"errors"`
	P50MS                  float64          `json:"p50_ms"`
	P95MS                  float64          `json:"p95_ms"`
	MaxMS                  float64          `json:"max_ms"`
	WallSeconds            float64          `json:"wall_seconds"`
	SQLQueries             uint64           `json:"sql_queries"`
	SQLQueriesPerOperation float64          `json:"sql_queries_per_operation"`
	PoolAcquires           int64            `json:"pool_acquires"`
	PoolEmptyAcquires      int64            `json:"pool_empty_acquires"`
	PoolAcquireDurationMS  float64          `json:"pool_acquire_duration_ms"`
	PoolCanceledAcquires   int64            `json:"pool_canceled_acquires"`
	PoolMaxConnections     int32            `json:"pool_max_connections"`
	RedisCommands          map[string]int64 `json:"redis_commands"`
	RedisCommandCount      int64            `json:"redis_command_count"`
	ResponseBytesMax       int              `json:"response_bytes_max"`
	RSSP50Bytes            uint64           `json:"rss_p50_bytes"`
	RSSP95Bytes            uint64           `json:"rss_p95_bytes"`
	RSSMaxBytes            uint64           `json:"rss_max_bytes"`
}

type measured struct {
	Fixture              *fixtureInfo               `json:"fixture"`
	StartupMS            float64                    `json:"startup_to_ready_ms"`
	ReadinessSQLQueries  uint64                     `json:"readiness_sql_queries"`
	ReadinessError       bool                       `json:"readiness_error"`
	Phases               []phaseResult              `json:"phases"`
	BoundedHydration     []map[string]int64         `json:"bounded_hydration"`
	Plans                map[string]json.RawMessage `json:"sql_plans"`
	Memory               map[string]uint64          `json:"process_memory"`
	RedisUsedMemoryBytes int64                      `json:"redis_used_memory_bytes_after_cleanup"`
	CleanupErrors        int                        `json:"cleanup_errors"`
	BudgetAssessment     map[string]any             `json:"budget_observations_not_target_approval"`
	Limitations          []string                   `json:"limitations"`
}

type requestResult struct {
	elapsed       time.Duration
	status        int
	body          []byte
	responseBytes int
	validation    string
}

func serve(router http.Handler, method, path, session, locale string, payload any) requestResult {
	var body []byte
	if payload != nil {
		body, _ = json.Marshal(payload)
	}
	r := httptest.NewRequest(method, path, bytes.NewReader(body))
	r.Header.Set("X-QuickQuiz-Theme", "bench-001")
	r.Header.Set("X-QuickQuiz-Session-ID", session)
	r.Header.Set("X-QuickQuiz-Locale", locale)
	r.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()
	started := time.Now()
	router.ServeHTTP(w, r)
	elapsed := time.Since(started)
	return requestResult{elapsed: elapsed, status: w.Code, body: w.Body.Bytes(), responseBytes: w.Body.Len()}
}

func decodeCreated(result *requestResult, limit int) app.CreateRunOutput {
	var out app.CreateRunOutput
	if result.status != http.StatusCreated {
		return out
	}
	if err := json.Unmarshal(result.body, &out); err != nil || out.RunID == "" || out.Question.ID == "" || out.Question.Total != limit || len(out.Question.Options) != 3 {
		result.validation = "invalid_created_run_shape"
	}
	return out
}

func measure(ctx context.Context, o options) (*measured, error) {
	cfg, fixture, err := readerConfig(ctx, o)
	if err != nil {
		return nil, err
	}
	// Reconstruct the URL from parsed fields without ever serializing it in a
	// receipt. This fixture role has no access to normalized base tables.
	address, err := url.Parse(o.databaseURL)
	if err != nil {
		return nil, safeError("reader URL")
	}
	address.User = url.UserPassword(cfg.User, cfg.Password)
	observer := redis.NewClient(&redis.Options{Addr: o.redisAddr, DB: o.redisDB})
	defer observer.Close()
	const guardKey = "__quickquiz_runtime_benchmark_guard"
	size, err := observer.DBSize(ctx).Result()
	if err != nil {
		return nil, safeError("disposable Redis connection")
	}
	if size != 0 {
		marker, markerErr := observer.Get(ctx, guardKey).Result()
		if markerErr != nil || marker != fixtureMarker {
			return nil, safeError("refuse nonempty Redis without synthetic guard marker")
		}
	}
	startup := time.Now()
	pg, err := store.NewPostgresQuestionStore(ctx, store.PostgresQuestionStoreConfig{URL: address.String(), MaxConnections: 4, ConnectTimeout: 5 * time.Second, QueryTimeout: 2 * time.Second, MaxIdleTime: time.Minute, FallbackLocale: "en-US", SupportedLocales: []string{"en-US", "pt-BR"}})
	if err != nil {
		return nil, safeError("runtime reader initialization")
	}
	defer pg.Close()
	questions := &observedQuestions{PostgresQuestionStore: pg}
	runs, err := store.NewRedisRunStore(ctx, store.RedisRunStoreConfig{Addr: o.redisAddr, DB: o.redisDB, KeyPrefix: "quickquiz:runtime-benchmark:runs:", TTL: time.Hour})
	if err != nil {
		return nil, safeError("runtime Redis initialization")
	}
	defer runs.Close()
	if err = observer.Set(ctx, guardKey, fixtureMarker, 0).Err(); err != nil {
		return nil, safeError("write disposable Redis marker")
	}
	logger := slog.New(slog.NewJSONHandler(io.Discard, &slog.HandlerOptions{Level: slog.LevelError}))
	locales := i18n.NewManager("en-US", []string{"en-US", "pt-BR"})
	service := app.NewRunService(questions, runs, 5, locales)
	router := httpapi.NewRouter(service, nil, logger, nil, pg.Ready, runs.Ready)
	ready := serve(router, "GET", "/readyz", "", "en-US", nil)
	report := &measured{Fixture: fixture, StartupMS: float64(time.Since(startup).Microseconds()) / 1000, ReadinessSQLQueries: pg.QueryCount(), ReadinessError: ready.status != http.StatusOK, Plans: map[string]json.RawMessage{}, Memory: map[string]uint64{}, Limitations: []string{
		"Server-time uses the real HTTP router with httptest, excluding ingress/network transit; the load generator and response validation share the API process and its RSS/CPU budget.",
		"The process is a benchmark harness, not cmd/api: excludes executable startup/config parsing, production log I/O, Manager, ads, SPAs, and reverse proxy.",
		"PostgreSQL/Redis are real isolated dependencies. Exact host/container restrictions are in environment.json; aggregate per-container memory caps and shared CPU pinning are an approximation, not a dedicated 2 GiB node acceptance run.",
		"SQL counts are pgx query-tracer calls including transaction commands. Pool acquire duration includes all acquisitions, while empty-acquire count indicates contention; this is not an isolated wait-time histogram.",
		"Redis command counts exclude observer INFO commands and include server-side Lua subcommands as reported by INFO commandstats; no production Redis monitoring or keys are accessed.",
		"All request scopes use the same 10,000-question hot topic/difficulty and alternate en-US/pt-BR. State/answer operate on captured runs; AI generation is intentionally excluded.",
		"Synthetic payloads have fixed 256-byte prompts and 96-byte alternatives unless flags override them; this does not model arbitrary author-provided payload sizes or long-lived session exhaustion.",
		"The optional one-million-question experiment has not been implied or approved by a 100k/200k run.",
	}}
	if report.ReadinessError {
		return report, safeError("readiness")
	}
	report.Memory["after_ready_rss_bytes"], report.Memory["after_ready_hwm_bytes"] = linuxRSS()
	for _, limit := range []int{5, 10} {
		questions.packages.Store(0)
		questions.snapshotBytes.Store(0)
		questions.locales.Store(0)
		service = app.NewRunService(questions, runs, limit, locales)
		router = httpapi.NewRouter(service, nil, logger, nil, pg.Ready, runs.Ready)
		bench := &runner{ctx: ctx, o: o, router: router, pg: pg, redis: observer, limit: limit, prefix: "synthetic-" + strconv.FormatInt(time.Now().UnixNano(), 36) + "-"}
		for _, endpoint := range []string{"discovery", "catalog", "availability", "create", "state", "answer"} {
			phase, phaseErr := bench.endpoint(endpoint)
			report.Phases = append(report.Phases, phase)
			if phaseErr != nil {
				report.CleanupErrors += bench.cleanup()
				return report, phaseErr
			}
			fmt.Fprintf(os.Stderr, "measured fixture=%d limit=%d endpoint=%s operations=%d p95_ms=%.3f errors=%d\n", o.questions, limit, endpoint, phase.Operations, phase.P95MS, sumErrors(phase.Errors))
		}
		report.CleanupErrors += bench.cleanup()
		report.BoundedHydration = append(report.BoundedHydration, map[string]int64{"run_question_limit": int64(limit), "max_selected_packages": questions.packages.Load(), "max_locales_per_package": questions.locales.Load(), "max_snapshot_json_bytes": questions.snapshotBytes.Load()})
	}
	// Plans are recorded after latency measurement, not part of measured query
	// deltas; only synthetic IDs/parameters occur in them.
	report.Plans, err = queryPlans(ctx, cfg)
	if err != nil {
		return report, err
	}
	runtime.GC()
	time.Sleep(100 * time.Millisecond)
	report.Memory["after_cleanup_rss_bytes"], report.Memory["process_hwm_bytes"] = linuxRSS()
	var m runtime.MemStats
	runtime.ReadMemStats(&m)
	report.Memory["go_heap_alloc_bytes"] = m.HeapAlloc
	report.Memory["go_heap_sys_bytes"] = m.HeapSys
	if info, infoErr := observer.Info(ctx, "memory").Result(); infoErr == nil {
		report.RedisUsedMemoryBytes = infoNumber(info, "used_memory")
	}
	var totalErrors int
	latencyWithinObservedBudgets := true
	var rssMax uint64
	for _, p := range report.Phases {
		totalErrors += sumErrors(p.Errors)
		budget := 250.0
		if p.Endpoint == "create" {
			budget = 500
		}
		if p.Endpoint == "state" || p.Endpoint == "answer" {
			budget = 100
		}
		if p.P95MS > budget {
			latencyWithinObservedBudgets = false
		}
		if p.RSSP95Bytes > rssMax {
			rssMax = p.RSSP95Bytes
		}
	}
	report.BudgetAssessment = map[string]any{"measured_http_validation_errors": totalErrors, "cleanup_errors": report.CleanupErrors, "observed_ready_under_10s": report.StartupMS <= 10000, "observed_p95_latency_budgets_met": latencyWithinObservedBudgets, "observed_steady_rss_p95_under_128mib": rssMax <= 128*1024*1024, "maximum_phase_rss_p95_bytes": rssMax, "dedicated_target_node_approval": false}
	if totalErrors != 0 || report.CleanupErrors != 0 {
		return report, safeError("HTTP/validation/owned-session cleanup assertions")
	}
	return report, nil
}

type runner struct {
	ctx      context.Context
	o        options
	router   http.Handler
	pg       *store.PostgresQuestionStore
	redis    *redis.Client
	limit    int
	prefix   string
	sessions []string
}

func (b *runner) newSessions(count int, label string) []string {
	out := make([]string, count)
	for i := range out {
		out[i] = b.prefix + label + "-" + strconv.Itoa(i)
	}
	b.sessions = append(b.sessions, out...)
	return out
}

func localeAt(i int) string {
	if i%2 == 0 {
		return "en-US"
	}
	return "pt-BR"
}

func (b *runner) create(session, locale string) (requestResult, app.CreateRunOutput) {
	result := serve(b.router, "POST", "/api/runs", session, locale, map[string]any{"topic": "topic-01", "difficulty": 1})
	out := decodeCreated(&result, b.limit)
	return result, out
}

func (b *runner) prepareRuns(count int, label string) ([]app.CreateRunOutput, []string, error) {
	sessions := b.newSessions(count, label)
	runs := make([]app.CreateRunOutput, count)
	var failed atomic.Bool
	parallel(count, b.o.clients, func(i int) {
		r, created := b.create(sessions[i], localeAt(i))
		if r.status != http.StatusCreated || r.validation != "" {
			failed.Store(true)
		}
		runs[i] = created
	})
	if failed.Load() {
		return nil, sessions, safeError("prepare owned captured runs")
	}
	return runs, sessions, nil
}

func (b *runner) endpoint(name string) (phaseResult, error) {
	n := b.o.warmup + b.o.operations
	var op func(int) requestResult
	expectedStatus := http.StatusOK
	switch name {
	case "discovery":
		op = func(i int) requestResult {
			r := serve(b.router, "GET", "/api/themes?tag=benchmark", "", localeAt(i), nil)
			if r.status == http.StatusOK {
				var out struct {
					Themes []domain.Theme `json:"themes"`
				}
				if json.Unmarshal(r.body, &out) != nil || len(out.Themes) != 200 {
					r.validation = "invalid_theme_count"
				}
			}
			return r
		}
	case "catalog":
		op = func(i int) requestResult {
			r := serve(b.router, "GET", "/api/catalog", "", localeAt(i), nil)
			if r.status == http.StatusOK {
				var out domain.CatalogMetadata
				if json.Unmarshal(r.body, &out) != nil || len(out.Topics) != 10 || out.Locale != localeAt(i) {
					r.validation = "invalid_catalog_shape"
				}
			}
			return r
		}
	case "availability":
		_, sessions, err := b.prepareRuns(b.o.clients, "availability")
		if err != nil {
			return phaseResult{Endpoint: name, RunLimit: b.limit}, err
		}
		op = func(i int) requestResult {
			r := serve(b.router, "GET", "/api/session/difficulties?topic=topic-01", sessions[i%b.o.clients], localeAt(i), nil)
			if r.status == http.StatusOK {
				var out domain.SessionDifficulties
				if json.Unmarshal(r.body, &out) != nil || len(out.Difficulties) != 1 || out.Difficulties[0].QuestionCount != 10000 || out.Difficulties[0].AvailableQuestionCount != 9999 {
					r.validation = "invalid_available_count"
				}
			}
			return r
		}
	case "create":
		expectedStatus = http.StatusCreated
		sessions := b.newSessions(n, "create")
		op = func(i int) requestResult { r, _ := b.create(sessions[i], localeAt(i)); return r }
	case "state":
		runs, sessions, err := b.prepareRuns(b.o.clients, "state")
		if err != nil {
			return phaseResult{Endpoint: name, RunLimit: b.limit}, err
		}
		op = func(i int) requestResult {
			at := i % b.o.clients
			r := serve(b.router, "GET", "/api/runs/"+runs[at].RunID+"/state", sessions[at], localeAt(at), nil)
			if r.status == http.StatusOK {
				var out domain.RunState
				if json.Unmarshal(r.body, &out) != nil || out.Question == nil || out.Question.ID != runs[at].Question.ID || out.Total != b.limit || out.Answered != 0 {
					r.validation = "invalid_captured_state"
				}
			}
			return r
		}
	case "answer":
		runs, sessions, err := b.prepareRuns(n, "answer")
		if err != nil {
			return phaseResult{Endpoint: name, RunLimit: b.limit}, err
		}
		op = func(i int) requestResult {
			q := runs[i].Question
			r := serve(b.router, "POST", "/api/runs/"+runs[i].RunID+"/answers", sessions[i], localeAt(i), map[string]string{"questionId": q.ID, "optionId": q.Options[0].ID})
			if r.status == http.StatusOK {
				var out app.AnswerOutput
				if json.Unmarshal(r.body, &out) != nil || out.Finished || out.Question == nil || out.Question.Current != 2 || out.Question.Total != b.limit {
					r.validation = "invalid_captured_answer"
				}
			}
			return r
		}
	}
	var warmupFailed atomic.Bool
	parallel(b.o.warmup, b.o.clients, func(i int) {
		r := op(i)
		if r.status != expectedStatus || r.validation != "" {
			warmupFailed.Store(true)
		}
	})
	if warmupFailed.Load() {
		return phaseResult{Endpoint: name, RunLimit: b.limit}, safeError("warmup " + name)
	}
	beforeSQL := b.pg.QueryCount()
	beforePool := b.pg.PoolStats()
	beforeRedis, err := redisCommands(b.ctx, b.redis)
	if err != nil {
		return phaseResult{Endpoint: name, RunLimit: b.limit}, err
	}
	var samplesMu sync.Mutex
	samples := []uint64{}
	stop := make(chan struct{})
	done := make(chan struct{})
	go func() {
		defer close(done)
		ticker := time.NewTicker(20 * time.Millisecond)
		defer ticker.Stop()
		for {
			select {
			case <-ticker.C:
				rss, _ := linuxRSS()
				samplesMu.Lock()
				samples = append(samples, rss)
				samplesMu.Unlock()
			case <-stop:
				return
			}
		}
	}()
	results := make([]requestResult, b.o.operations)
	started := time.Now()
	parallel(b.o.operations, b.o.clients, func(i int) { r := op(i + b.o.warmup); r.body = nil; results[i] = r })
	wall := time.Since(started)
	close(stop)
	<-done
	afterSQL := b.pg.QueryCount()
	afterPool := b.pg.PoolStats()
	afterRedis, err := redisCommands(b.ctx, b.redis)
	if err != nil {
		return phaseResult{Endpoint: name, RunLimit: b.limit}, err
	}
	rss, _ := linuxRSS()
	samples = append(samples, rss)
	sort.Slice(samples, func(i, j int) bool { return samples[i] < samples[j] })
	phase := phaseResult{RunLimit: b.limit, Endpoint: name, Operations: b.o.operations, Warmup: b.o.warmup, Clients: b.o.clients, Errors: map[string]int{}, WallSeconds: wall.Seconds(), SQLQueries: afterSQL - beforeSQL, PoolAcquires: afterPool.AcquireCount() - beforePool.AcquireCount(), PoolEmptyAcquires: afterPool.EmptyAcquireCount() - beforePool.EmptyAcquireCount(), PoolAcquireDurationMS: float64((afterPool.AcquireDuration() - beforePool.AcquireDuration()).Microseconds()) / 1000, PoolCanceledAcquires: afterPool.CanceledAcquireCount() - beforePool.CanceledAcquireCount(), PoolMaxConnections: afterPool.MaxConns(), RedisCommands: map[string]int64{}}
	phase.SQLQueriesPerOperation = float64(phase.SQLQueries) / float64(phase.Operations)
	for command, after := range afterRedis {
		if command == "info" {
			continue
		}
		delta := after - beforeRedis[command]
		if delta > 0 {
			phase.RedisCommands[command] = delta
			phase.RedisCommandCount += delta
		}
	}
	latencies := make([]time.Duration, len(results))
	for i, r := range results {
		latencies[i] = r.elapsed
		if r.status != expectedStatus {
			phase.Errors["http_"+strconv.Itoa(r.status)]++
		}
		if r.validation != "" {
			phase.Errors[r.validation]++
		}
		if r.responseBytes > phase.ResponseBytesMax {
			phase.ResponseBytesMax = r.responseBytes
		}
	}
	sort.Slice(latencies, func(i, j int) bool { return latencies[i] < latencies[j] })
	phase.P50MS = durationMS(latencies, 0.50)
	phase.P95MS = durationMS(latencies, 0.95)
	phase.MaxMS = durationMS(latencies, 1)
	phase.RSSP50Bytes = quantileUint(samples, 0.50)
	phase.RSSP95Bytes = quantileUint(samples, 0.95)
	phase.RSSMaxBytes = quantileUint(samples, 1)
	return phase, nil
}

func (b *runner) cleanup() int {
	var failed atomic.Int64
	parallel(len(b.sessions), b.o.clients, func(i int) {
		r := serve(b.router, "POST", "/api/session/reset", b.sessions[i], "en-US", map[string]any{})
		if r.status != http.StatusOK {
			failed.Add(1)
		}
	})
	b.sessions = nil
	return int(failed.Load())
}

func parallel(n, clients int, work func(int)) {
	var next atomic.Int64
	var wg sync.WaitGroup
	for c := 0; c < clients; c++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for {
				i := int(next.Add(1) - 1)
				if i >= n {
					return
				}
				work(i)
			}
		}()
	}
	wg.Wait()
}

func durationMS(v []time.Duration, q float64) float64 {
	if len(v) == 0 {
		return 0
	}
	at := int(float64(len(v)-1) * q)
	return float64(v[at].Microseconds()) / 1000
}
func quantileUint(v []uint64, q float64) uint64 {
	if len(v) == 0 {
		return 0
	}
	return v[int(float64(len(v)-1)*q)]
}
func sumErrors(v map[string]int) int {
	n := 0
	for _, x := range v {
		n += x
	}
	return n
}

func linuxRSS() (rss, hwm uint64) {
	data, err := os.ReadFile("/proc/self/status")
	if err != nil {
		return
	}
	for _, line := range strings.Split(string(data), "\n") {
		f := strings.Fields(line)
		if len(f) < 2 {
			continue
		}
		n, _ := strconv.ParseUint(f[1], 10, 64)
		if f[0] == "VmRSS:" {
			rss = n * 1024
		}
		if f[0] == "VmHWM:" {
			hwm = n * 1024
		}
	}
	return
}

func infoNumber(info, key string) int64 {
	for _, line := range strings.Split(info, "\n") {
		if strings.HasPrefix(line, key+":") {
			n, _ := strconv.ParseInt(strings.TrimSpace(strings.TrimPrefix(line, key+":")), 10, 64)
			return n
		}
	}
	return 0
}

func redisCommands(ctx context.Context, client *redis.Client) (map[string]int64, error) {
	info, err := client.Info(ctx, "commandstats").Result()
	if err != nil {
		return nil, safeError("Redis command counters")
	}
	out := map[string]int64{}
	for _, line := range strings.Split(info, "\n") {
		if !strings.HasPrefix(line, "cmdstat_") {
			continue
		}
		parts := strings.SplitN(strings.TrimSpace(line), ":", 2)
		if len(parts) != 2 {
			continue
		}
		for _, field := range strings.Split(parts[1], ",") {
			if strings.HasPrefix(field, "calls=") {
				n, _ := strconv.ParseInt(strings.TrimPrefix(field, "calls="), 10, 64)
				out[strings.TrimPrefix(parts[0], "cmdstat_")] = n
			}
		}
	}
	return out, nil
}
