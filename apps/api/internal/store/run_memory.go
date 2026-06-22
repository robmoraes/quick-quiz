package store

import (
	"context"
	"sync"
	"time"

	"quickquiz/api/internal/domain"
)

type MemoryRunStore struct {
	mu   sync.RWMutex
	ttl  time.Duration
	runs map[string]*domain.Run
}

func NewMemoryRunStore(ttl time.Duration) *MemoryRunStore {
	return &MemoryRunStore{
		ttl:  ttl,
		runs: make(map[string]*domain.Run),
	}
}

func (s *MemoryRunStore) Create(_ context.Context, run *domain.Run) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.cleanupLocked()
	s.runs[run.ID] = cloneRun(run)
	return nil
}

func (s *MemoryRunStore) Get(_ context.Context, id string) (*domain.Run, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.cleanupLocked()
	run, ok := s.runs[id]
	if !ok {
		return nil, errRunNotFound{}
	}
	return cloneRun(run), nil
}

func (s *MemoryRunStore) Save(_ context.Context, run *domain.Run) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.cleanupLocked()
	s.runs[run.ID] = cloneRun(run)
	return nil
}

func (s *MemoryRunStore) ListTracked(_ context.Context) ([]domain.Run, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.cleanupLocked()
	runs := make([]domain.Run, 0, len(s.runs))
	for _, run := range s.runs {
		runs = append(runs, *cloneRun(run))
	}
	return runs, nil
}

func (s *MemoryRunStore) DeleteBySession(_ context.Context, sessionID, theme string) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.cleanupLocked()
	for id, run := range s.runs {
		if run.SessionID == sessionID && run.Theme == theme {
			delete(s.runs, id)
		}
	}
	return nil
}

func (s *MemoryRunStore) UsedQuestionIDs(_ context.Context, sessionID, theme, topic string, difficulty domain.Difficulty) (map[string]bool, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.cleanupLocked()
	usedQuestionIDs := make(map[string]bool)
	for _, run := range s.runs {
		if run.SessionID != sessionID || run.Theme != theme || run.Topic != topic || run.Difficulty != difficulty {
			continue
		}
		for id, used := range run.UsedQuestionIDs {
			if used {
				usedQuestionIDs[id] = true
			}
		}
	}
	return usedQuestionIDs, nil
}

func (s *MemoryRunStore) TrackSolutionRequest(_ context.Context, runID, questionID string, limit int) (bool, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.cleanupLocked()
	run, ok := s.runs[runID]
	if !ok {
		return false, errRunNotFound{}
	}
	if run.SolutionRequestQuestionIDs == nil {
		run.SolutionRequestQuestionIDs = make(map[string]bool)
	}
	if run.SolutionRequestQuestionIDs[questionID] {
		return true, nil
	}
	if limit <= 0 || len(run.SolutionRequestQuestionIDs) >= limit {
		return false, nil
	}

	run.SolutionRequestQuestionIDs[questionID] = true
	run.UpdatedAt = time.Now().UTC()
	return true, nil
}

func (s *MemoryRunStore) cleanupLocked() {
	if s.ttl <= 0 {
		return
	}

	now := time.Now().UTC()
	for id, run := range s.runs {
		if now.Sub(run.UpdatedAt) > s.ttl {
			delete(s.runs, id)
		}
	}
}

func cloneRun(run *domain.Run) *domain.Run {
	cloned := *run

	cloned.UsedQuestionIDs = make(map[string]bool, len(run.UsedQuestionIDs))
	for id, used := range run.UsedQuestionIDs {
		cloned.UsedQuestionIDs[id] = used
	}

	cloned.SolutionRequestQuestionIDs = make(map[string]bool, len(run.SolutionRequestQuestionIDs))
	for id, requested := range run.SolutionRequestQuestionIDs {
		cloned.SolutionRequestQuestionIDs[id] = requested
	}

	cloned.Answers = append([]domain.AnswerRecord(nil), run.Answers...)

	if run.CurrentQuestion != nil {
		current := *run.CurrentQuestion
		current.Options = append([]domain.Option(nil), run.CurrentQuestion.Options...)
		cloned.CurrentQuestion = &current
	}

	return &cloned
}

type errRunNotFound struct{}

func (errRunNotFound) Error() string {
	return "run not found"
}
