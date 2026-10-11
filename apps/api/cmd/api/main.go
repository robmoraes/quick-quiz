package main

import (
	"context"
	"crypto/tls"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"

	"quickquiz/api/internal/app"
	"quickquiz/api/internal/config"
	"quickquiz/api/internal/httpapi"
	"quickquiz/api/internal/i18n"
	"quickquiz/api/internal/store"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		slog.Error("failed to load configuration", "error", err)
		os.Exit(1)
	}
	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{
		Level: parseLogLevel(cfg.LogLevel),
	}))
	if len(os.Args) > 1 {
		if err := runCommand(context.Background(), cfg, os.Args[1:]); err != nil {
			logger.Error("command failed", "error", err)
			os.Exit(1)
		}
		return
	}
	localeManager := i18n.NewManager(cfg.FallbackLocale, cfg.SupportedLocales)
	var postgres *store.PostgresQuestionStore
	if cfg.QuestionStorageProvider == "postgres" || cfg.SolutionPromptStorageProvider == "postgres" {
		postgres, err = store.NewPostgresQuestionStore(context.Background(), store.PostgresQuestionStoreConfig{
			URL: cfg.QuizDatabase.URL, MaxConnections: cfg.QuizDatabase.MaxConnections,
			ConnectTimeout: cfg.QuizDatabase.ConnectTimeout, QueryTimeout: cfg.QuizDatabase.QueryTimeout,
			MaxIdleTime: cfg.QuizDatabase.MaxIdleTime, FallbackLocale: localeManager.Fallback(), SupportedLocales: localeManager.Supported(),
		})
		if err != nil {
			logger.Error("failed to initialize PostgreSQL", "error", err)
			os.Exit(1)
		}
		defer postgres.Close()
	}

	storageCtx, cancelStorage := context.WithTimeout(context.Background(), cfg.StorageStartupTimeout)
	var questionStore app.QuestionRepository
	if cfg.QuestionStorageProvider == "postgres" {
		if err = postgres.Ready(storageCtx); err != nil {
			cancelStorage()
			logger.Error("PostgreSQL runtime is not ready", "error", err)
			os.Exit(1)
		}
		questionStore = postgres
	} else {
		dataset, loadErr := loadQuestionDataset(storageCtx, cfg, localeManager.Fallback(), localeManager.Supported())
		if loadErr != nil {
			cancelStorage()
			logger.Error("failed to load questions", "provider", cfg.QuestionStorageProvider, "error", loadErr)
			os.Exit(1)
		}
		memory := store.NewMemoryQuestionStoreWithThemeMetadata(dataset.Questions, dataset.Topics, dataset.Themes)
		memory.SetProvenanceProvider(cfg.QuestionStorageProvider)
		questionStore = memory
	}
	cancelStorage()
	runStore, closeRunStore, err := loadRunStore(context.Background(), cfg)
	if err != nil {
		logger.Error("failed to initialize run storage", "provider", cfg.RunStorageProvider, "error", err)
		os.Exit(1)
	}
	defer func() {
		if err := closeRunStore(); err != nil {
			logger.Error("failed to close run storage", "error", err)
		}
	}()
	if cfg.QuestionStorageProvider == "postgres" {
		if legacy, ok := runStore.(interface {
			CountLegacyDependent(context.Context) (int, error)
		}); ok {
			legacyCtx, cancelLegacy := context.WithTimeout(context.Background(), cfg.StorageStartupTimeout)
			count, countErr := legacy.CountLegacyDependent(legacyCtx)
			cancelLegacy()
			if countErr != nil || count != 0 {
				logger.Error("PostgreSQL cutover requires no legacy-provider-dependent runs", "remaining", count)
				os.Exit(1)
			}
		}
	}
	runService := app.NewRunService(questionStore, runStore, cfg.RunQuestionLimit, localeManager)
	solutionStore, closeSolutionStore, err := loadSolutionStore(context.Background(), cfg)
	if err != nil {
		logger.Error("failed to initialize solution storage", "provider", cfg.SolutionStorageProvider, "error", err)
		os.Exit(1)
	}
	defer func() {
		if err := closeSolutionStore(); err != nil {
			logger.Error("failed to close solution storage", "error", err)
		}
	}()
	promptCtx, cancelPrompt := context.WithTimeout(context.Background(), cfg.StorageStartupTimeout)
	promptSource, err := loadSolutionPromptSource(promptCtx, cfg, postgres)
	cancelPrompt()
	if err != nil {
		logger.Error("failed to initialize solution prompt storage", "provider", cfg.SolutionPromptStorageProvider, "error", err)
		os.Exit(1)
	}
	solutionGenerator := app.NewOpenAISolutionGenerator(app.OpenAISolutionGeneratorConfig{
		APIKey:       cfg.OpenAI.APIKey,
		BaseURL:      cfg.OpenAI.BaseURL,
		Model:        cfg.OpenAI.Model,
		Organization: cfg.OpenAI.Organization,
		Project:      cfg.OpenAI.Project,
		Timeout:      cfg.OpenAI.Timeout,
	}, nil, promptSource)
	solutionService := app.NewSolutionService(questionStore, runStore, solutionStore, solutionGenerator, localeManager)

	readiness := make([]httpapi.ReadinessCheck, 0, 4)
	if cfg.QuestionStorageProvider == "postgres" {
		readiness = append(readiness, postgres.Ready)
	}
	if cfg.SolutionPromptStorageProvider == "postgres" {
		readiness = append(readiness, postgres.PromptReady)
	}
	for _, dependency := range []any{runStore, solutionStore} {
		if check, ok := dependency.(interface{ Ready(context.Context) error }); ok {
			readiness = append(readiness, check.Ready)
		}
	}
	server := &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           httpapi.NewRouter(runService, solutionService, logger, cfg.CORSAllowedOrigins, readiness...),
		ReadHeaderTimeout: cfg.HTTPReadHeaderTimeout,
		ReadTimeout:       cfg.HTTPReadTimeout,
		WriteTimeout:      cfg.HTTPWriteTimeout,
		IdleTimeout:       cfg.HTTPIdleTimeout,
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	go func() {
		logger.Info("http server listening", "addr", cfg.HTTPAddr)
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			logger.Error("http server failed", "error", err)
			os.Exit(1)
		}
	}()

	<-ctx.Done()

	shutdownCtx, cancel := context.WithTimeout(context.Background(), cfg.ShutdownTimeout)
	defer cancel()

	logger.Info("shutting down http server")
	if err := server.Shutdown(shutdownCtx); err != nil {
		logger.Error("http server shutdown failed", "error", err)
		os.Exit(1)
	}
}

