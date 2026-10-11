package store

import (
	"context"
	"errors"
	"fmt"
	"os"
	"reflect"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/redis/go-redis/v9"

	"quickquiz/api/internal/domain"
)

// This endpoint must explicitly identify a disposable Redis instance, never the
// application's configured Redis. Cleanup is limited to this test's namespaces.
func TestRealRedisRunIndexMigrationAndAtomicWrites(t *testing.T) {
	addr := os.Getenv("QUICKQUIZ_TEST_REDIS_ADDR")
	if addr == "" {
		t.Skip("set QUICKQUIZ_TEST_REDIS_ADDR to a disposable local Redis")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	client := redis.NewClient(&redis.Options{Addr: addr})
	s := newRedisRunStore(client, fmt.Sprintf("qq:isolated-index-test:%d:", time.Now().UnixNano()), time.Minute)
	t.Cleanup(func() {
		cleanup, done := context.WithTimeout(context.Background(), 5*time.Second)
		defer done()
		for _, pattern := range []string{s.keyPrefix + "*", s.indexPrefix() + "*"} {
			var cursor uint64
			for {
				keys, next, err := client.Scan(cleanup, cursor, pattern, 100).Result()
				if err != nil {
					t.Errorf("isolated test cleanup failed: %v", err)
					break
				}
				if len(keys) > 0 {
					client.Del(cleanup, keys...)
				}
				cursor = next
				if cursor == 0 {
					break
				}
			}
		}
		client.Close()
	})
	legacy := indexTestRun("legacy", "session")
	payload := seedLegacyRun(t, s, legacy, 45*time.Second)
	ttlBefore := client.PTTL(ctx, s.key(legacy.ID)).Val()
	if report, err := s.MigrateRunIndex(ctx, RunIndexMigrationOptions{BatchSize: 1}); err != nil || report.VisitedRunRecords != 1 || report.Completed {
		t.Fatalf("real Redis dry-run: %+v, %v", report, err)
	}
	if count := client.DBSize(ctx).Val(); count < 1 {
		t.Fatal("dry-run lost legacy record")
	}
	if report, err := s.MigrateRunIndex(ctx, RunIndexMigrationOptions{Apply: true, ConfirmLegacyWritersStopped: true, BatchSize: 1}); err != nil || !report.Completed {
		t.Fatalf("real Redis apply: %+v, %v", report, err)
	}
	stored := client.Get(ctx, s.key(legacy.ID)).Val()
	ttlAfter := client.PTTL(ctx, s.key(legacy.ID)).Val()
	if stored != string(payload) || ttlAfter > ttlBefore || ttlAfter < ttlBefore-3*time.Second {
		t.Fatalf("backfill changed legacy record or expiry: before=%s after=%s", ttlBefore, ttlAfter)
	}
	run := indexTestRun("snapshot-run", "session")
	run.Version = 2
	run.QuestionSnapshots = []domain.QuestionSnapshot{{Key: domain.QuestionKey{Theme: "dev", Topic: "go", Difficulty: domain.DifficultyEasy, QuestionID: "snapshot-run"}, Translations: map[string]domain.Question{"en-US": {Prompt: "immutable prompt", CorrectOptions: []string{"correct"}, WrongOptions: []string{"wrong-a", "wrong-b"}}}, Digest: "snapshot-digest"}}
	if err := s.Create(ctx, run); err != nil {
		t.Fatal(err)
	}
	loaded, err := s.Get(ctx, run.ID)
	if err != nil || !reflect.DeepEqual(loaded.QuestionSnapshots, run.QuestionSnapshots) {
		t.Fatalf("snapshot Redis roundtrip: %v", err)
	}
	consumed, err := s.ConsumedBySession(ctx, "session", "dev")
	if err != nil || !reflect.DeepEqual(consumed["go"][domain.DifficultyEasy], []string{"legacy", "snapshot-run"}) {
		t.Fatalf("real Redis consumption: %#v, %v", consumed, err)
	}
	const workers, limit = 8, 3
	var allowed atomic.Int32
	var wg sync.WaitGroup
	for i := 0; i < workers; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			ok, err := s.TrackSolutionRequest(ctx, run.ID, fmt.Sprintf("q-%d", i), limit)
			if err != nil {
				t.Errorf("real Redis concurrent track: %v", err)
			} else if ok {
				allowed.Add(1)
			}
		}(i)
	}
	wg.Wait()
	if allowed.Load() != limit {
		t.Fatalf("atomic solution limit: got %d allowed", allowed.Load())
	}
	legacy.Finished = true
	if err := s.Save(ctx, legacy); err != nil {
		t.Fatal(err)
	}
	if count, err := s.CountLegacyDependent(ctx); err != nil || count != 0 {
		t.Fatalf("finished legacy removed from dependency index: %d, %v", count, err)
	}
	if err := s.DeleteBySession(ctx, "session", "dev"); err != nil {
		t.Fatal(err)
	}
	if has, err := s.HasSession(ctx, "session", "dev"); err != nil || has {
		t.Fatalf("real Redis reset: %t, %v", has, err)
	}
	if _, err := s.Get(ctx, run.ID); !errors.Is(err, domain.ErrRunNotFound) {
		t.Fatalf("reset left run: %v", err)
	}
}
