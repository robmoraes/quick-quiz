package store

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"quickquiz/api/internal/domain"
)

func TestLoadQuestionDatasetFromRootUsesIndexAndPathMetadata(t *testing.T) {
	root := t.TempDir()
	writeTestQuestionFile(t, root, "en-US", "php", "1", "php-1-001.json")
	writeTestQuestionFile(t, root, "en-US", "python", "1", "python-1-001.json")
	writeTestCentralIndexFile(t, root)
	writeTestFile(t, filepath.Join(root, "dev", "en-US", "index.json"), `{
		"topics": [
			{
				"key": "php",
				"name": "Localized PHP",
				"description": "Localized PHP description"
			}
		]
	}`)

	dataset, err := LoadQuestionDatasetFromRoot(root, []string{"en-US"})
	if err != nil {
		t.Fatalf("LoadQuestionDatasetFromRoot() error = %v", err)
	}

	if got := len(dataset.Questions); got != 1 {
		t.Fatalf("expected one indexed question, got %d", got)
	}

	question := dataset.Questions[0]
	if question.ID != "php-1-001" {
		t.Fatalf("expected id from filename, got %q", question.ID)
	}
	if question.Locale != "en-US" || question.Topic != "php" || question.Difficulty != domain.DifficultyEasy {
		t.Fatalf("expected path metadata, got locale=%q topic=%q difficulty=%d", question.Locale, question.Topic, question.Difficulty)
	}

	if got := len(dataset.Topics); got != 1 {
		t.Fatalf("expected one indexed topic, got %d", got)
	}
	if dataset.Topics[0].ID != "php" || dataset.Topics[0].Label != "Localized PHP" {
		t.Fatalf("expected topic metadata from index, got %#v", dataset.Topics[0])
	}
	if dataset.Topics[0].Description != "Localized PHP description" {
		t.Fatalf("expected localized description, got %#v", dataset.Topics[0])
	}
	if dataset.Topics[0].Weight != 100 || dataset.Topics[0].CreatedAt != "2026-01-01T00:00:00-03:00" {
		t.Fatalf("expected canonical publication metadata, got %#v", dataset.Topics[0])
	}
}

func TestLoadQuestionDatasetFromRootFallsBackToCentralTopicMetadata(t *testing.T) {
	root := t.TempDir()
	writeTestQuestionFile(t, root, "en-US", "php", "1", "php-1-001.json")
	writeTestCentralIndexFile(t, root)

	dataset, err := LoadQuestionDatasetFromRoot(root, []string{"en-US"})
	if err != nil {
		t.Fatalf("LoadQuestionDatasetFromRoot() error = %v", err)
	}

	if got := len(dataset.Topics); got != 1 {
		t.Fatalf("expected one indexed topic, got %d", got)
	}
	if dataset.Topics[0].ID != "php" || dataset.Topics[0].Label != "General PHP" {
		t.Fatalf("expected central topic metadata, got %#v", dataset.Topics[0])
	}
}

func TestLoadQuestionDatasetFromRootRejectsLocalizedTopicOutsideCentralIndex(t *testing.T) {
	root := t.TempDir()
	writeTestQuestionFile(t, root, "en-US", "php", "1", "php-1-001.json")
	writeTestCentralIndexFile(t, root)
	writeTestFile(t, filepath.Join(root, "dev", "en-US", "index.json"), `{
		"topics": [
			{
				"key": "ruby",
				"name": "Ruby",
				"description": "Ruby description"
			}
		]
	}`)

	_, err := LoadQuestionDatasetFromRoot(root, []string{"en-US"})
	if err == nil {
		t.Fatal("expected localized orphan key to fail")
	}
	if !strings.Contains(err.Error(), "localized topic key ruby") {
		t.Fatalf("expected localized orphan key error, got %v", err)
	}
}

func TestLoadQuestionDatasetFromRootRequiresTranslatedQuestionPackages(t *testing.T) {
	root := t.TempDir()
	writeTestQuestionFile(t, root, "en-US", "php", "1", "php-1-001.json")
	writeTestCentralIndexFile(t, root)

	_, err := LoadQuestionDatasetFromRootWithFallback(root, "en-US", []string{"en-US", "pt-BR"})
	if err == nil {
		t.Fatal("expected missing translated question to fail")
	}
	if !strings.Contains(err.Error(), "missing fallback question package php/1/php-1-001") {
		t.Fatalf("expected missing package error, got %v", err)
	}
}

func writeTestQuestionFile(t *testing.T, root, locale, topic, difficulty, filename string) {
	t.Helper()

	writeTestFile(t, filepath.Join(root, "dev", locale, topic, difficulty, filename), `{
		"prompt": "Fake question",
		"correctOptions": ["correct answer"],
		"wrongOptions": [
			"wrong answer 01",
			"wrong answer 02",
			"wrong answer 03",
			"wrong answer 04",
			"wrong answer 05",
			"wrong answer 06",
			"wrong answer 07",
			"wrong answer 08",
			"wrong answer 09",
			"wrong answer 10",
			"wrong answer 11",
			"wrong answer 12",
			"wrong answer 13",
			"wrong answer 14",
			"wrong answer 15",
			"wrong answer 16",
			"wrong answer 17",
			"wrong answer 18",
			"wrong answer 19",
			"wrong answer 20"
		]
	}`)
}

func writeTestCentralIndexFile(t *testing.T, root string) {
	t.Helper()

	writeTestFile(t, filepath.Join(root, "themes.json"), `{
		"themes": [
			{
				"id": "dev",
				"name": "Development",
				"description": "Development quizzes",
				"weight": 100,
				"createdAt": "2026-01-01T00:00:00-03:00",
				"active": true
			}
		]
	}`)
	writeTestFile(t, filepath.Join(root, "dev", "index.json"), `{
		"topics": [
			{
				"key": "php",
				"name": "General PHP",
				"description": "Version-agnostic PHP topic fundamentals",
				"weight": 100,
				"created_at": "2026-01-01T00:00:00-03:00",
				"active": true
			},
			{
				"key": "python",
				"name": "General Python",
				"description": "Version-agnostic Python topic fundamentals",
				"weight": 200,
				"created_at": "2026-01-01T00:00:00-03:00",
				"active": false
			}
		]
	}`)
}

func writeTestFile(t *testing.T, path, content string) {
	t.Helper()

	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatalf("MkdirAll() error = %v", err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatalf("WriteFile() error = %v", err)
	}
}