func loadSolutionPromptSource(ctx context.Context, cfg config.Config, postgres ...*store.PostgresQuestionStore) (app.SolutionPromptSource, error) {
	switch strings.ToLower(strings.TrimSpace(cfg.SolutionPromptStorageProvider)) {
	case "postgres":
		if len(postgres) == 0 || postgres[0] == nil {
			return nil, fmt.Errorf("PostgreSQL prompt storage is not initialized")
		}
		return postgres[0], nil
	case "", "local":
		return store.NewFileSolutionPromptSource(cfg.OpenAI.SolutionPromptFile), nil
	case "s3":
		return store.NewS3SolutionPromptSource(ctx, store.S3ContentSourceConfig{
			Region:         cfg.S3.Region,
			Bucket:         cfg.S3.Bucket,
			Prefix:         cfg.S3.Prefix,
			EndpointURL:    cfg.S3.EndpointURL,
			ForcePathStyle: cfg.S3.ForcePathStyle,
		})
	default:
		return nil, fmt.Errorf("unsupported SOLUTION_PROMPT_STORAGE_PROVIDER")
	}
}

func loadSolutionStore(ctx context.Context, cfg config.Config) (app.SolutionRepository, func() error, error) {
	switch strings.ToLower(strings.TrimSpace(cfg.SolutionStorageProvider)) {
	case "", "local":
		return store.NewFileSolutionStore(cfg.QuestionSource), func() error { return nil }, nil
	case "memory":
		return store.NewMemorySolutionStore(nil), func() error { return nil }, nil
	case "redis":
		var tlsConfig *tls.Config
		if cfg.Redis.TLS {
			tlsConfig = &tls.Config{MinVersion: tls.VersionTLS12}
		}

		connectCtx, cancel := context.WithTimeout(ctx, cfg.Redis.ConnectTimeout)
		defer cancel()
		solutionStore, err := store.NewRedisSolutionStore(connectCtx, store.RedisSolutionStoreConfig{
			Addr:      cfg.Redis.Addr,
			Username:  cfg.Redis.Username,
			Password:  cfg.Redis.Password,
			DB:        cfg.Redis.DB,
			TLSConfig: tlsConfig,
			KeyPrefix: cfg.Redis.SolutionKeyPrefix,
			TTL:       cfg.SolutionTTL,
		})
		if err != nil {
			return nil, nil, err
		}
		return solutionStore, solutionStore.Close, nil
	default:
		return nil, nil, fmt.Errorf("unsupported SOLUTION_STORAGE_PROVIDER %q: use local, memory, or redis", cfg.SolutionStorageProvider)
	}
}

