package store

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"sync"

	"quickquiz/api/internal/domain"
)

type MemorySolutionStore struct {
	mu        sync.RWMutex
	solutions map[string]domain.QuestionSolution
}

func NewMemorySolutionStore(solutions []domain.QuestionSolution) *MemorySolutionStore {
	store := &MemorySolutionStore{solutions: make(map[string]domain.QuestionSolution, len(solutions))}
	for _, solution := range solutions {
		store.solutions[solutionKey(solution)] = solution
	}
	return store
}

func (s *MemorySolutionStore) Get(_ context.Context, key domain.QuestionSolution) (domain.QuestionSolution, error) {
	s.mu.RLock()
	defer s.mu.RUnlock()

	solution, ok := s.solutions[solutionKey(key)]
	if !ok {
		return domain.QuestionSolution{}, domain.ErrSolutionNotFound
	}
	return solution, nil
}

func (s *MemorySolutionStore) Save(_ context.Context, solution domain.QuestionSolution) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.solutions[solutionKey(solution)] = solution
	return nil
}

func solutionKey(solution domain.QuestionSolution) string {
	if solution.CacheIdentity != "" {
		// Hash structured identity, rather than joining untrusted text with a
		// separator. No content/instruction text appears in a cache key.
		payload, _ := json.Marshal([]any{"v2", solution.Theme, solution.Locale, solution.Topic, solution.Difficulty, solution.QuestionID, solution.CacheIdentity})
		sum := sha256.Sum256(payload)
		return "v2:" + hex.EncodeToString(sum[:])
	}
	return fmt.Sprintf(
		"%s:%s:%s:%d:%s",
		solution.Theme,
		solution.Locale,
		solution.Topic,
		solution.Difficulty,
		solution.QuestionID,
	)
}
