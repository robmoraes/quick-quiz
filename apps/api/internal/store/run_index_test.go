package store

import (
	"context"
	"encoding/json"
	"errors"
	"reflect"
	"sync/atomic"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"

	"quickquiz/api/internal/domain"
)

func indexTestRun(id, session string) *domain.Run {
	return &domain.Run{ID: id, SessionID: session, Theme: "dev", Topic: "go", Difficulty: domain.DifficultyEasy, Locale: "en-US", UsedQuestionIDs: map[string]bool{id: true}, UpdatedAt: time.Now().UTC()}
}

func unindexedRunStore(t *testing.T) (*RedisRunStore, *miniredis.Miniredis) {
	t.Helper()
	server := miniredis.RunT(t)
	store := newRedisRunStore(redis.NewClient(&redis.Options{Addr: server.Addr()}), "test:runs:", time.Hour)
	t.Cleanup(func() { _ = store.Close() })
	return store, server
}

func seedLegacyRun(t *testing.T, s *RedisRunStore, run *domain.Run, ttl time.Duration) []byte {
	t.Helper()
	payload, err := json.Marshal(run)
	if err != nil {
		t.Fatal(err)
	}
	if err := s.client.Set(context.Background(), s.key(run.ID), payload, ttl).Err(); err != nil {
		t.Fatal(err)
	}
	return payload
}

func TestRunIndexBackfillIsExplicitReadOnlyAndPreservesLegacyTTL(t *testing.T) {
	ctx := context.Background()
	s, server := unindexedRunStore(t)
	run := indexTestRun("legacy", "session")
	payload := seedLegacyRun(t, s, run, 17*time.Minute)
	for name, call := range map[string]func() error{
		"readiness":   func() error { return s.Ready(ctx) },
		"create":      func() error { return s.Create(ctx, indexTestRun("new", "session")) },
		"consumption": func() error { _, err := s.ConsumedBySession(ctx, "session", "dev"); return err },
		"session":     func() error { _, err := s.HasSession(ctx, "session", "dev"); return err },
		"reset":       func() error { return s.DeleteBySession(ctx, "session", "dev") },
	} {
		if err := call(); !errors.Is(err, domain.ErrStorageUnavailable) {
			t.Errorf("%s must fail unavailable before completion: %v", name, err)
		}
	}
	if stored, err := s.Get(ctx, run.ID); err != nil || stored.ID != run.ID {
		t.Fatalf("legacy Get must remain available: %v", err)
	}
	keysBefore, ttlBefore := server.Keys(), server.TTL(s.key(run.ID))
	report, err := s.MigrateRunIndex(ctx, RunIndexMigrationOptions{BatchSize: 1})
	if err != nil || report.VisitedRunRecords != 1 || report.Completed || report.IndexedRuns != 0 {
		t.Fatalf("unexpected dry-run: %+v, %v", report, err)
	}
	if !reflect.DeepEqual(server.Keys(), keysBefore) || server.TTL(s.key(run.ID)) != ttlBefore {
		t.Fatal("dry-run changed keys or TTL")
	}
	if _, err := s.MigrateRunIndex(ctx, RunIndexMigrationOptions{Apply: true}); err == nil {
		t.Fatal("apply must require explicit old-writer stop acknowledgement")
	}
	if !reflect.DeepEqual(server.Keys(), keysBefore) {
		t.Fatal("unconfirmed apply wrote keys")
	}
	report, err = s.MigrateRunIndex(ctx, RunIndexMigrationOptions{Apply: true, ConfirmLegacyWritersStopped: true, BatchSize: 1})
	if err != nil || !report.Completed || report.IndexedRuns != 1 || report.LegacyDependent == nil || *report.LegacyDependent != 1 {
		t.Fatalf("unexpected apply: %+v, %v", report, err)
	}
	got, _ := s.client.Get(ctx, s.key(run.ID)).Bytes()
	if string(got) != string(payload) || server.TTL(s.key(run.ID)) != ttlBefore {
		t.Fatal("backfill rewrote legacy run or refreshed its TTL")
	}
	consumed, err := s.ConsumedBySession(ctx, "session", "dev")
	if err != nil || !reflect.DeepEqual(consumed["go"][domain.DifficultyEasy], []string{"legacy"}) {
		t.Fatalf("legacy consumption lost after backfill: %#v, %v", consumed, err)
	}
	if _, err := s.MigrateRunIndex(ctx, RunIndexMigrationOptions{Apply: true, ConfirmLegacyWritersStopped: true}); err != nil {
		t.Fatalf("repeat apply failed: %v", err)
	}
	if server.TTL(s.key(run.ID)) != ttlBefore {
		t.Fatal("repeat backfill refreshed legacy TTL")
	}
}

