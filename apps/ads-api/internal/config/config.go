package config

import (
	"bufio"
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

type Config struct {
	HTTPAddr              string
	HTTPReadHeaderTimeout time.Duration
	HTTPReadTimeout       time.Duration
	HTTPWriteTimeout      time.Duration
	HTTPIdleTimeout       time.Duration
	CORSAllowedOrigins    []string
	LogLevel              string
	StorageStartupTimeout time.Duration
	AdsStorageProvider    string
	AdsSource             string
	ShutdownTimeout       time.Duration
	S3                    S3Config
}

type S3Config struct {
	Region         string
	Bucket         string
	Prefix         string
	EndpointURL    string
	ForcePathStyle bool
}

func Load() (Config, error) {
	loadDotEnv(getEnv("ENV_FILE", ".env"))
	if err := loadFileEnvironment(); err != nil {
		return Config{}, err
	}

	return Config{
		HTTPAddr:              getEnv("HTTP_ADDR", ":8080"),
		HTTPReadHeaderTimeout: getEnvDuration("HTTP_READ_HEADER_TIMEOUT", 5*time.Second),
		HTTPReadTimeout:       getEnvDuration("HTTP_READ_TIMEOUT", 15*time.Second),
		HTTPWriteTimeout:      getEnvDuration("HTTP_WRITE_TIMEOUT", 15*time.Second),
		HTTPIdleTimeout:       getEnvDuration("HTTP_IDLE_TIMEOUT", 60*time.Second),
		CORSAllowedOrigins:    getEnvList("CORS_ALLOWED_ORIGINS", []string{"*"}),
		LogLevel:              strings.ToLower(strings.TrimSpace(getEnv("LOG_LEVEL", "info"))),
		StorageStartupTimeout: getEnvDuration("STORAGE_STARTUP_TIMEOUT", 30*time.Second),
		AdsStorageProvider:    strings.ToLower(strings.TrimSpace(getEnv("ADS_STORAGE_PROVIDER", "local"))),
		AdsSource:             getEnv("ADS_SOURCE", ".local"),
		ShutdownTimeout:       getEnvDuration("SHUTDOWN_TIMEOUT", 10*time.Second),
		S3: S3Config{
			Region:         getEnv("AWS_REGION", "us-east-1"),
			Bucket:         getEnv("S3_BUCKET", ""),
			Prefix:         getEnv("S3_PREFIX", "questions"),
			EndpointURL:    getEnv("S3_ENDPOINT_URL", ""),
			ForcePathStyle: getEnvBool("S3_FORCE_PATH_STYLE", false),
		},
	}, nil
}

func loadFileEnvironment() error {
	for _, item := range os.Environ() {
		name, filePath, ok := strings.Cut(item, "=")
		if !ok || !strings.HasSuffix(name, "__FILE") || strings.TrimSpace(filePath) == "" {
			continue
		}

		target := strings.TrimSuffix(name, "__FILE")
		if target == "" {
			return fmt.Errorf("invalid file-backed environment variable %q", name)
		}

		filePath = strings.TrimSpace(filePath)
		contents, err := os.ReadFile(filePath)
		if err != nil {
			return fmt.Errorf("read %s from %q: %w", name, filePath, err)
		}

		value := strings.TrimRight(string(contents), "\r\n")
		if value == "" {
			return fmt.Errorf("read %s from %q: secret file is empty", name, filePath)
		}
		if err := os.Setenv(target, value); err != nil {
			return fmt.Errorf("apply %s: %w", name, err)
		}
	}

	return nil
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

func getEnvList(key string, fallback []string) []string {
	value := os.Getenv(key)
	if value == "" {
		return fallback
	}

	parts := strings.Split(value, ",")
	items := make([]string, 0, len(parts))
	for _, part := range parts {
		item := strings.TrimSpace(part)
		if item != "" {
			items = append(items, item)
		}
	}
	if len(items) == 0 {
		return fallback
	}
	return items
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
