package store

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"

	"quickquiz/api/internal/domain"
)

func TestRedisSolutionStoreLifecycleAndExpiry(t *testing.T) {
	redisServer := miniredis.RunT(t)
	client := redis.NewClient(&redis.Options{Addr: redisServer.Addr()})
	store := newRedisSolutionStore(client, "test:solutions:", time.Hour)
	t.Cleanup(func() { _ = store.Close() })

	solution := domain.QuestionSolution{
		Theme:        "dev",
		Locale:       "en-US",
		Topic:        "go",
		Difficulty:   domain.DifficultyEasy,
		QuestionID:   "go-1-001",
		Explanation:  "Because the first option is correct.",
		Model:        "test-model",
		QuestionHash: "question-hash",
		GeneratedAt:  "2026-09-14T12:00:00Z",
	}
	ctx := context.Background()
	if err := store.Save(ctx, solution); err != nil {
		t.Fatalf("Save() error = %v", err)
	}

	keys := redisServer.Keys()
	if len(keys) != 1 || !strings.HasPrefix(keys[0], "test:solutions:") {
		t.Fatalf("unexpected Redis keys: %#v", keys)
	}
	if ttl := redisServer.TTL(keys[0]); ttl != time.Hour {
		t.Fatalf("expected one hour TTL, got %s", ttl)
	}

	loaded, err := store.Get(ctx, solution)
	if err != nil {
		t.Fatalf("Get() error = %v", err)
	}
	if loaded != solution {
		t.Fatalf("unexpected stored solution: %#v", loaded)
	}

	redisServer.FastForward(time.Hour + time.Second)
	if _, err := store.Get(ctx, solution); !errors.Is(err, domain.ErrSolutionNotFound) {
		t.Fatalf("expected expired solution to be missing, got %v", err)
	}
}

func TestNewRedisSolutionStoreValidatesConfiguration(t *testing.T) {
	ctx := context.Background()
	if _, err := NewRedisSolutionStore(ctx, RedisSolutionStoreConfig{TTL: time.Hour}); err == nil {
		t.Fatal("expected empty Redis address to fail")
	}
	if _, err := NewRedisSolutionStore(ctx, RedisSolutionStoreConfig{Addr: "redis:6379"}); err == nil {
		t.Fatal("expected empty solution TTL to fail")
	}
}
