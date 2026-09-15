package config

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestLoadReadsS3QuestionStorageConfiguration(t *testing.T) {
	t.Setenv("ENV_FILE", t.TempDir()+"/missing.env")
	t.Setenv("QUESTION_STORAGE_PROVIDER", " S3 ")
	t.Setenv("AWS_REGION", "us-east-1")
	t.Setenv("S3_BUCKET", "quickquiz-questions")
	t.Setenv("S3_PREFIX", "questions/dev")
	t.Setenv("S3_ENDPOINT_URL", "http://localhost:9000")
	t.Setenv("S3_FORCE_PATH_STYLE", "true")

	config := loadConfig(t)

	if config.QuestionStorageProvider != "s3" {
		t.Fatalf("expected s3 provider, got %q", config.QuestionStorageProvider)
	}
	if config.S3.Region != "us-east-1" || config.S3.Bucket != "quickquiz-questions" || config.S3.Prefix != "questions/dev" {
		t.Fatalf("unexpected S3 location: %#v", config.S3)
	}
	if config.S3.EndpointURL != "http://localhost:9000" || !config.S3.ForcePathStyle {
		t.Fatalf("unexpected S3 endpoint configuration: %#v", config.S3)
	}
}

func TestLoadDefaultsQuestionStorageToLocal(t *testing.T) {
	t.Setenv("ENV_FILE", t.TempDir()+"/missing.env")
	t.Setenv("QUESTION_STORAGE_PROVIDER", "")

	config := loadConfig(t)

	if config.QuestionStorageProvider != "local" {
		t.Fatalf("expected local provider, got %q", config.QuestionStorageProvider)
	}
}

func TestLoadReadsRedisRunStorageConfiguration(t *testing.T) {
	t.Setenv("ENV_FILE", t.TempDir()+"/missing.env")
	t.Setenv("RUN_STORAGE_PROVIDER", " Redis ")
	t.Setenv("REDIS_ADDR", "redis:6379")
	t.Setenv("REDIS_USERNAME", "quickquiz")
	t.Setenv("REDIS_PASSWORD", "secret")
	t.Setenv("REDIS_DB", "2")
	t.Setenv("REDIS_TLS", "true")
	t.Setenv("REDIS_KEY_PREFIX", "test:runs:")

	config := loadConfig(t)

	if config.RunStorageProvider != "redis" {
		t.Fatalf("expected redis provider, got %q", config.RunStorageProvider)
	}
	if config.Redis.Addr != "redis:6379" || config.Redis.Username != "quickquiz" || config.Redis.Password != "secret" {
		t.Fatalf("unexpected Redis connection configuration: %#v", config.Redis)
	}
	if config.Redis.DB != 2 || !config.Redis.TLS || config.Redis.KeyPrefix != "test:runs:" {
		t.Fatalf("unexpected Redis storage configuration: %#v", config.Redis)
	}
}

func TestLoadDefaultsRunStorageToMemory(t *testing.T) {
	t.Setenv("ENV_FILE", t.TempDir()+"/missing.env")
	t.Setenv("RUN_STORAGE_PROVIDER", "")

	config := loadConfig(t)

	if config.RunStorageProvider != "memory" {
		t.Fatalf("expected memory provider, got %q", config.RunStorageProvider)
	}
}

func TestLoadDefaultsSolutionStorageToLocal(t *testing.T) {
	t.Setenv("ENV_FILE", t.TempDir()+"/missing.env")
	t.Setenv("SOLUTION_STORAGE_PROVIDER", "")

	config := loadConfig(t)

	if config.SolutionStorageProvider != "local" {
		t.Fatalf("expected local solution provider, got %q", config.SolutionStorageProvider)
	}
}

func TestLoadReadsRedisSolutionStorageConfiguration(t *testing.T) {
	t.Setenv("ENV_FILE", t.TempDir()+"/missing.env")
	t.Setenv("SOLUTION_STORAGE_PROVIDER", " Redis ")
	t.Setenv("SOLUTION_TTL", "48h")
	t.Setenv("REDIS_SOLUTION_KEY_PREFIX", "test:solutions:")

	config := loadConfig(t)

	if config.SolutionStorageProvider != "redis" {
		t.Fatalf("expected Redis solution provider, got %q", config.SolutionStorageProvider)
	}
	if config.SolutionTTL != 48*time.Hour {
		t.Fatalf("expected 48 hour solution TTL, got %s", config.SolutionTTL)
	}
	if config.Redis.SolutionKeyPrefix != "test:solutions:" {
		t.Fatalf("unexpected Redis solution key prefix: %q", config.Redis.SolutionKeyPrefix)
	}
}

