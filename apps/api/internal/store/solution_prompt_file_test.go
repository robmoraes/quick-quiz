package store

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

func TestFileSolutionPromptSourceLoadsThemePath(t *testing.T) {
	root := t.TempDir()
	promptDirectory := filepath.Join(root, "dev", "ai-prompts")
	if err := os.MkdirAll(promptDirectory, 0o755); err != nil {
		t.Fatalf("create prompt directory: %v", err)
	}
	if err := os.WriteFile(filepath.Join(promptDirectory, "question-solution-prompt.txt"), []byte("Custom prompt."), 0o644); err != nil {
		t.Fatalf("write prompt: %v", err)
	}

	source := NewFileSolutionPromptSource(filepath.Join(root, "{{theme}}", "ai-prompts", "question-solution-prompt.txt"))
	prompt, err := source.Load(context.Background(), "dev")
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}
	if prompt != "Custom prompt." {
		t.Fatalf("unexpected prompt: %q", prompt)
	}
}

func TestFileSolutionPromptSourceRejectsUnsafeTheme(t *testing.T) {
	source := NewFileSolutionPromptSource(filepath.Join(t.TempDir(), "{{theme}}", "prompt.txt"))
	if _, err := source.Load(context.Background(), "../dev"); err == nil {
		t.Fatal("expected unsafe theme to fail")
	}
}
