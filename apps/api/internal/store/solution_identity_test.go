package store

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"

	"quickquiz/api/internal/domain"
)

func TestSolutionStoresSeparateLegacyAndContentPromptIdentities(t *testing.T) {
	ctx := context.Background()
	server := miniredis.RunT(t)
	redisStore := newRedisSolutionStore(redis.NewClient(&redis.Options{Addr: server.Addr()}), "test:solutions:", time.Hour)
	defer redisStore.Close()
	stores := map[string]interface {
		Get(context.Context, domain.QuestionSolution) (domain.QuestionSolution, error)
		Save(context.Context, domain.QuestionSolution) error
	}{"memory": NewMemorySolutionStore(nil), "file": NewFileSolutionStore(t.TempDir()), "redis": redisStore}
	base := domain.QuestionSolution{Theme: "dev", Locale: "en-US", Topic: "go", Difficulty: domain.DifficultyEasy, QuestionID: "q-1", QuestionHash: "content-1", Explanation: "legacy explanation"}
	if got := solutionKey(base); got != "dev:en-US:go:1:q-1" {
		t.Fatalf("legacy key changed: %s", got)
	}
	for name, store := range stores {
		t.Run(name, func(t *testing.T) {
			if err := store.Save(ctx, base); err != nil {
				t.Fatal(err)
			}
			versioned := base
			versioned.CacheIdentity, versioned.Explanation = "content-prompt-model-config-identity-1", "first snapshot explanation"
			if _, err := store.Get(ctx, versioned); !errors.Is(err, domain.ErrSolutionNotFound) {
				t.Fatalf("v2 reused legacy explanation: %v", err)
			}
			if err := store.Save(ctx, versioned); err != nil {
				t.Fatal(err)
			}
			second := versioned
			second.CacheIdentity, second.Explanation = "content-prompt-model-config-identity-2", "changed prompt explanation"
			if _, err := store.Get(ctx, second); !errors.Is(err, domain.ErrSolutionNotFound) {
				t.Fatalf("different prompt/config identity reused cache: %v", err)
			}
			if err := store.Save(ctx, second); err != nil {
				t.Fatal(err)
			}
			for _, want := range []domain.QuestionSolution{base, versioned, second} {
				got, err := store.Get(ctx, want)
				if err != nil || got != want {
					t.Fatalf("cache identity roundtrip: got %#v, err %v", got, err)
				}
			}
			if key := solutionKey(versioned); !strings.HasPrefix(key, "v2:") || strings.Contains(key, versioned.CacheIdentity) {
				t.Fatal("new cache key contains raw fingerprint input")
			}
			public, err := json.Marshal(versioned)
			if err != nil || strings.Contains(string(public), versioned.CacheIdentity) || strings.Contains(string(public), "cacheIdentity") {
				t.Fatal("private cache identity leaked into public solution JSON")
			}
		})
	}
}
