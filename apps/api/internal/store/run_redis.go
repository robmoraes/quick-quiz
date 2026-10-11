package store

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/redis/go-redis/v9"

	"quickquiz/api/internal/domain"
)

const redisTransactionRetries = 16

type RedisRunStoreConfig struct {
	Addr      string
	Username  string
	Password  string
	DB        int
	TLSConfig *tls.Config
	KeyPrefix string
	TTL       time.Duration
	// Operator dry-run clients must not bootstrap an empty database implicitly.
	DisableIndexBootstrap bool
}

type RedisRunStore struct {
	client    *redis.Client
	keyPrefix string
	ttl       time.Duration
}

func NewRedisRunStore(ctx context.Context, config RedisRunStoreConfig) (*RedisRunStore, error) {
	if strings.TrimSpace(config.Addr) == "" {
		return nil, errors.New("REDIS_ADDR is required")
	}
	if config.TTL < time.Millisecond {
		return nil, errors.New("SESSION_TTL must be at least one millisecond")
	}

	keyPrefix := strings.TrimSpace(config.KeyPrefix)
	if keyPrefix == "" {
		keyPrefix = "quickquiz:runs:"
	}
	if strings.ContainsAny(keyPrefix, "*?[]\\") {
		return nil, errors.New("REDIS_KEY_PREFIX cannot contain Redis glob characters")
	}

	client := redis.NewClient(&redis.Options{
		Addr:      config.Addr,
		Username:  config.Username,
		Password:  config.Password,
		DB:        config.DB,
		TLSConfig: config.TLSConfig,
	})
	if err := client.Ping(ctx).Err(); err != nil {
		_ = client.Close()
		return nil, indexError(err)
	}

	store := newRedisRunStore(client, keyPrefix, config.TTL)
	if !config.DisableIndexBootstrap {
		if err := store.bootstrapIndex(ctx); err != nil {
			_ = client.Close()
			return nil, err
		}
	}
	return store, nil
}

func newRedisRunStore(client *redis.Client, keyPrefix string, ttl time.Duration) *RedisRunStore {
	return &RedisRunStore{client: client, keyPrefix: keyPrefix, ttl: ttl}
}

func (s *RedisRunStore) Close() error {
	return s.client.Close()
}

func (s *RedisRunStore) Create(ctx context.Context, run *domain.Run) error {
	return s.store(ctx, run, true)
}

func (s *RedisRunStore) Get(ctx context.Context, id string) (*domain.Run, error) {
	payload, err := s.client.Get(ctx, s.key(id)).Bytes()
	if errors.Is(err, redis.Nil) {
		return nil, errRunNotFound{}
	}
	if err != nil {
		return nil, indexError(err)
	}

	run, err := decodeRedisRun(payload)
	if err != nil {
		return nil, indexError(errors.New("invalid Redis run record"))
	}
	return run, nil
}

func (s *RedisRunStore) Save(ctx context.Context, run *domain.Run) error {
	return s.store(ctx, run, false)
}

func (s *RedisRunStore) ListTracked(ctx context.Context) ([]domain.Run, error) {
	runs := make([]domain.Run, 0)
	err := s.forEachRun(ctx, func(run *domain.Run) error {
		runs = append(runs, *run)
		return nil
	})
	if err != nil {
		return nil, err
	}
	return runs, nil
}

func (s *RedisRunStore) DeleteBySession(ctx context.Context, sessionID, theme string) error {
	return s.deleteIndexedSession(ctx, sessionID, theme)
}

func (s *RedisRunStore) UsedQuestionIDs(ctx context.Context, sessionID, theme, topic string, difficulty domain.Difficulty) (map[string]bool, error) {
	usedQuestionIDs := make(map[string]bool)
	consumption, err := s.ConsumedBySession(ctx, sessionID, theme)
	if err != nil {
		return nil, err
	}
	for _, id := range consumption[topic][difficulty] {
		usedQuestionIDs[id] = true
	}
	return usedQuestionIDs, nil
}

