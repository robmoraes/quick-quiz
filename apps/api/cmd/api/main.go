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
	"time"

	"quickquiz/api/internal/app"
	"quickquiz/api/internal/config"
	"quickquiz/api/internal/httpapi"
	"quickquiz/api/internal/i18n"
	"quickquiz/api/internal/store"
)

func main() {
	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{
		Level: slog.LevelInfo,
	}))

	cfg := config.Load()
	localeManager := i18n.NewManager(cfg.FallbackLocale, cfg.SupportedLocales)

	questionDataset, err := loadQuestionDataset(context.Background(), cfg, localeManager.Fallback(), localeManager.Supported())
	if err != nil {
		logger.Error("failed to load questions", "provider", cfg.QuestionStorageProvider, "error", err)
		os.Exit(1)
	}

	questionStore := store.NewMemoryQuestionStoreWithThemeMetadata(questionDataset.Questions, questionDataset.Topics, questionDataset.Themes)
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
	promptSource, err := loadSolutionPromptSource(context.Background(), cfg)
	if err != nil {
		logger.Error("failed to initialize solution prompt storage", "provider", cfg.QuestionStorageProvider, "error", err)
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

	server := &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           httpapi.NewRouter(runService, solutionService, logger),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      15 * time.Second,
		IdleTimeout:       60 * time.Second,
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

func loadSolutionPromptSource(ctx context.Context, cfg config.Config) (app.SolutionPromptSource, error) {
	switch strings.ToLower(strings.TrimSpace(cfg.QuestionStorageProvider)) {
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
		return nil, fmt.Errorf("unsupported QUESTION_STORAGE_PROVIDER %q: use local or s3", cfg.QuestionStorageProvider)
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

		connectCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
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

func loadRunStore(ctx context.Context, cfg config.Config) (app.RunRepository, func() error, error) {
	switch strings.ToLower(strings.TrimSpace(cfg.RunStorageProvider)) {
	case "", "memory":
		return store.NewMemoryRunStore(cfg.SessionTTL), func() error { return nil }, nil
	case "redis":
		var tlsConfig *tls.Config
		if cfg.Redis.TLS {
			tlsConfig = &tls.Config{MinVersion: tls.VersionTLS12}
		}

		connectCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
		defer cancel()
		runStore, err := store.NewRedisRunStore(connectCtx, store.RedisRunStoreConfig{
			Addr:      cfg.Redis.Addr,
			Username:  cfg.Redis.Username,
			Password:  cfg.Redis.Password,
			DB:        cfg.Redis.DB,
			TLSConfig: tlsConfig,
			KeyPrefix: cfg.Redis.KeyPrefix,
			TTL:       cfg.SessionTTL,
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
