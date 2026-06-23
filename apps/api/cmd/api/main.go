package main

import (
	"context"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
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

	questionDataset, err := store.LoadQuestionDatasetFromRootWithFallback(cfg.QuestionSource, localeManager.Fallback(), localeManager.Supported())
	if err != nil {
		logger.Error("failed to load questions", "source", cfg.QuestionSource, "error", err)
		os.Exit(1)
	}

	questionStore := store.NewMemoryQuestionStoreWithThemeMetadata(questionDataset.Questions, questionDataset.Topics, questionDataset.Themes)
	runStore := store.NewMemoryRunStore(cfg.SessionTTL)
	runService := app.NewRunService(questionStore, runStore, cfg.RunQuestionLimit, localeManager)
	solutionStore := store.NewFileSolutionStore(cfg.QuestionSource)
	solutionGenerator := app.NewOpenAISolutionGenerator(app.OpenAISolutionGeneratorConfig{
		APIKey:       cfg.OpenAI.APIKey,
		BaseURL:      cfg.OpenAI.BaseURL,
		Model:        cfg.OpenAI.Model,
		Organization: cfg.OpenAI.Organization,
		Project:      cfg.OpenAI.Project,
		PromptFile:   cfg.OpenAI.SolutionPromptFile,
		Timeout:      cfg.OpenAI.Timeout,
	}, nil)
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