func (s *RedisRunStore) TrackSolutionRequest(ctx context.Context, runID, questionID string, limit int) (bool, error) {
	key := s.key(runID)
	for attempt := 0; attempt < redisTransactionRetries; attempt++ {
		allowed := false
		err := s.client.Watch(ctx, func(tx *redis.Tx) error {
			payload, err := tx.Get(ctx, key).Bytes()
			if errors.Is(err, redis.Nil) {
				return errRunNotFound{}
			}
			if err != nil {
				return err
			}

			run, err := decodeRedisRun(payload)
			if err != nil {
				return err
			}
			if run.SolutionRequestQuestionIDs == nil {
				run.SolutionRequestQuestionIDs = make(map[string]bool)
			}
			if run.SolutionRequestQuestionIDs[questionID] {
				allowed = true
				return nil
			}
			if limit <= 0 || len(run.SolutionRequestQuestionIDs) >= limit {
				return nil
			}

			run.SolutionRequestQuestionIDs[questionID] = true
			run.UpdatedAt = time.Now().UTC()
			updatedPayload, err := json.Marshal(run)
			if err != nil {
				return err
			}
			metadata, err := s.metadata(run)
			if err != nil {
				return err
			}
			_, err = tx.TxPipelined(ctx, func(pipe redis.Pipeliner) error {
				pipe.Eval(ctx, indexedWriteLua, s.writeIndexKeys(run), s.writeIndexArgs(run, updatedPayload, metadata, false)...)
				return nil
			})
			if err == nil {
				allowed = true
			}
			return err
		}, key)
		if errors.Is(err, redis.TxFailedErr) {
			continue
		}
		if err != nil {
			if errors.As(err, new(errRunNotFound)) {
				return false, err
			}
			return false, indexError(err)
		}
		return allowed, nil
	}
	return false, indexError(errors.New("concurrent update retry limit reached"))
}

func (s *RedisRunStore) store(ctx context.Context, run *domain.Run, requireReady bool) error {
	metadata, err := s.metadata(run)
	if err != nil {
		return indexError(err)
	}
	payload, err := json.Marshal(run)
	if err != nil {
		return indexError(errors.New("cannot encode Redis run record"))
	}
	_, err = s.client.Eval(ctx, indexedWriteLua, s.writeIndexKeys(run), s.writeIndexArgs(run, payload, metadata, requireReady)...).Result()
	return indexError(err)
}

func (s *RedisRunStore) forEachRun(ctx context.Context, visit func(*domain.Run) error) error {
	var cursor uint64
	for {
		keys, nextCursor, err := s.client.Scan(ctx, cursor, s.keyPrefix+"*", 100).Result()
		if err != nil {
			return fmt.Errorf("scan runs in Redis: %w", err)
		}
		if len(keys) > 0 {
			values, err := s.client.MGet(ctx, keys...).Result()
			if err != nil {
				return fmt.Errorf("read runs from Redis: %w", err)
			}
			for index, value := range values {
				if value == nil {
					continue
				}
				payload, ok := value.(string)
				if !ok {
					return fmt.Errorf("read run %q from Redis: unexpected value type %T", keys[index], value)
				}
				run, err := decodeRedisRun([]byte(payload))
				if err != nil {
					return fmt.Errorf("decode run %q from Redis: %w", keys[index], err)
				}
				if err := visit(run); err != nil {
					return err
				}
			}
		}
		cursor = nextCursor
		if cursor == 0 {
			return nil
		}
	}
}

func (s *RedisRunStore) key(runID string) string {
	return s.keyPrefix + runID
}

func decodeRedisRun(payload []byte) (*domain.Run, error) {
	var run domain.Run
	if err := json.Unmarshal(payload, &run); err != nil {
		return nil, err
	}
	return &run, nil
}
