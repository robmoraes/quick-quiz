package store

import (
	"context"
	"errors"
	"testing"

	"quickquiz/api/internal/domain"
)

func TestFileSolutionStoreSavesAndLoadsSolution(t *testing.T) {
	store := NewFileSolutionStore(t.TempDir())
	solution := domain.QuestionSolution{
		Theme:        "dev",
		Locale:       "pt-BR",
		Topic:        "go",
		Difficulty:   domain.DifficultyEasy,
		QuestionID:   "go-easy-001",
		Explanation:  "Explicacao gerada.",
		Model:        "fake-model",
		QuestionHash: "hash",
		GeneratedAt:  "2026-06-17T03:00:00Z",
	}

	if err := store.Save(context.Background(), solution); err != nil {
		t.Fatalf("Save() error = %v", err)
	}

	loaded, err := store.Get(context.Background(), solution)
	if err != nil {
		t.Fatalf("Get() error = %v", err)
	}
	if loaded.Explanation != solution.Explanation {
		t.Fatalf("expected explanation %q, got %q", solution.Explanation, loaded.Explanation)
	}
	if loaded.QuestionHash != solution.QuestionHash {
		t.Fatalf("expected question hash %q, got %q", solution.QuestionHash, loaded.QuestionHash)
	}
}

func TestFileSolutionStoreReturnsNotFound(t *testing.T) {
	store := NewFileSolutionStore(t.TempDir())

	_, err := store.Get(context.Background(), domain.QuestionSolution{
		Theme:      "dev",
		Locale:     "en-US",
		Topic:      "go",
		Difficulty: domain.DifficultyEasy,
		QuestionID: "go-easy-001",
	})
	if !errors.Is(err, domain.ErrSolutionNotFound) {
		t.Fatalf("expected ErrSolutionNotFound, got %v", err)
	}
}
