package store

import (
	"context"
	"testing"
	"time"

	"quickquiz/api/internal/domain"
)

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
