package config

import (
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

	config := Load()

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

	config := Load()

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

	config := Load()

	if config.LogLevel != "warn" || len(config.CORSAllowedOrigins) != 2 {
		t.Fatalf("unexpected logging or CORS configuration: %#v", config)
	}
	if config.HTTPReadHeaderTimeout != 7*time.Second || config.HTTPReadTimeout != 21*time.Second || config.HTTPWriteTimeout != 22*time.Second || config.HTTPIdleTimeout != 75*time.Second || config.StorageStartupTimeout != 40*time.Second {
		t.Fatalf("unexpected operational timeouts: %#v", config)
	}
}
