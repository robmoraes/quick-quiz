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
	"time"

	"quickquiz/ads-api/internal/app"
	"quickquiz/ads-api/internal/config"
	"quickquiz/ads-api/internal/httpapi"
	"quickquiz/ads-api/internal/store"
)

func main() {
	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{
		Level: slog.LevelInfo,
	}))

	cfg := config.Load()
	adStore, catalogStore, err := loadStores(context.Background(), cfg)
	if err != nil {
		logger.Error("failed to initialize content storage", "provider", cfg.AdsStorageProvider, "error", err)
		os.Exit(1)
	}
	publicService := app.NewPublicAdService(adStore, catalogStore)
	adminService := app.NewAdminAdService(adStore, catalogStore)

	server := &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           httpapi.NewRouter(publicService, adminService, logger),
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

func loadStores(ctx context.Context, cfg config.Config) (app.AdRepository, app.CatalogRepository, error) {
	switch strings.ToLower(strings.TrimSpace(cfg.AdsStorageProvider)) {
	case "", "local":
		return store.NewFileAdStore(cfg.AdsSource), store.NewFileCatalogStore(cfg.AdsSource), nil
	case "s3":
		connectCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
		defer cancel()
		adStore, catalogStore, err := store.NewS3Stores(connectCtx, store.S3ContentStoreConfig{
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
