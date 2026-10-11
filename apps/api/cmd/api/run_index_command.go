package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"io"
	"os"
	"time"

	"quickquiz/api/internal/config"
	"quickquiz/api/internal/store"
)

func runCommand(ctx context.Context, cfg config.Config, args []string) error {
	if len(args) == 0 || args[0] != "migrate-run-index" {
		return errors.New("supported command: migrate-run-index --dry-run|--apply [--confirm-legacy-writers-stopped]")
	}
	flags := flag.NewFlagSet("migrate-run-index", flag.ContinueOnError)
	flags.SetOutput(io.Discard)
	dry := flags.Bool("dry-run", false, "validate without writes")
	apply := flags.Bool("apply", false, "build the session index")
	stopped := flags.Bool("confirm-legacy-writers-stopped", false, "operator confirms all pre-index API writers are stopped")
	batch := flags.Int("batch-size", 100, "bounded Redis SCAN batch")
	if flags.Parse(args[1:]) != nil || flags.NArg() != 0 || *dry == *apply {
		return errors.New("choose exactly one of --dry-run or --apply")
	}
	if *apply && !*stopped {
		return errors.New("--apply requires --confirm-legacy-writers-stopped")
	}
	if cfg.RunStorageProvider != "redis" {
		return errors.New("migrate-run-index requires RUN_STORAGE_PROVIDER=redis")
	}
	ctx, cancel := context.WithTimeout(ctx, 30*time.Minute)
	defer cancel()
	repository, closeStore, err := loadRunStore(ctx, cfg, true)
	if err != nil {
		return err
	}
	defer closeStore()
	redisStore, ok := repository.(*store.RedisRunStore)
	if !ok {
		return errors.New("Redis run storage required")
	}
	report, err := redisStore.MigrateRunIndex(ctx, store.RunIndexMigrationOptions{Apply: *apply, ConfirmLegacyWritersStopped: *stopped, BatchSize: *batch})
	if err != nil {
		return err
	}
	return json.NewEncoder(os.Stdout).Encode(report)
}