func TestRunIndexFreshBootstrapAndCLIDryRun(t *testing.T) {
	ctx := context.Background()
	server := miniredis.RunT(t)
	s, err := NewRedisRunStore(ctx, RedisRunStoreConfig{Addr: server.Addr(), TTL: time.Hour, DisableIndexBootstrap: true})
	if err != nil {
		t.Fatal(err)
	}
	defer s.Close()
	if _, err := s.MigrateRunIndex(ctx, RunIndexMigrationOptions{}); err != nil || len(server.Keys()) != 0 {
		t.Fatalf("fresh dry-run mutated DB: %v", err)
	}
	fresh, err := NewRedisRunStore(ctx, RedisRunStoreConfig{Addr: server.Addr(), TTL: time.Hour})
	if err != nil {
		t.Fatal(err)
	}
	defer fresh.Close()
	if err := fresh.Ready(ctx); err != nil {
		t.Fatalf("fresh DB must bootstrap ready: %v", err)
	}
	// Another namespace cannot justify assuming there are no old API runs.
	other := miniredis.RunT(t)
	other.Set("unrelated", "present")
	nonempty, err := NewRedisRunStore(ctx, RedisRunStoreConfig{Addr: other.Addr(), TTL: time.Hour})
	if err != nil {
		t.Fatal(err)
	}
	defer nonempty.Close()
	if !errors.Is(nonempty.Ready(ctx), domain.ErrStorageUnavailable) {
		t.Fatal("nonempty unmarked DB was silently accepted")
	}
}

func TestRunIndexPerRunExpiryAndLegacyDependency(t *testing.T) {
	ctx := context.Background()
	s, server := newTestRedisRunStore(t, time.Minute)
	now := time.Now().UTC().Truncate(time.Millisecond)
	server.SetTime(now)
	early := indexTestRun("early", "session")
	if err := s.Create(ctx, early); err != nil {
		t.Fatal(err)
	}
	server.FastForward(40 * time.Second)
	server.SetTime(now.Add(40 * time.Second))
	later := indexTestRun("later", "session")
	later.Version, later.Locale = 2, "pt-BR"
	later.UsedQuestionIDs["not-presented"] = false
	if err := s.Create(ctx, later); err != nil {
		t.Fatal(err)
	}
	count, err := s.CountLegacyDependent(ctx)
	if err != nil || count != 1 {
		t.Fatalf("expected one legacy dependent: %d, %v", count, err)
	}
	server.FastForward(21 * time.Second)
	server.SetTime(now.Add(61 * time.Second))
	consumed, err := s.ConsumedBySession(ctx, "session", "dev")
	if err != nil || !reflect.DeepEqual(consumed["go"][domain.DifficultyEasy], []string{"later"}) {
		t.Fatalf("expiry or locale consumption incorrect: %#v, %v", consumed, err)
	}
	if count, err := s.CountLegacyDependent(ctx); err != nil || count != 0 {
		t.Fatalf("expired legacy dependency retained: %d, %v", count, err)
	}
	if server.TTL(s.key(later.ID)) != 39*time.Second {
		t.Fatal("indexed reads refreshed run TTL")
	}
	legacyFinished := indexTestRun("finished", "other")
	legacyFinished.Finished = true
	if err := s.Create(ctx, legacyFinished); err != nil {
		t.Fatal(err)
	}
	if count, err := s.CountLegacyDependent(ctx); err != nil || count != 0 {
		t.Fatalf("finished legacy run still depends on old provider: %d, %v", count, err)
	}
}

