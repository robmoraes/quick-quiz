package app

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"quickquiz/api/internal/domain"
)

func TestOpenAISolutionGeneratorCallsResponsesAPI(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/responses" {
			t.Fatalf("expected /responses path, got %s", r.URL.Path)
		}
		if got := r.Header.Get("Authorization"); got != "Bearer test-key" {
			t.Fatalf("expected authorization header, got %q", got)
		}
		if got := r.Header.Get("OpenAI-Project"); got != "proj_test" {
			t.Fatalf("expected project header, got %q", got)
		}

		var payload openAIResponseRequest
		if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
			t.Fatalf("decode request body: %v", err)
		}
		if payload.Model != "test-model" {
			t.Fatalf("expected model test-model, got %q", payload.Model)
		}
		if !strings.Contains(payload.Input, "Requested locale: en-US") {
			t.Fatalf("expected locale in input, got %q", payload.Input)
		}

		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{
			"output": [{
				"type": "message",
				"content": [{
					"type": "output_text",
					"text": "Because gofmt standardizes formatting."
				}]
			}]
		}`))
	}))
	defer server.Close()

	generator := NewOpenAISolutionGenerator(OpenAISolutionGeneratorConfig{
		APIKey:  "test-key",
		BaseURL: server.URL,
		Model:   "test-model",
		Project: "proj_test",
	}, server.Client(), nil)

	explanation, err := generator.GenerateSolution(context.Background(), GenerateSolutionInput{
		Locale:         "en-US",
		Topic:          "go",
		Difficulty:     domain.DifficultyEasy,
		QuestionID:     "go-easy-001",
		Prompt:         "What does gofmt do?",
		CorrectOptions: []string{"Formats Go source code"},
		WrongOptions:   []string{"Runs tests", "Compiles binaries"},
	})
	if err != nil {
		t.Fatalf("GenerateSolution() error = %v", err)
	}
	if explanation != "Because gofmt standardizes formatting." {
		t.Fatalf("unexpected explanation: %q", explanation)
	}
}

func TestOpenAISolutionGeneratorLoadsPromptSourceByTheme(t *testing.T) {
	source := &testSolutionPromptSource{prompt: "Custom theme prompt."}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var payload openAIResponseRequest
		if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
			t.Fatalf("decode request body: %v", err)
		}
		if payload.Instructions != "Custom theme prompt." {
			t.Fatalf("expected custom prompt instructions, got %q", payload.Instructions)
		}

		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"output_text":"Theme prompt loaded."}`))
	}))
	defer server.Close()

	generator := NewOpenAISolutionGenerator(OpenAISolutionGeneratorConfig{
		APIKey:  "test-key",
		BaseURL: server.URL,
		Model:   "test-model",
	}, server.Client(), source)

	explanation, err := generator.GenerateSolution(context.Background(), GenerateSolutionInput{
		Theme:          "dev",
		Locale:         "en-US",
		Topic:          "go",
		Difficulty:     domain.DifficultyEasy,
		QuestionID:     "go-easy-001",
		Prompt:         "What does gofmt do?",
		CorrectOptions: []string{"Formats Go source code"},
		WrongOptions:   []string{"Runs tests", "Compiles binaries"},
	})
	if err != nil {
		t.Fatalf("GenerateSolution() error = %v", err)
	}
	if explanation != "Theme prompt loaded." {
		t.Fatalf("unexpected explanation: %q", explanation)
	}
	if source.theme != "dev" {
		t.Fatalf("expected dev prompt theme, got %q", source.theme)
	}
}

type testSolutionPromptSource struct {
	prompt string
	theme  string
}

func (s *testSolutionPromptSource) Load(_ context.Context, theme string) (string, error) {
	s.theme = theme
	return s.prompt, nil
}
