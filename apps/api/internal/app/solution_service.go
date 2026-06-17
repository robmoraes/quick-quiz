package app

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"sync"
	"time"

	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/i18n"
)

type SolutionRepository interface {
	Get(ctx context.Context, key domain.QuestionSolution) (domain.QuestionSolution, error)
	Save(ctx context.Context, solution domain.QuestionSolution) error
}

type SolutionGenerator interface {
	GenerateSolution(ctx context.Context, input GenerateSolutionInput) (string, error)
}

type GenerateSolutionInput struct {
	Theme          string
	Locale         string
	Topic          string
	Difficulty     domain.Difficulty
	QuestionID     string
	Prompt         string
	CorrectOptions []string
	WrongOptions   []string
}

type QuestionSolutionInput struct {
	Theme      string
	Locale     string
	RunID      string
	QuestionID string
}

type QuestionSolutionOutput struct {
	domain.QuestionSolution
	Cached bool `json:"cached"`
}

type SolutionService struct {
	questions QuestionRepository
	runs      RunRepository
	solutions SolutionRepository
	generator SolutionGenerator
	locales   i18n.Manager
	locks     *solutionLocks
	now       func() time.Time
}

func NewSolutionService(questions QuestionRepository, runs RunRepository, solutions SolutionRepository, generator SolutionGenerator, locales i18n.Manager) *SolutionService {
	return &SolutionService{
		questions: questions,
		runs:      runs,
		solutions: solutions,
		generator: generator,
		locales:   locales,
		locks:     newSolutionLocks(),
		now:       func() time.Time { return time.Now().UTC() },
	}
}

func (s *SolutionService) QuestionSolution(ctx context.Context, input QuestionSolutionInput) (QuestionSolutionOutput, error) {
	if input.Theme == "" || input.RunID == "" || input.QuestionID == "" {
		return QuestionSolutionOutput{}, ErrInvalidInput
	}
	if err := validateTheme(ctx, s.questions, input.Theme); err != nil {
		return QuestionSolutionOutput{}, err
	}

	run, err := s.runs.Get(ctx, input.RunID)
	if err != nil {
		return QuestionSolutionOutput{}, ErrRunNotFound
	}
	if run.Theme != input.Theme {
		return QuestionSolutionOutput{}, ErrRunNotFound
	}
	if !runHasRejectedAnswer(run, input.QuestionID) {
		return QuestionSolutionOutput{}, ErrSolutionForbidden
	}

	limit := solutionRequestLimit(run)
	allowed, err := s.runs.TrackSolutionRequest(ctx, run.ID, input.QuestionID, limit)
	if err != nil {
		return QuestionSolutionOutput{}, ErrRunNotFound
	}
	if !allowed {
		return QuestionSolutionOutput{}, ErrSolutionRateLimited
	}

	resolvedLocale := s.locales.Resolve(input.Locale, "")
	question, err := s.question(ctx, input.Theme, resolvedLocale, run.Topic, run.Difficulty, input.QuestionID)
	if err != nil {
		return QuestionSolutionOutput{}, err
	}
	targetKey := solutionKeyFromQuestion(question)
	if solution, ok, err := s.cachedSolution(ctx, targetKey, questionHash(question)); err != nil {
		return QuestionSolutionOutput{}, err
	} else if ok {
		return QuestionSolutionOutput{QuestionSolution: solution, Cached: true}, nil
	}

	unlock := s.locks.lock(canonicalSolutionLockKey(question))
	defer unlock()

	if solution, ok, err := s.cachedSolution(ctx, targetKey, questionHash(question)); err != nil {
		return QuestionSolutionOutput{}, err
	} else if ok {
		return QuestionSolutionOutput{QuestionSolution: solution, Cached: true}, nil
	}

	questionsByLocale, err := s.questionsByLocale(ctx, input.Theme, run.Topic, run.Difficulty, input.QuestionID)
	if err != nil {
		return QuestionSolutionOutput{}, err
	}

	generatedTarget := false
	for _, locale := range s.locales.Supported() {
		localeQuestion, ok := questionsByLocale[locale]
		if !ok {
			continue
		}

		localeHash := questionHash(localeQuestion)
		if _, ok, err := s.cachedSolution(ctx, solutionKeyFromQuestion(localeQuestion), localeHash); err != nil {
			return QuestionSolutionOutput{}, err
		} else if ok {
			continue
		}

		explanation, err := s.generator.GenerateSolution(ctx, GenerateSolutionInput{
			Theme:          localeQuestion.Theme,
			Locale:         localeQuestion.Locale,
			Topic:          localeQuestion.Topic,
			Difficulty:     localeQuestion.Difficulty,
			QuestionID:     localeQuestion.ID,
			Prompt:         localeQuestion.Prompt,
			CorrectOptions: append([]string(nil), localeQuestion.CorrectOptions...),
			WrongOptions:   append([]string(nil), localeQuestion.WrongOptions...),
		})
		if err != nil {
			return QuestionSolutionOutput{}, fmt.Errorf("%w: %v", ErrSolutionUnavailable, err)
		}

		if err := s.solutions.Save(ctx, domain.QuestionSolution{
			Theme:        localeQuestion.Theme,
			Locale:       localeQuestion.Locale,
			Topic:        localeQuestion.Topic,
			Difficulty:   localeQuestion.Difficulty,
			QuestionID:   localeQuestion.ID,
			Explanation:  explanation,
			Model:        generatorModel(s.generator),
			QuestionHash: localeHash,
			GeneratedAt:  s.now().Format(time.RFC3339),
		}); err != nil {
			return QuestionSolutionOutput{}, err
		}
		if localeQuestion.Locale == resolvedLocale {
			generatedTarget = true
		}
	}

	solution, ok, err := s.cachedSolution(ctx, targetKey, questionHash(question))
	if err != nil {
		return QuestionSolutionOutput{}, err
	}
	if !ok {
		return QuestionSolutionOutput{}, ErrSolutionUnavailable
	}
	return QuestionSolutionOutput{QuestionSolution: solution, Cached: !generatedTarget}, nil
}