func loadRunStore(ctx context.Context, cfg config.Config, disableBootstrap ...bool) (app.RunRepository, func() error, error) {
	switch strings.ToLower(strings.TrimSpace(cfg.RunStorageProvider)) {
	case "", "memory":
		return store.NewMemoryRunStore(cfg.SessionTTL), func() error { return nil }, nil
	case "redis":
		var tlsConfig *tls.Config
		if cfg.Redis.TLS {
			tlsConfig = &tls.Config{MinVersion: tls.VersionTLS12}
		}

		connectCtx, cancel := context.WithTimeout(ctx, cfg.Redis.ConnectTimeout)
		defer cancel()
		runStore, err := store.NewRedisRunStore(connectCtx, store.RedisRunStoreConfig{
			Addr:                  cfg.Redis.Addr,
			Username:              cfg.Redis.Username,
			Password:              cfg.Redis.Password,
			DB:                    cfg.Redis.DB,
			TLSConfig:             tlsConfig,
			KeyPrefix:             cfg.Redis.KeyPrefix,
			TTL:                   cfg.SessionTTL,
			DisableIndexBootstrap: len(disableBootstrap) > 0 && disableBootstrap[0],
		})
		if err != nil {
			return nil, nil, err
		}
		return runStore, runStore.Close, nil
	default:
		return nil, nil, fmt.Errorf("unsupported RUN_STORAGE_PROVIDER %q: use memory or redis", cfg.RunStorageProvider)
	}
}

func loadQuestionDataset(ctx context.Context, cfg config.Config, fallbackLocale string, supportedLocales []string) (store.QuestionDataset, error) {
	switch strings.ToLower(strings.TrimSpace(cfg.QuestionStorageProvider)) {
	case "", "local":
		return store.LoadQuestionDatasetFromRootWithFallback(cfg.QuestionSource, fallbackLocale, supportedLocales)
	case "s3":
		return store.LoadQuestionDatasetFromS3WithFallback(ctx, store.S3ContentSourceConfig{
			Region:         cfg.S3.Region,
			Bucket:         cfg.S3.Bucket,
			Prefix:         cfg.S3.Prefix,
			EndpointURL:    cfg.S3.EndpointURL,
			ForcePathStyle: cfg.S3.ForcePathStyle,
		}, fallbackLocale, supportedLocales)
	default:
		return store.QuestionDataset{}, fmt.Errorf("unsupported QUESTION_STORAGE_PROVIDER %q: use local or s3", cfg.QuestionStorageProvider)
	}
}

func parseLogLevel(value string) slog.Level {
	var level slog.Level
	if err := level.UnmarshalText([]byte(value)); err != nil {
		return slog.LevelInfo
	}
	return level
}