func TestRunIndexCorruptionFailsBeforeRecordMutation(t *testing.T) {
	ctx := context.Background()
	for _, corruption := range []string{"missing-metadata", "missing-scope", "wrong-legacy-type"} {
		t.Run(corruption, func(t *testing.T) {
			s, _ := newTestRedisRunStore(t, time.Hour)
			run := indexTestRun("retained", "session")
			if err := s.Create(ctx, run); err != nil {
				t.Fatal(err)
			}
			switch corruption {
			case "missing-metadata":
				s.client.Del(ctx, s.runMetadataKey(run.ID))
			case "missing-scope":
				s.client.Del(ctx, s.sessionIndexKey("session", "dev"))
			case "wrong-legacy-type":
				s.client.Del(ctx, s.legacyIndexKey())
				s.client.Set(ctx, s.legacyIndexKey(), "corrupt", 0)
				if err := s.Create(ctx, indexTestRun("must-not-exist", "session")); !errors.Is(err, domain.ErrStorageUnavailable) {
					t.Fatalf("wrong type create: %v", err)
				}
				if s.client.Exists(ctx, s.key("must-not-exist")).Val() != 0 {
					t.Fatal("failed Lua write left an unindexed record")
				}
			}
			if corruption != "wrong-legacy-type" {
				if _, err := s.ConsumedBySession(ctx, "session", "dev"); !errors.Is(err, domain.ErrStorageUnavailable) {
					t.Fatalf("corrupt consumption must not report empty: %v", err)
				}
			}
			if err := s.DeleteBySession(ctx, "session", "dev"); !errors.Is(err, domain.ErrStorageUnavailable) {
				t.Fatalf("corrupt reset: %v", err)
			}
			if _, err := s.Get(ctx, run.ID); err != nil {
				t.Fatalf("failed reset deleted retained record: %v", err)
			}
		})
	}
}

func TestRunIndexBackfillFailureLeavesMarkerIncompleteAndCanRetry(t *testing.T) {
	ctx := context.Background()
	s, _ := unindexedRunStore(t)
	run := indexTestRun("valid", "session")
	seedLegacyRun(t, s, run, time.Hour)
	s.client.Set(ctx, s.key("invalid"), "{broken", time.Hour)
	options := RunIndexMigrationOptions{Apply: true, ConfirmLegacyWritersStopped: true, BatchSize: 1}
	if report, err := s.MigrateRunIndex(ctx, options); err == nil || report.Completed {
		t.Fatalf("invalid record should prevent completion: %+v, %v", report, err)
	}
	if !errors.Is(s.IndexReady(ctx), domain.ErrStorageUnavailable) {
		t.Fatal("failed backfill left completion marker")
	}
	s.client.Del(ctx, s.key("invalid"))
	if report, err := s.MigrateRunIndex(ctx, options); err != nil || !report.Completed {
		t.Fatalf("backfill could not safely retry: %+v, %v", report, err)
	}
}

type changeAfterReadHook struct {
	target   string
	changed  atomic.Bool
	callback func() error
}

func (h *changeAfterReadHook) DialHook(next redis.DialHook) redis.DialHook { return next }
func (h *changeAfterReadHook) ProcessPipelineHook(next redis.ProcessPipelineHook) redis.ProcessPipelineHook {
	return next
}
func (h *changeAfterReadHook) ProcessHook(next redis.ProcessHook) redis.ProcessHook {
	return func(ctx context.Context, command redis.Cmder) error {
		if err := next(ctx, command); err != nil {
			return err
		}
		if command.Name() == "get" && command.Args()[1] == h.target && h.changed.CompareAndSwap(false, true) {
			return h.callback()
		}
		return nil
	}
}

