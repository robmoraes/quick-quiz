package store

import (
	"context"
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
	return fmt.Sprintf(
		"%s:%s:%s:%d:%s",
		solution.Theme,
		solution.Locale,
		solution.Topic,
		solution.Difficulty,
		solution.QuestionID,
	)
}
