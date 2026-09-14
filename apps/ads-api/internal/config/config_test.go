package config

import "testing"

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
