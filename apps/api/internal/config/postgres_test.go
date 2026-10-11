package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestPostgresConfigurationUsesFileSecretAndInheritedPromptProvider(t *testing.T) {
	t.Setenv("ENV_FILE", filepath.Join(t.TempDir(), "absent"))
	t.Setenv("QUESTION_STORAGE_PROVIDER", "postgres")
	t.Setenv("SOLUTION_PROMPT_STORAGE_PROVIDER", "")
	t.Setenv("QUIZ_DATABASE_URL", "postgres://unused")
	path := filepath.Join(t.TempDir(), "database-url")
	secret := "postgresql://reader:local-test@db:5432/quiz"
	if err := os.WriteFile(path, []byte(secret+"\n"), 0600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("QUIZ_DATABASE_URL__FILE", path)
	cfg, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if cfg.QuizDatabase.URL != secret || cfg.SolutionPromptStorageProvider != "postgres" || cfg.QuizDatabase.MaxConnections != 4 || cfg.QuizDatabase.QueryTimeout != 2*time.Second {
		t.Fatal("unexpected PostgreSQL defaults/secret precedence")
	}
}
func TestPostgresRequiresCredentialAndRejectsInvalidLimits(t *testing.T) {
	t.Setenv("ENV_FILE", filepath.Join(t.TempDir(), "absent"))
	t.Setenv("QUESTION_STORAGE_PROVIDER", "postgres")
	t.Setenv("QUIZ_DATABASE_URL", "")
	t.Setenv("QUIZ_DATABASE_URL__FILE", "")
	if _, err := Load(); err == nil {
		t.Fatal("expected missing reader credential")
	}
	t.Setenv("QUIZ_DATABASE_URL", "postgresql://reader:private-value@localhost/quiz")
	for _, test := range []struct{ name, value string }{{"QUIZ_DATABASE_MAX_CONNECTIONS", "0"}, {"QUIZ_DATABASE_MAX_CONNECTIONS", "2147483648"}, {"QUIZ_DATABASE_QUERY_TIMEOUT", "invalid"}, {"QUIZ_DATABASE_CONNECT_TIMEOUT", "-1s"}} {
		t.Run(test.name+test.value, func(t *testing.T) {
			t.Setenv(test.name, test.value)
			_, err := Load()
			if err == nil || strings.Contains(err.Error(), "private-value") {
				t.Fatal("expected safe validation failure")
			}
		})
	}
}
func TestPromptProviderCanBeSelectedIndependently(t *testing.T) {
	t.Setenv("ENV_FILE", filepath.Join(t.TempDir(), "absent"))
	t.Setenv("QUESTION_STORAGE_PROVIDER", "postgres")
	t.Setenv("QUIZ_DATABASE_URL", "postgresql://reader@db/quiz")
	t.Setenv("SOLUTION_PROMPT_STORAGE_PROVIDER", "local")
	cfg, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if cfg.SolutionPromptStorageProvider != "local" {
		t.Fatal("prompt provider was coupled to questions")
	}
}
