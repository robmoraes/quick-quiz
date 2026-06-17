package app

import (
	"context"
	"errors"
	"sync"
	"testing"
	"time"

	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/i18n"
	"quickquiz/api/internal/store"
)

func TestSolutionServiceReturnsCachedSolution(t *testing.T) {
	question := solutionTestQuestion("en-US")
	cached := domain.QuestionSolution{
		Theme:        question.Theme,
		Locale:       question.Locale,
		Topic:        question.Topic,
		Difficulty:   question.Difficulty,
		QuestionID:   question.ID,
		Explanation:  "Cached explanation",
		QuestionHash: questionHash(question),
	}
	generator := &countingSolutionGenerator{}
	runStore := store.NewMemoryRunStore(time.Hour)
	createSolutionTestRun(t, runStore, "run_solution_001", false)
	service := NewSolutionService(
		store.NewMemoryQuestionStoreWithThemeMetadata([]domain.Question{question}, nil, []domain.Theme{{ID: "dev", Active: true}}),
		runStore,
		store.NewMemorySolutionStore([]domain.QuestionSolution{cached}),
		generator,
		i18n.NewManager("en-US", []string{"en-US"}),
	)

	output, err := service.QuestionSolution(context.Background(), QuestionSolutionInput{
		Theme:      "dev",
		Locale:     "en-US",
		RunID:      "run_solution_001",
		QuestionID: "go-easy-001",
	})
	if err != nil {
		t.Fatalf("QuestionSolution() error = %v", err)
	}

	if !output.Cached {
		t.Fatalf("expected cached output")
	}
	if output.Explanation != "Cached explanation" {
		t.Fatalf("expected cached explanation, got %q", output.Explanation)
	}
	if generator.Count() != 0 {
		t.Fatalf("expected generator not to be called, got %d calls", generator.Count())
	}
}

func TestSolutionServiceGeneratesOncePerLocaleForConcurrentRequests(t *testing.T) {
	generator := &countingSolutionGenerator{delay: 10 * time.Millisecond}
	runStore := store.NewMemoryRunStore(time.Hour)
	createSolutionTestRun(t, runStore, "run_solution_001", false)
	service := NewSolutionService(
		store.NewMemoryQuestionStoreWithThemeMetadata(
			[]domain.Question{
				solutionTestQuestion("en-US"),
				solutionTestQuestion("pt-BR"),
			},
			nil,
			[]domain.Theme{{ID: "dev", Active: true}},
		),
		runStore,
		store.NewMemorySolutionStore(nil),
		generator,
		i18n.NewManager("en-US", []string{"en-US", "pt-BR"}),
	)

	const goroutines = 8
	var wg sync.WaitGroup
	errors := make(chan error, goroutines)
	for i := 0; i < goroutines; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			output, err := service.QuestionSolution(context.Background(), QuestionSolutionInput{
				Theme:      "dev",
				Locale:     "pt-BR",
				RunID:      "run_solution_001",
				QuestionID: "go-easy-001",
			})
			if err != nil {
				errors <- err
				return
			}
			if output.Explanation != "Generated solution for pt-BR" {
				errors <- &unexpectedSolutionError{got: output.Explanation}
			}
		}()
	}
	wg.Wait()
	close(errors)

	for err := range errors {
		t.Fatalf("QuestionSolution() error = %v", err)
	}
	if got := generator.Count(); got != 2 {
		t.Fatalf("expected one generation per supported locale, got %d calls", got)
	}
}

func TestSolutionServiceRejectsCorrectRunAnswer(t *testing.T) {
	question := solutionTestQuestion("en-US")
	generator := &countingSolutionGenerator{}
	runStore := store.NewMemoryRunStore(time.Hour)
	createSolutionTestRun(t, runStore, "run_solution_001", true)
	service := NewSolutionService(
		store.NewMemoryQuestionStoreWithThemeMetadata([]domain.Question{question}, nil, []domain.Theme{{ID: "dev", Active: true}}),
		runStore,
		store.NewMemorySolutionStore(nil),
		generator,
		i18n.NewManager("en-US", []string{"en-US"}),
	)

	_, err := service.QuestionSolution(context.Background(), QuestionSolutionInput{
		Theme:      "dev",
		Locale:     "en-US",
		RunID:      "run_solution_001",
		QuestionID: "go-easy-001",
	})
	if !errors.Is(err, ErrSolutionForbidden) {
		t.Fatalf("expected ErrSolutionForbidden, got %v", err)
	}
	if generator.Count() != 0 {
		t.Fatalf("expected generator not to be called, got %d calls", generator.Count())
	}
}

