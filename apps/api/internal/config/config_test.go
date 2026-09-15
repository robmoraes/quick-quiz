package config

import (
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

	config := Load()

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

	config := Load()

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

	config := Load()

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

	config := Load()

	if config.RunStorageProvider != "memory" {
		t.Fatalf("expected memory provider, got %q", config.RunStorageProvider)
	}
}

func TestLoadDefaultsSolutionStorageToLocal(t *testing.T) {
	t.Setenv("ENV_FILE", t.TempDir()+"/missing.env")
	t.Setenv("SOLUTION_STORAGE_PROVIDER", "")

	config := Load()

	if config.SolutionStorageProvider != "local" {
		t.Fatalf("expected local solution provider, got %q", config.SolutionStorageProvider)
	}
}

func TestLoadReadsRedisSolutionStorageConfiguration(t *testing.T) {
	t.Setenv("ENV_FILE", t.TempDir()+"/missing.env")
	t.Setenv("SOLUTION_STORAGE_PROVIDER", " Redis ")
	t.Setenv("SOLUTION_TTL", "48h")
	t.Setenv("REDIS_SOLUTION_KEY_PREFIX", "test:solutions:")

	config := Load()

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
