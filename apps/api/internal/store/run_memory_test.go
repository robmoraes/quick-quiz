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
