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
	HTTPAddr                string
	HTTPReadHeaderTimeout   time.Duration
	HTTPReadTimeout         time.Duration
	HTTPWriteTimeout        time.Duration
	HTTPIdleTimeout         time.Duration
	CORSAllowedOrigins      []string
	LogLevel                string
	StorageStartupTimeout   time.Duration
	RunQuestionLimit        int
	RunStorageProvider      string
	SolutionStorageProvider string
	QuestionStorageProvider string
	QuestionSource          string
	FallbackLocale          string
	SupportedLocales        []string
	SessionTTL              time.Duration
	SolutionTTL             time.Duration
	ShutdownTimeout         time.Duration
	Redis                   RedisConfig
	S3                      S3Config
	OpenAI                  OpenAIConfig
}

type RedisConfig struct {
	Addr              string
	ConnectTimeout    time.Duration
	Username          string
	Password          string
	DB                int
	TLS               bool
	KeyPrefix         string
	SolutionKeyPrefix string
}

type S3Config struct {
	Region         string
	Bucket         string
	Prefix         string
	EndpointURL    string
	ForcePathStyle bool
}

type OpenAIConfig struct {
	APIKey             string
	BaseURL            string
	Model              string
	Organization       string
	Project            string
	SolutionPromptFile string
	Timeout            time.Duration
}

func Load() (Config, error) {
	loadDotEnv(getEnv("ENV_FILE", ".env"))
	if err := loadFileEnvironment(); err != nil {
		return Config{}, err
	}

	return Config{
		HTTPAddr:                getEnv("HTTP_ADDR", ":8080"),
		HTTPReadHeaderTimeout:   getEnvDuration("HTTP_READ_HEADER_TIMEOUT", 5*time.Second),
		HTTPReadTimeout:         getEnvDuration("HTTP_READ_TIMEOUT", 15*time.Second),
		HTTPWriteTimeout:        getEnvDuration("HTTP_WRITE_TIMEOUT", 15*time.Second),
		HTTPIdleTimeout:         getEnvDuration("HTTP_IDLE_TIMEOUT", 60*time.Second),
		CORSAllowedOrigins:      getEnvList("CORS_ALLOWED_ORIGINS", []string{"*"}),
		LogLevel:                strings.ToLower(strings.TrimSpace(getEnv("LOG_LEVEL", "info"))),
		StorageStartupTimeout:   getEnvDuration("STORAGE_STARTUP_TIMEOUT", 30*time.Second),
		RunQuestionLimit:        getEnvInt("RUN_QUESTION_LIMIT", 10),
		RunStorageProvider:      strings.ToLower(strings.TrimSpace(getEnv("RUN_STORAGE_PROVIDER", "memory"))),
		SolutionStorageProvider: strings.ToLower(strings.TrimSpace(getEnv("SOLUTION_STORAGE_PROVIDER", "local"))),
		QuestionStorageProvider: strings.ToLower(strings.TrimSpace(getEnv("QUESTION_STORAGE_PROVIDER", "local"))),
		QuestionSource:          getEnv("QUESTION_SOURCE", ".local"),
		FallbackLocale:          getEnv("FALLBACK_LOCALE", "en-US"),
		SupportedLocales:        getEnvList("SUPPORTED_LOCALES", []string{"en-US", "pt-BR"}),
		SessionTTL:              getEnvDuration("SESSION_TTL", 30*time.Minute),
		SolutionTTL:             getEnvDuration("SOLUTION_TTL", 7*24*time.Hour),
		ShutdownTimeout:         getEnvDuration("SHUTDOWN_TIMEOUT", 10*time.Second),
		Redis: RedisConfig{
			Addr:              getEnv("REDIS_ADDR", "127.0.0.1:6379"),
			ConnectTimeout:    getEnvDuration("REDIS_CONNECT_TIMEOUT", 5*time.Second),
			Username:          getEnv("REDIS_USERNAME", ""),
			Password:          getEnv("REDIS_PASSWORD", ""),
			DB:                getEnvInt("REDIS_DB", 0),
			TLS:               getEnvBool("REDIS_TLS", false),
			KeyPrefix:         getEnv("REDIS_KEY_PREFIX", "quickquiz:runs:"),
			SolutionKeyPrefix: getEnv("REDIS_SOLUTION_KEY_PREFIX", "quickquiz:solutions:"),
		},
		S3: S3Config{
			Region:         getEnv("AWS_REGION", "us-east-1"),
			Bucket:         getEnv("S3_BUCKET", ""),
			Prefix:         getEnv("S3_PREFIX", "questions"),
			EndpointURL:    getEnv("S3_ENDPOINT_URL", ""),
			ForcePathStyle: getEnvBool("S3_FORCE_PATH_STYLE", false),
		},
		OpenAI: OpenAIConfig{
			APIKey:             getEnv("OPENAI_API_KEY", ""),
			BaseURL:            getEnv("OPENAI_BASE_URL", "https://api.openai.com/v1"),
			Model:              getEnv("OPENAI_MODEL", "gpt-5.4-mini"),
			Organization:       getEnv("OPENAI_ORGANIZATION", ""),
			Project:            getEnv("OPENAI_PROJECT", ""),
			SolutionPromptFile: getEnv("OPENAI_SOLUTION_PROMPT_FILE", ".local/{{theme}}/ai-prompts/question-solution-prompt.txt"),
			Timeout:            getEnvDuration("OPENAI_TIMEOUT", 30*time.Second),
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

func getEnvInt(key string, fallback int) int {
	value := os.Getenv(key)
	if value == "" {
		return fallback
	}

	parsed, err := strconv.Atoi(value)
	if err != nil || parsed <= 0 {
		return fallback
	}

	return parsed
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
