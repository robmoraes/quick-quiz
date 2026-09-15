package config

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestLoadReadsS3StorageConfiguration(t *testing.T) {
	t.Setenv("ENV_FILE", t.TempDir()+"/missing.env")
	t.Setenv("ADS_STORAGE_PROVIDER", " S3 ")
	t.Setenv("AWS_REGION", "us-east-1")
	t.Setenv("S3_BUCKET", "quickquiz-content")
	t.Setenv("S3_PREFIX", "content/beta")
	t.Setenv("S3_ENDPOINT_URL", "http://localhost:9000")
	t.Setenv("S3_FORCE_PATH_STYLE", "true")

	config := loadConfig(t)

	if config.AdsStorageProvider != "s3" {
		t.Fatalf("expected s3 provider, got %q", config.AdsStorageProvider)
	}
	if config.S3.Region != "us-east-1" || config.S3.Bucket != "quickquiz-content" || config.S3.Prefix != "content/beta" {
		t.Fatalf("unexpected S3 location: %#v", config.S3)
	}
	if config.S3.EndpointURL != "http://localhost:9000" || !config.S3.ForcePathStyle {
		t.Fatalf("unexpected S3 endpoint configuration: %#v", config.S3)
	}
}

func TestLoadDefaultsStorageToLocal(t *testing.T) {
	t.Setenv("ENV_FILE", t.TempDir()+"/missing.env")
	t.Setenv("ADS_STORAGE_PROVIDER", "")

	config := loadConfig(t)

	if config.AdsStorageProvider != "local" {
		t.Fatalf("expected local provider, got %q", config.AdsStorageProvider)
	}
}

func TestLoadReadsOperationalConfiguration(t *testing.T) {
	t.Setenv("ENV_FILE", t.TempDir()+"/missing.env")
	t.Setenv("LOG_LEVEL", "warn")
	t.Setenv("CORS_ALLOWED_ORIGINS", "https://dev.example.com, https://dslab.example.com")
	t.Setenv("HTTP_READ_HEADER_TIMEOUT", "7s")
	t.Setenv("HTTP_READ_TIMEOUT", "21s")
	t.Setenv("HTTP_WRITE_TIMEOUT", "22s")
	t.Setenv("HTTP_IDLE_TIMEOUT", "75s")
	t.Setenv("STORAGE_STARTUP_TIMEOUT", "40s")

	config := loadConfig(t)

	if config.LogLevel != "warn" || len(config.CORSAllowedOrigins) != 2 {
		t.Fatalf("unexpected logging or CORS configuration: %#v", config)
	}
	if config.HTTPReadHeaderTimeout != 7*time.Second || config.HTTPReadTimeout != 21*time.Second || config.HTTPWriteTimeout != 22*time.Second || config.HTTPIdleTimeout != 75*time.Second || config.StorageStartupTimeout != 40*time.Second {
		t.Fatalf("unexpected operational timeouts: %#v", config)
	}
}

func loadConfig(t *testing.T) Config {
	t.Helper()

	config, err := Load()
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}
	return config
}

func TestLoadFileEnvironmentTakesPriority(t *testing.T) {
	secretFile := filepath.Join(t.TempDir(), "aws-secret-access-key")
	if err := os.WriteFile(secretFile, []byte("from-file\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("ENV_FILE", filepath.Join(t.TempDir(), "missing.env"))
	t.Setenv("AWS_SECRET_ACCESS_KEY", "from-environment")
	t.Setenv("AWS_SECRET_ACCESS_KEY__FILE", secretFile)

	loadConfig(t)

	if got := os.Getenv("AWS_SECRET_ACCESS_KEY"); got != "from-file" {
		t.Fatalf("expected file-backed AWS secret, got %q", got)
	}
}
