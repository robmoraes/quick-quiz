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
	if config.TTL <= 0 {
		return nil, errors.New("SESSION_TTL must be greater than zero")
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
		return nil, fmt.Errorf("connect to Redis at %s: %w", config.Addr, err)
	}

	return newRedisRunStore(client, keyPrefix, config.TTL), nil
}

func newRedisRunStore(client *redis.Client, keyPrefix string, ttl time.Duration) *RedisRunStore {
	return &RedisRunStore{client: client, keyPrefix: keyPrefix, ttl: ttl}
}

func (s *RedisRunStore) Close() error {
	return s.client.Close()
}

func (s *RedisRunStore) Create(ctx context.Context, run *domain.Run) error {
	return s.store(ctx, run)
}

func (s *RedisRunStore) Get(ctx context.Context, id string) (*domain.Run, error) {
	payload, err := s.client.Get(ctx, s.key(id)).Bytes()
	if errors.Is(err, redis.Nil) {
		return nil, errRunNotFound{}
	}
	if err != nil {
		return nil, fmt.Errorf("get run %q from Redis: %w", id, err)
	}

	run, err := decodeRedisRun(payload)
	if err != nil {
		return nil, fmt.Errorf("decode run %q from Redis: %w", id, err)
	}
	return run, nil
}

func (s *RedisRunStore) Save(ctx context.Context, run *domain.Run) error {
	return s.store(ctx, run)
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
	keys := make([]string, 0)
	err := s.forEachRun(ctx, func(run *domain.Run) error {
		if run.SessionID == sessionID && run.Theme == theme {
			keys = append(keys, s.key(run.ID))
		}
		return nil
	})
	if err != nil {
		return err
	}
	if len(keys) == 0 {
		return nil
	}
	if err := s.client.Del(ctx, keys...).Err(); err != nil {
		return fmt.Errorf("delete session runs from Redis: %w", err)
	}
	return nil
}

func (s *RedisRunStore) UsedQuestionIDs(ctx context.Context, sessionID, theme, topic string, difficulty domain.Difficulty) (map[string]bool, error) {
	usedQuestionIDs := make(map[string]bool)
	err := s.forEachRun(ctx, func(run *domain.Run) error {
		if run.SessionID != sessionID || run.Theme != theme || run.Topic != topic || run.Difficulty != difficulty {
			return nil
		}
		for id, used := range run.UsedQuestionIDs {
			if used {
				usedQuestionIDs[id] = true
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
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
			_, err = tx.TxPipelined(ctx, func(pipe redis.Pipeliner) error {
				pipe.Set(ctx, key, updatedPayload, s.ttl)
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
			return false, fmt.Errorf("track solution request in Redis: %w", err)
		}
		return allowed, nil
	}
	return false, errors.New("track solution request in Redis: concurrent update retry limit reached")
}

func (s *RedisRunStore) store(ctx context.Context, run *domain.Run) error {
	payload, err := json.Marshal(run)
	if err != nil {
		return fmt.Errorf("encode run %q for Redis: %w", run.ID, err)
	}
	if err := s.client.Set(ctx, s.key(run.ID), payload, s.ttl).Err(); err != nil {
		return fmt.Errorf("store run %q in Redis: %w", run.ID, err)
	}
	return nil
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