func (s *SolutionService) question(ctx context.Context, theme, locale, topic string, difficulty domain.Difficulty, questionID string) (domain.Question, error) {
	questions, err := s.questions.ListByThemeLocaleTopicAndDifficulty(ctx, theme, locale, topic, difficulty)
	if err != nil {
		return domain.Question{}, err
	}
	for _, question := range questions {
		if question.ID == questionID {
			return question, nil
		}
	}
	return domain.Question{}, ErrQuestionNotFound
}

func (s *SolutionService) questionsByLocale(ctx context.Context, theme, topic string, difficulty domain.Difficulty, questionID string) (map[string]domain.Question, error) {
	questionsByLocale := make(map[string]domain.Question)
	for _, locale := range s.locales.Supported() {
		question, err := s.question(ctx, theme, locale, topic, difficulty, questionID)
		if err != nil {
			if errors.Is(err, ErrQuestionNotFound) {
				continue
			}
			return nil, err
		}
		questionsByLocale[locale] = question
	}
	if len(questionsByLocale) == 0 {
		return nil, ErrQuestionNotFound
	}
	return questionsByLocale, nil
}

func (s *SolutionService) cachedSolution(ctx context.Context, key domain.QuestionSolution, expectedQuestionHash string) (domain.QuestionSolution, bool, error) {
	solution, err := s.solutions.Get(ctx, key)
	if err != nil {
		if errors.Is(err, domain.ErrSolutionNotFound) {
			return domain.QuestionSolution{}, false, nil
		}
		return domain.QuestionSolution{}, false, err
	}
	if solution.QuestionHash != expectedQuestionHash || strings.TrimSpace(solution.Explanation) == "" {
		return domain.QuestionSolution{}, false, nil
	}
	return solution, true, nil
}

func solutionKeyFromQuestion(question domain.Question) domain.QuestionSolution {
	return domain.QuestionSolution{
		Theme:      question.Theme,
		Locale:     question.Locale,
		Topic:      question.Topic,
		Difficulty: question.Difficulty,
		QuestionID: question.ID,
	}
}

func questionHash(question domain.Question) string {
	payload := struct {
		Prompt         string   `json:"prompt"`
		CorrectOptions []string `json:"correctOptions"`
		WrongOptions   []string `json:"wrongOptions"`
	}{
		Prompt:         question.Prompt,
		CorrectOptions: question.CorrectOptions,
		WrongOptions:   question.WrongOptions,
	}
	bytes, _ := json.Marshal(payload)
	sum := sha256.Sum256(bytes)
	return hex.EncodeToString(sum[:])
}

func runHasRejectedAnswer(run *domain.Run, questionID string) bool {
	for _, answer := range run.Answers {
		if answer.QuestionID == questionID {
			return !answer.Correct
		}
	}
	return false
}

func solutionRequestLimit(run *domain.Run) int {
	if len(run.Answers) > 0 {
		return len(run.Answers)
	}
	return run.QuestionLimit
}

func canonicalSolutionLockKey(question domain.Question) string {
	return fmt.Sprintf("%s:%s:%d:%s", question.Theme, question.Topic, question.Difficulty, question.ID)
}

type modelReporter interface {
	Model() string
}

func generatorModel(generator SolutionGenerator) string {
	reporter, ok := generator.(modelReporter)
	if !ok {
		return ""
	}
	return reporter.Model()
}

type solutionLocks struct {
	mu    sync.Mutex
	locks map[string]*sync.Mutex
}

func newSolutionLocks() *solutionLocks {
	return &solutionLocks{locks: make(map[string]*sync.Mutex)}
}

func (l *solutionLocks) lock(key string) func() {
	l.mu.Lock()
	lock, ok := l.locks[key]
	if !ok {
		lock = &sync.Mutex{}
		l.locks[key] = lock
	}
	l.mu.Unlock()

	lock.Lock()
	return lock.Unlock
}