func TestSolutionServiceRateLimitsRunRequests(t *testing.T) {
	question := solutionTestQuestion("en-US")
	generator := &countingSolutionGenerator{}
	runStore := store.NewMemoryRunStore(time.Hour)
	now := time.Now().UTC()
	err := runStore.Create(context.Background(), &domain.Run{
		ID:            "run_solution_001",
		SessionID:     "session_solution_001",
		Theme:         "dev",
		Locale:        "en-US",
		Topic:         "go",
		Difficulty:    domain.DifficultyEasy,
		QuestionLimit: 1,
		UsedQuestionIDs: map[string]bool{
			"go-easy-001": true,
		},
		SolutionRequestQuestionIDs: map[string]bool{
			"other-question": true,
		},
		Answers: []domain.AnswerRecord{{
			QuestionID: "go-easy-001",
			Prompt:     "What does gofmt do?",
			Correct:    false,
		}},
		Finished:     true,
		FinishReason: domain.FinishReasonMaxQuestionsReached,
		CreatedAt:    now,
		UpdatedAt:    now,
	})
	if err != nil {
		t.Fatalf("Create() error = %v", err)
	}
	service := NewSolutionService(
		store.NewMemoryQuestionStoreWithThemeMetadata([]domain.Question{question}, nil, []domain.Theme{{ID: "dev", Active: true}}),
		runStore,
		store.NewMemorySolutionStore(nil),
		generator,
		i18n.NewManager("en-US", []string{"en-US"}),
	)

	_, err = service.QuestionSolution(context.Background(), QuestionSolutionInput{
		Theme:      "dev",
		Locale:     "en-US",
		RunID:      "run_solution_001",
		QuestionID: "go-easy-001",
	})
	if !errors.Is(err, ErrSolutionRateLimited) {
		t.Fatalf("expected ErrSolutionRateLimited, got %v", err)
	}
	if generator.Count() != 0 {
		t.Fatalf("expected generator not to be called, got %d calls", generator.Count())
	}
}

type countingSolutionGenerator struct {
	mu    sync.Mutex
	delay time.Duration
	count int
}

func (g *countingSolutionGenerator) GenerateSolution(_ context.Context, input GenerateSolutionInput) (string, error) {
	if g.delay > 0 {
		time.Sleep(g.delay)
	}
	g.mu.Lock()
	defer g.mu.Unlock()
	g.count++
	return "Generated solution for " + input.Locale, nil
}

func (g *countingSolutionGenerator) Model() string {
	return "fake-model"
}

func (g *countingSolutionGenerator) Count() int {
	g.mu.Lock()
	defer g.mu.Unlock()
	return g.count
}

type unexpectedSolutionError struct {
	got string
}

func (e *unexpectedSolutionError) Error() string {
	return "unexpected explanation: " + e.got
}

func solutionTestQuestion(locale string) domain.Question {
	return domain.Question{
		ID:             "go-easy-001",
		Theme:          "dev",
		Locale:         locale,
		Topic:          "go",
		Difficulty:     domain.DifficultyEasy,
		Prompt:         "What does gofmt do?",
		CorrectOptions: []string{"Formats Go source code"},
		WrongOptions:   []string{"Runs tests", "Compiles binaries"},
	}
}

func createSolutionTestRun(t *testing.T, runStore *store.MemoryRunStore, runID string, correct bool) {
	t.Helper()
	now := time.Now().UTC()
	err := runStore.Create(context.Background(), &domain.Run{
		ID:            runID,
		SessionID:     "session_solution_001",
		Theme:         "dev",
		Locale:        "en-US",
		Topic:         "go",
		Difficulty:    domain.DifficultyEasy,
		QuestionLimit: 1,
		UsedQuestionIDs: map[string]bool{
			"go-easy-001": true,
		},
		Answers: []domain.AnswerRecord{{
			QuestionID: "go-easy-001",
			Prompt:     "What does gofmt do?",
			Correct:    correct,
		}},
		Finished:     true,
		FinishReason: domain.FinishReasonMaxQuestionsReached,
		CreatedAt:    now,
		UpdatedAt:    now,
	})
	if err != nil {
		t.Fatalf("Create() error = %v", err)
	}
}
