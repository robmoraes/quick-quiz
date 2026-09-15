package main

import (
	"context"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"

	"quickquiz/ads-api/internal/app"
	"quickquiz/ads-api/internal/config"
	"quickquiz/ads-api/internal/httpapi"
	"quickquiz/ads-api/internal/store"
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
	storageCtx, cancelStorage := context.WithTimeout(context.Background(), cfg.StorageStartupTimeout)
	adStore, catalogStore, err := loadStores(storageCtx, cfg)
	cancelStorage()
	if err != nil {
		logger.Error("failed to initialize content storage", "provider", cfg.AdsStorageProvider, "error", err)
		os.Exit(1)
	}
	publicService := app.NewPublicAdService(adStore, catalogStore)
	adminService := app.NewAdminAdService(adStore, catalogStore)

	server := &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           httpapi.NewRouter(publicService, adminService, logger, cfg.CORSAllowedOrigins),
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

func loadStores(ctx context.Context, cfg config.Config) (app.AdRepository, app.CatalogRepository, error) {
	switch strings.ToLower(strings.TrimSpace(cfg.AdsStorageProvider)) {
	case "", "local":
		return store.NewFileAdStore(cfg.AdsSource), store.NewFileCatalogStore(cfg.AdsSource), nil
	case "s3":
		adStore, catalogStore, err := store.NewS3Stores(ctx, store.S3ContentStoreConfig{
			Region:         cfg.S3.Region,
			Bucket:         cfg.S3.Bucket,
			Prefix:         cfg.S3.Prefix,
			EndpointURL:    cfg.S3.EndpointURL,
			ForcePathStyle: cfg.S3.ForcePathStyle,
		})
		if err != nil {
			return nil, nil, err
		}
		return adStore, catalogStore, nil
	default:
		return nil, nil, fmt.Errorf("unsupported ADS_STORAGE_PROVIDER %q: use local or s3", cfg.AdsStorageProvider)
	}
}

func parseLogLevel(value string) slog.Level {
	var level slog.Level
	if err := level.UnmarshalText([]byte(value)); err != nil {
		return slog.LevelInfo
	}
	return level
}
