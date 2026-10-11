// runtime-benchmark is an opt-in, destructive-only-to-its-own-fixture capacity
// tool. It never accepts the normal QuickQuiz database or runtime environment.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"time"

	"github.com/jackc/pgx/v5"
)

const databaseName = "quickquiz_runtime_benchmark_test"
const readerName = "runtime_benchmark_reader"
const readerPassword = "synthetic-benchmark-only"
const fixtureMarker = "quickquiz-synthetic-runtime-v1"

type options struct {
	mode, databaseURL, redisAddr, migrationsDir, output, profile string
	questions, clients, operations, warmup, redisDB              int
	promptBytes, answerBytes                                     int
	applyFixture, disposableRedis                                bool
}

func parseOptions() options {
	var o options
	flag.StringVar(&o.mode, "mode", "measure", "seed or measure")
	flag.StringVar(&o.databaseURL, "database-url", os.Getenv("RUNTIME_BENCHMARK_DATABASE_URL"), "disposable database URL; prefer environment")
	flag.StringVar(&o.redisAddr, "redis-addr", os.Getenv("RUNTIME_BENCHMARK_REDIS_ADDR"), "disposable Redis host:port")
	flag.IntVar(&o.redisDB, "redis-db", 15, "must be isolated database 15")
	flag.BoolVar(&o.disposableRedis, "disposable-redis", false, "explicitly acknowledge an isolated disposable Redis")
	flag.StringVar(&o.migrationsDir, "migrations", "../manager/migrations", "versioned Manager SQL migrations")
	flag.StringVar(&o.output, "output", "runtime-benchmark.json", "safe JSON receipt, created mode 0600")
	flag.StringVar(&o.profile, "profile", "unclassified-local-host", "description only, never used to claim target equivalence")
	flag.IntVar(&o.questions, "questions", 100000, "synthetic canonical count, at least 100000")
	flag.IntVar(&o.clients, "clients", 10, "concurrent clients")
	flag.IntVar(&o.operations, "operations", 1000, "measured operations per endpoint and run limit")
	flag.IntVar(&o.warmup, "warmup", 100, "warm-up operations per endpoint and run limit")
	flag.IntVar(&o.promptBytes, "prompt-bytes", 256, "ASCII synthetic prompt bytes")
	flag.IntVar(&o.answerBytes, "answer-bytes", 96, "ASCII synthetic answer bytes")
	flag.BoolVar(&o.applyFixture, "apply-fixture", false, "allow fixture creation/extension in the guarded database")
	flag.Parse()
	return o
}

func validateOptions(o options) error {
	if o.mode != "seed" && o.mode != "measure" {
		return errors.New("mode must be seed or measure")
	}
	if o.databaseURL == "" {
		return errors.New("RUNTIME_BENCHMARK_DATABASE_URL is required")
	}
	c, err := pgx.ParseConfig(o.databaseURL)
	if err != nil || c.Database != databaseName {
		return errors.New("refusing database: exact disposable benchmark database name required")
	}
	if o.questions < 100000 || o.questions > 10000000 || o.clients < 1 || o.clients > 100 || o.operations < 1 || o.warmup < 1 || o.promptBytes < 64 || o.promptBytes > 4096 || o.answerBytes < 32 || o.answerBytes > 1024 {
		return errors.New("invalid fixture or measurement bounds")
	}
	if o.mode == "seed" && !o.applyFixture {
		return errors.New("fixture writes require --apply-fixture")
	}
	if o.mode == "measure" && (!o.disposableRedis || o.redisAddr == "" || o.redisDB != 15) {
		return errors.New("measure requires --disposable-redis, an explicit Redis address, and database 15")
	}
	return nil
}

func main() {
	o := parseOptions()
	if err := validateOptions(o); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(2)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Minute)
	defer cancel()
	started := time.Now().UTC()
	var data any
	var err error
	if o.mode == "seed" {
		data, err = seedFixture(ctx, o)
	} else {
		data, err = measure(ctx, o)
	}
	status := "completed"
	if err != nil {
		status = "failed"
	}
	receipt := map[string]any{
		"tool": "quickquiz-runtime-benchmark-v1", "status": status,
		"mode": o.mode, "started_at": started.Format(time.RFC3339Nano),
		"finished_at": time.Now().UTC().Format(time.RFC3339Nano),
		"go_version":  runtime.Version(), "architecture": runtime.GOARCH,
		"profile_description": o.profile, "data": data,
	}
	if err != nil {
		// Database/Redis driver errors can embed connection details. Report only
		// our safe stage label; never print an underlying driver error or URL.
		receipt["error"] = err.Error()
	}
	if writeErr := writeJSON(o.output, receipt); writeErr != nil {
		fmt.Fprintln(os.Stderr, "cannot write benchmark receipt")
		os.Exit(1)
	}
	fmt.Printf("status=%s mode=%s questions=%d receipt=%s\n", status, o.mode, o.questions, o.output)
	if err != nil {
		os.Exit(1)
	}
}

func writeJSON(path string, data any) error {
	if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
		return err
	}
	payload, err := json.MarshalIndent(data, "", "  ")
	if err != nil {
		return err
	}
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0600)
	if err != nil {
		return err
	}
	defer f.Close()
	if err = f.Chmod(0600); err != nil {
		return err
	}
	_, err = f.Write(append(payload, '\n'))
	return err
}

func safeError(stage string) error { return fmt.Errorf("benchmark stage failed: %s", stage) }