func TestLoadReadsOperationalConfiguration(t *testing.T) {
	t.Setenv("ENV_FILE", t.TempDir()+"/missing.env")
	t.Setenv("LOG_LEVEL", "debug")
	t.Setenv("CORS_ALLOWED_ORIGINS", "https://dev.example.com, https://dslab.example.com")
	t.Setenv("HTTP_READ_HEADER_TIMEOUT", "7s")
	t.Setenv("HTTP_READ_TIMEOUT", "21s")
	t.Setenv("HTTP_WRITE_TIMEOUT", "22s")
	t.Setenv("HTTP_IDLE_TIMEOUT", "75s")
	t.Setenv("STORAGE_STARTUP_TIMEOUT", "40s")
	t.Setenv("REDIS_CONNECT_TIMEOUT", "8s")

	config := loadConfig(t)

	if config.LogLevel != "debug" {
		t.Fatalf("unexpected log level: %q", config.LogLevel)
	}
	if len(config.CORSAllowedOrigins) != 2 || config.CORSAllowedOrigins[1] != "https://dslab.example.com" {
		t.Fatalf("unexpected CORS origins: %#v", config.CORSAllowedOrigins)
	}
	if config.HTTPReadHeaderTimeout != 7*time.Second || config.HTTPReadTimeout != 21*time.Second || config.HTTPWriteTimeout != 22*time.Second || config.HTTPIdleTimeout != 75*time.Second {
		t.Fatalf("unexpected HTTP timeouts: %#v", config)
	}
	if config.StorageStartupTimeout != 40*time.Second || config.Redis.ConnectTimeout != 8*time.Second {
		t.Fatalf("unexpected dependency timeouts: %#v", config)
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
	secretFile := filepath.Join(t.TempDir(), "redis-password")
	if err := os.WriteFile(secretFile, []byte("from-file\r\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("ENV_FILE", filepath.Join(t.TempDir(), "missing.env"))
	t.Setenv("REDIS_PASSWORD", "from-environment")
	t.Setenv("REDIS_PASSWORD__FILE", secretFile)

	config := loadConfig(t)

	if config.Redis.Password != "from-file" {
		t.Fatalf("expected file-backed password, got %q", config.Redis.Password)
	}
}

func TestLoadFileEnvironmentFallsBackWhenFileVariableIsEmpty(t *testing.T) {
	t.Setenv("ENV_FILE", filepath.Join(t.TempDir(), "missing.env"))
	t.Setenv("REDIS_PASSWORD", "from-environment")
	t.Setenv("REDIS_PASSWORD__FILE", "")

	config := loadConfig(t)

	if config.Redis.Password != "from-environment" {
		t.Fatalf("expected environment fallback, got %q", config.Redis.Password)
	}
}

func TestLoadFileEnvironmentFailsForEmptyFile(t *testing.T) {
	secretFile := filepath.Join(t.TempDir(), "empty-secret")
	if err := os.WriteFile(secretFile, nil, 0o600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("ENV_FILE", filepath.Join(t.TempDir(), "missing.env"))
	t.Setenv("QUICKQUIZ_EMPTY_SECRET__FILE", secretFile)

	if _, err := Load(); err == nil {
		t.Fatal("expected empty secret file to fail configuration loading")
	}
}

func TestLoadFileEnvironmentFailsForMissingFile(t *testing.T) {
	t.Setenv("ENV_FILE", filepath.Join(t.TempDir(), "missing.env"))
	t.Setenv("QUICKQUIZ_MISSING_SECRET__FILE", filepath.Join(t.TempDir(), "missing-secret"))

	if _, err := Load(); err == nil {
		t.Fatal("expected missing secret file to fail configuration loading")
	}
}