func TestRunIndexBackfillDoesNotOverwriteRacingCompatibleWriter(t *testing.T) {
	ctx := context.Background()
	s, server := unindexedRunStore(t)
	run := indexTestRun("racing", "session")
	seedLegacyRun(t, s, run, time.Minute)
	writer := newRedisRunStore(redis.NewClient(&redis.Options{Addr: server.Addr()}), s.keyPrefix, time.Minute)
	defer writer.Close()
	hook := &changeAfterReadHook{target: s.key(run.ID), callback: func() error {
		run.UsedQuestionIDs = map[string]bool{"current-question": true}
		return writer.Save(ctx, run)
	}}
	s.client.AddHook(hook)
	if report, err := s.MigrateRunIndex(ctx, RunIndexMigrationOptions{Apply: true, ConfirmLegacyWritersStopped: true}); err != nil || !report.Completed {
		t.Fatalf("racing backfill failed: %+v, %v", report, err)
	}
	if !hook.changed.Load() {
		t.Fatal("test did not exercise concurrent writer")
	}
	consumed, err := s.ConsumedBySession(ctx, "session", "dev")
	if err != nil || !reflect.DeepEqual(consumed["go"][domain.DifficultyEasy], []string{"current-question"}) {
		t.Fatalf("backfill replaced writer's newer metadata: %#v, %v", consumed, err)
	}
}

func TestRunIndexRejectsRecordsWithoutExpiry(t *testing.T) {
	ctx := context.Background()
	s, server := unindexedRunStore(t)
	run := indexTestRun("persistent", "session")
	seedLegacyRun(t, s, run, 0)
	for _, apply := range []bool{false, true} {
		if report, err := s.MigrateRunIndex(ctx, RunIndexMigrationOptions{Apply: apply, ConfirmLegacyWritersStopped: true}); err == nil || report.Completed {
			t.Fatalf("persistent record accepted: %+v, %v", report, err)
		}
		if server.TTL(s.key(run.ID)) != 0 || !errors.Is(s.IndexReady(ctx), domain.ErrStorageUnavailable) {
			t.Fatal("persistent record mutated or invalid migration marked complete")
		}
	}
}

type forbidScanHook struct{ scans atomic.Int32 }

func (h *forbidScanHook) DialHook(next redis.DialHook) redis.DialHook { return next }
func (h *forbidScanHook) ProcessHook(next redis.ProcessHook) redis.ProcessHook {
	return func(ctx context.Context, command redis.Cmder) error {
		if command.Name() == "scan" || command.Name() == "keys" {
			h.scans.Add(1)
			return errors.New("global enumeration forbidden in player path")
		}
		return next(ctx, command)
	}
}
func (h *forbidScanHook) ProcessPipelineHook(next redis.ProcessPipelineHook) redis.ProcessPipelineHook {
	return next
}

func TestRunIndexPlayerOperationsDoNotEnumerateGlobalRuns(t *testing.T) {
	ctx := context.Background()
	s, _ := newTestRedisRunStore(t, time.Hour)
	hook := &forbidScanHook{}
	s.client.AddHook(hook)
	for _, session := range []string{"requested", "unrelated"} {
		if err := s.Create(ctx, indexTestRun(session, session)); err != nil {
			t.Fatal(err)
		}
	}
	// A corrupt unrelated full record is never fetched while checking this scope.
	s.client.Set(ctx, s.key("unrelated"), "not-json", time.Hour)
	if _, err := s.ConsumedBySession(ctx, "requested", "dev"); err != nil {
		t.Fatal(err)
	}
	if has, err := s.HasSession(ctx, "requested", "dev"); err != nil || !has {
		t.Fatalf("HasSession: %t, %v", has, err)
	}
	if err := s.DeleteBySession(ctx, "requested", "dev"); err != nil {
		t.Fatal(err)
	}
	if s.client.Exists(ctx, s.key("unrelated")).Val() != 1 || hook.scans.Load() != 0 {
		t.Fatal("player operation enumerated or changed unrelated records")
	}
}

func TestRedisRunStoreUnavailableIsNotRunNotFound(t *testing.T) {
	ctx := context.Background()
	s, _ := newTestRedisRunStore(t, time.Hour)
	if _, err := s.Get(ctx, "absent"); !errors.Is(err, domain.ErrRunNotFound) || errors.Is(err, domain.ErrStorageUnavailable) {
		t.Fatalf("absent run sentinel: %v", err)
	}
	s.client.Close()
	if _, err := s.Get(ctx, "absent"); !errors.Is(err, domain.ErrStorageUnavailable) || errors.Is(err, domain.ErrRunNotFound) {
		t.Fatalf("outage sentinel: %v", err)
	}
}
