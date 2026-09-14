package config

import (
	"bufio"
	"os"
	"strconv"
	"strings"
	"time"
)

type Config struct {
	HTTPAddr           string
	AdsStorageProvider string
	AdsSource          string
	ShutdownTimeout    time.Duration
	S3                 S3Config
}

type S3Config struct {
	Region         string
	Bucket         string
	Prefix         string
	EndpointURL    string
	ForcePathStyle bool
}

func Load() Config {
	loadDotEnv(getEnv("ENV_FILE", ".env"))

	return Config{
		HTTPAddr:           getEnv("HTTP_ADDR", ":8080"),
		AdsStorageProvider: strings.ToLower(strings.TrimSpace(getEnv("ADS_STORAGE_PROVIDER", "local"))),
		AdsSource:          getEnv("ADS_SOURCE", ".local"),
		ShutdownTimeout:    getEnvDuration("SHUTDOWN_TIMEOUT", 10*time.Second),
		S3: S3Config{
			Region:         getEnv("AWS_REGION", "us-east-1"),
			Bucket:         getEnv("S3_BUCKET", ""),
			Prefix:         getEnv("S3_PREFIX", "questions"),
			EndpointURL:    getEnv("S3_ENDPOINT_URL", ""),
			ForcePathStyle: getEnvBool("S3_FORCE_PATH_STYLE", false),
		},
	}
}

func loadDotEnv(path string) {
	file, err := os.Open(path)
	if err != nil {
		return
	}
	defer file.Close()

	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}

		key, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}

		key = strings.TrimSpace(key)
		value = strings.Trim(strings.TrimSpace(value), `"'`)
		if key != "" && os.Getenv(key) == "" {
			_ = os.Setenv(key, value)
		}
	}
}

func getEnv(key, fallback string) string {
	value := os.Getenv(key)
	if value == "" {
		return fallback
	}
	return value
}

func getEnvDuration(key string, fallback time.Duration) time.Duration {
	value := os.Getenv(key)
	if value == "" {
		return fallback
	}

	parsed, err := time.ParseDuration(value)
	if err != nil || parsed <= 0 {
		return fallback
	}
	return parsed
}

func getEnvBool(key string, fallback bool) bool {
	value := os.Getenv(key)
	if value == "" {
		return fallback
	}

	parsed, err := strconv.ParseBool(value)
	if err != nil {
		return fallback
	}
	return parsed
}
