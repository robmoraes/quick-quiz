package store

import (
	"context"
	"fmt"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"

	"quickquiz/api/internal/domain"
)

func TestRedisRunStoreLifecycleAndQueries(t *testing.T) {
	runStore, redisServer := newTestRedisRunStore(t, time.Minute)
	ctx := context.Background()
	now := time.Now().UTC().Truncate(time.Millisecond)

	runs := []*domain.Run{
		{
			ID:              "run_001",
			SessionID:       "session_001",
			Theme:           "dev",
			Topic:           "go",
			Difficulty:      domain.DifficultyEasy,
			UsedQuestionIDs: map[string]bool{"question_001": true},
			CreatedAt:       now,
			UpdatedAt:       now,
		},
		{
			ID:              "run_002",
			SessionID:       "session_001",
			Theme:           "dev",
			Topic:           "go",
			Difficulty:      domain.DifficultyEasy,
			UsedQuestionIDs: map[string]bool{"question_002": true},
			CreatedAt:       now,
			UpdatedAt:       now,
		},
		{
			ID:              "run_003",
			SessionID:       "session_002",
			Theme:           "dev",
			Topic:           "go",
			Difficulty:      domain.DifficultyEasy,
			UsedQuestionIDs: map[string]bool{"question_003": true},
			CreatedAt:       now,
			UpdatedAt:       now,
		},
	}
	for _, run := range runs {
		if err := runStore.Create(ctx, run); err != nil {
			t.Fatalf("Create(%q) error = %v", run.ID, err)
		}
	}

	stored, err := runStore.Get(ctx, "run_001")
	if err != nil {
		t.Fatalf("Get() error = %v", err)
	}
	if stored.SessionID != "session_001" || !stored.UsedQuestionIDs["question_001"] {
		t.Fatalf("unexpected stored run: %#v", stored)
	}

	used, err := runStore.UsedQuestionIDs(ctx, "session_001", "dev", "go", domain.DifficultyEasy)
	if err != nil {
		t.Fatalf("UsedQuestionIDs() error = %v", err)
	}
	if len(used) != 2 || !used["question_001"] || !used["question_002"] {
		t.Fatalf("unexpected used question ids: %#v", used)
	}

	tracked, err := runStore.ListTracked(ctx)
	if err != nil {
		t.Fatalf("ListTracked() error = %v", err)
	}
	if len(tracked) != 3 {
		t.Fatalf("expected three tracked runs, got %d", len(tracked))
	}

	if err := runStore.DeleteBySession(ctx, "session_001", "dev"); err != nil {
		t.Fatalf("DeleteBySession() error = %v", err)
	}
	if _, err := runStore.Get(ctx, "run_001"); err == nil {
		t.Fatal("expected deleted run to be missing")
	}
	if _, err := runStore.Get(ctx, "run_003"); err != nil {
		t.Fatalf("expected other session run to remain: %v", err)
	}

	redisServer.FastForward(time.Minute + time.Second)
	if _, err := runStore.Get(ctx, "run_003"); err == nil {
		t.Fatal("expected run to expire after SESSION_TTL")
	}
}

func TestRedisRunStoreTracksSolutionRequestsAtomically(t *testing.T) {
	runStore, _ := newTestRedisRunStore(t, time.Hour)
	ctx := context.Background()
	now := time.Now().UTC()
	if err := runStore.Create(ctx, &domain.Run{
		ID:        "run_001",
		SessionID: "session_001",
		Theme:     "dev",
		CreatedAt: now,
		UpdatedAt: now,
	}); err != nil {
		t.Fatalf("Create() error = %v", err)
	}

	const requestCount = 8
	const limit = 3
	var allowed atomic.Int32
	var waitGroup sync.WaitGroup
	errors := make(chan error, requestCount)
	for index := 0; index < requestCount; index++ {
		waitGroup.Add(1)
		go func(questionIndex int) {
			defer waitGroup.Done()
			ok, err := runStore.TrackSolutionRequest(ctx, "run_001", fmt.Sprintf("question_%03d", questionIndex), limit)
			if err != nil {
				errors <- err
				return
			}
			if ok {
				allowed.Add(1)
			}
		}(index)
	}
	waitGroup.Wait()
	close(errors)
	for err := range errors {
		t.Fatalf("TrackSolutionRequest() error = %v", err)
	}
	if got := allowed.Load(); got != limit {
		t.Fatalf("expected exactly %d allowed requests, got %d", limit, got)
	}

	stored, err := runStore.Get(ctx, "run_001")
	if err != nil {
		t.Fatalf("Get() error = %v", err)
	}
	if len(stored.SolutionRequestQuestionIDs) != limit {
		t.Fatalf("expected %d tracked question ids, got %#v", limit, stored.SolutionRequestQuestionIDs)
	}

	var repeatedQuestion string
	for questionID := range stored.SolutionRequestQuestionIDs {
		repeatedQuestion = questionID
		break
	}
	repeated, err := runStore.TrackSolutionRequest(ctx, "run_001", repeatedQuestion, limit)
	if err != nil {
		t.Fatalf("repeated TrackSolutionRequest() error = %v", err)
	}
	if !repeated {
		t.Fatal("expected repeated question to remain allowed")
	}
}

func newTestRedisRunStore(t *testing.T, ttl time.Duration) (*RedisRunStore, *miniredis.Miniredis) {
	t.Helper()
	redisServer := miniredis.RunT(t)
	client := redis.NewClient(&redis.Options{Addr: redisServer.Addr()})
	runStore := newRedisRunStore(client, "test:runs:", ttl)
	t.Cleanup(func() {
		_ = runStore.Close()
	})
	return runStore, redisServer
}
