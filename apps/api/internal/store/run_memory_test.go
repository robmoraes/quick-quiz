package store

import (
	"context"
	"errors"
	"testing"
	"time"

	"quickquiz/api/internal/domain"
)

func TestMemoryRunStoreSnapshotIsolationAndIndexedConsumption(t *testing.T) {
	ctx := context.Background()
	s := NewMemoryRunStore(time.Hour)
	revision := int64(42)
	run := indexTestRun("snapshot", "session")
	run.Version, run.CatalogRevision = 2, &revision
	run.QuestionSnapshots = []domain.QuestionSnapshot{{Translations: map[string]domain.Question{"en-US": {Prompt: "original", CorrectOptions: []string{"correct"}, WrongOptions: []string{"wrong"}}}}}
	if err := s.Create(ctx, run); err != nil {
		t.Fatal(err)
	}
	*run.CatalogRevision = 99
	question := run.QuestionSnapshots[0].Translations["en-US"]
	question.CorrectOptions[0] = "mutated caller"
	question.WrongOptions[0] = "mutated caller"
	run.QuestionSnapshots[0].Translations["en-US"] = question
	loaded, err := s.Get(ctx, run.ID)
	if err != nil || *loaded.CatalogRevision != 42 || loaded.QuestionSnapshots[0].Translations["en-US"].CorrectOptions[0] != "correct" || loaded.QuestionSnapshots[0].Translations["en-US"].WrongOptions[0] != "wrong" {
		t.Fatalf("caller changed persisted snapshot: %v", err)
	}
	delete(loaded.QuestionSnapshots[0].Translations, "en-US")
	*loaded.CatalogRevision = 100
	loaded, _ = s.Get(ctx, run.ID)
	if *loaded.CatalogRevision != 42 || len(loaded.QuestionSnapshots[0].Translations) != 1 {
		t.Fatal("Get returned aliased snapshot state")
	}
	if consumed, err := s.ConsumedBySession(ctx, "session", "dev"); err != nil || len(consumed["go"][domain.DifficultyEasy]) != 1 {
		t.Fatalf("memory canonical consumption: %#v, %v", consumed, err)
	}
	if count, err := s.CountLegacyDependent(ctx); err != nil || count != 0 {
		t.Fatalf("v2 memory run marked legacy: %d, %v", count, err)
	}
	if _, err := s.Get(ctx, "absent"); !errors.Is(err, domain.ErrRunNotFound) {
		t.Fatalf("missing run sentinel: %v", err)
	}
}

func TestMemoryRunStoreTracksSolutionRequestsUpToLimit(t *testing.T) {
	runStore := NewMemoryRunStore(time.Hour)
	now := time.Now().UTC()
	err := runStore.Create(context.Background(), &domain.Run{
		ID:        "run_001",
		SessionID: "session_001",
		Theme:     "dev",
		CreatedAt: now,
		UpdatedAt: now,
	})
	if err != nil {
		t.Fatalf("Create() error = %v", err)
	}

	allowed, err := runStore.TrackSolutionRequest(context.Background(), "run_001", "question_001", 1)
	if err != nil {
		t.Fatalf("TrackSolutionRequest() error = %v", err)
	}
	if !allowed {
		t.Fatalf("expected first question to be allowed")
	}

	allowed, err = runStore.TrackSolutionRequest(context.Background(), "run_001", "question_001", 1)
	if err != nil {
		t.Fatalf("TrackSolutionRequest() repeated error = %v", err)
	}
	if !allowed {
		t.Fatalf("expected repeated question to be allowed without consuming a new slot")
	}

	allowed, err = runStore.TrackSolutionRequest(context.Background(), "run_001", "question_002", 1)
	if err != nil {
		t.Fatalf("TrackSolutionRequest() second question error = %v", err)
	}
	if allowed {
		t.Fatalf("expected second distinct question to be rate limited")
	}
}

func TestMemoryRunStoreListsUnexpiredRuns(t *testing.T) {
	runStore := NewMemoryRunStore(time.Minute)
	now := time.Now().UTC()
	runs := []*domain.Run{
		{
			ID:        "run_active",
			SessionID: "session_active",
			Theme:     "dev",
			CreatedAt: now,
			UpdatedAt: now,
		},
		{
			ID:        "run_finished",
			SessionID: "session_finished",
			Theme:     "dev",
			Finished:  true,
			CreatedAt: now,
			UpdatedAt: now,
		},
		{
			ID:        "run_expired",
			SessionID: "session_expired",
			Theme:     "dev",
			CreatedAt: now.Add(-time.Hour),
			UpdatedAt: now.Add(-time.Hour),
		},
	}
	for _, run := range runs {
		if err := runStore.Create(context.Background(), run); err != nil {
			t.Fatalf("Create() error = %v", err)
		}
	}

	active, err := runStore.ListTracked(context.Background())
	if err != nil {
		t.Fatalf("ListTracked() error = %v", err)
	}
	if got := len(active); got != 2 {
		t.Fatalf("expected two unexpired runs, got %d: %#v", got, active)
	}
	found := map[string]bool{}
	for _, run := range active {
		found[run.ID] = true
	}
	if !found["run_active"] || !found["run_finished"] || found["run_expired"] {
		t.Fatalf("expected active and finished unexpired runs, got %#v", active)
	}
}
