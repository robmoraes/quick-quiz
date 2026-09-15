package store

import (
	"context"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/redis/go-redis/v9"

	"quickquiz/api/internal/domain"
)

type RedisSolutionStoreConfig struct {
	Addr      string
	Username  string
	Password  string
	DB        int
	TLSConfig *tls.Config
	KeyPrefix string
	TTL       time.Duration
}

type RedisSolutionStore struct {
	client    *redis.Client
	keyPrefix string
	ttl       time.Duration
}

func NewRedisSolutionStore(ctx context.Context, config RedisSolutionStoreConfig) (*RedisSolutionStore, error) {
	if strings.TrimSpace(config.Addr) == "" {
		return nil, errors.New("REDIS_ADDR is required")
	}
	if config.TTL <= 0 {
		return nil, errors.New("SOLUTION_TTL must be greater than zero")
	}

	keyPrefix := strings.TrimSpace(config.KeyPrefix)
	if keyPrefix == "" {
		keyPrefix = "quickquiz:solutions:"
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

	return newRedisSolutionStore(client, keyPrefix, config.TTL), nil
}

func newRedisSolutionStore(client *redis.Client, keyPrefix string, ttl time.Duration) *RedisSolutionStore {
	return &RedisSolutionStore{client: client, keyPrefix: keyPrefix, ttl: ttl}
}

func (s *RedisSolutionStore) Close() error {
	return s.client.Close()
}

func (s *RedisSolutionStore) Get(ctx context.Context, key domain.QuestionSolution) (domain.QuestionSolution, error) {
	payload, err := s.client.Get(ctx, s.key(key)).Bytes()
	if errors.Is(err, redis.Nil) {
		return domain.QuestionSolution{}, domain.ErrSolutionNotFound
	}
	if err != nil {
		return domain.QuestionSolution{}, fmt.Errorf("get solution from Redis: %w", err)
	}

	var record storedSolutionRecord
	if err := json.Unmarshal(payload, &record); err != nil {
		return domain.QuestionSolution{}, fmt.Errorf("decode solution from Redis: %w", err)
	}

	return record.toDomain(), nil
}

func (s *RedisSolutionStore) Save(ctx context.Context, solution domain.QuestionSolution) error {
	payload, err := json.Marshal(storedSolutionRecordFromDomain(solution))
	if err != nil {
		return fmt.Errorf("encode solution for Redis: %w", err)
	}
	if err := s.client.Set(ctx, s.key(solution), payload, s.ttl).Err(); err != nil {
		return fmt.Errorf("store solution in Redis: %w", err)
	}
	return nil
}

func (s *RedisSolutionStore) key(solution domain.QuestionSolution) string {
	sum := sha256.Sum256([]byte(solutionKey(solution)))
	return s.keyPrefix + hex.EncodeToString(sum[:])
}
