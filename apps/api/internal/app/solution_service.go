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
	Theme                 string
	Locale                string
	Topic                 string
	Difficulty            domain.Difficulty
	QuestionID            string
	Prompt                string
	CorrectOptions        []string
	WrongOptions          []string
	EffectiveInstructions string
}

type SolutionGenerationContext struct {
	Instructions          string
	PromptDigest          string
	GeneratorConfigDigest string
	Model                 string
}

type PreparedSolutionGenerator interface {
	PrepareSolution(context.Context, string) (SolutionGenerationContext, error)
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
	run, err := s.runs.Get(ctx, input.RunID)
	if err != nil {
		return QuestionSolutionOutput{}, runStorageError(err)
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
		return QuestionSolutionOutput{}, runStorageError(err)
	}
	if !allowed {
		return QuestionSolutionOutput{}, ErrSolutionRateLimited
	}

	resolvedLocale := s.locales.Resolve(input.Locale, "")
	snapshot, err := runQuestionSnapshot(run, input.QuestionID)
	if err != nil {
		return QuestionSolutionOutput{}, err
	}
	question, ok := snapshot.Translations[resolvedLocale]
	if !ok {
		return QuestionSolutionOutput{}, ErrSolutionUnavailable
	}
	prepared, err := prepareSolution(ctx, s.generator, input.Theme)
	if err != nil {
		return QuestionSolutionOutput{}, fmt.Errorf("%w: prompt preparation failed", ErrSolutionUnavailable)
	}
	targetKey := solutionKeyFromSnapshot(question, snapshot.Digest, prepared)
	if solution, ok, err := s.cachedSolution(ctx, targetKey, questionHash(question)); err != nil {
		return QuestionSolutionOutput{}, err
	} else if ok {
		return QuestionSolutionOutput{QuestionSolution: solution, Cached: true}, nil
	}

	unlock := s.locks.lock(canonicalSolutionLockKey(question) + ":" + snapshot.Digest + ":" + prepared.PromptDigest + ":" + prepared.GeneratorConfigDigest)
	defer unlock()

	if solution, ok, err := s.cachedSolution(ctx, targetKey, questionHash(question)); err != nil {
		return QuestionSolutionOutput{}, err
	} else if ok {
		return QuestionSolutionOutput{QuestionSolution: solution, Cached: true}, nil
	}

	generatedTarget := false
	for _, locale := range s.locales.Supported() {
		localeQuestion, ok := snapshot.Translations[locale]
		if !ok {
			continue
		}

		localeHash := questionHash(localeQuestion)
		localeKey := solutionKeyFromSnapshot(localeQuestion, snapshot.Digest, prepared)
		if _, ok, err := s.cachedSolution(ctx, localeKey, localeHash); err != nil {
			return QuestionSolutionOutput{}, err
		} else if ok {
			continue
		}

		explanation, err := s.generator.GenerateSolution(ctx, GenerateSolutionInput{
			Theme:                 localeQuestion.Theme,
			Locale:                localeQuestion.Locale,
			Topic:                 localeQuestion.Topic,
			Difficulty:            localeQuestion.Difficulty,
			QuestionID:            localeQuestion.ID,
			Prompt:                localeQuestion.Prompt,
			CorrectOptions:        append([]string(nil), localeQuestion.CorrectOptions...),
			WrongOptions:          append([]string(nil), localeQuestion.WrongOptions...),
			EffectiveInstructions: prepared.Instructions,
		})
		if err != nil {
			return QuestionSolutionOutput{}, fmt.Errorf("%w: generation failed", ErrSolutionUnavailable)
		}

		localeKey.Explanation = explanation
		localeKey.Model = prepared.Model
		localeKey.QuestionHash = localeHash
		localeKey.GeneratedAt = s.now().Format(time.RFC3339)
		if err := s.solutions.Save(ctx, localeKey); err != nil {
			return QuestionSolutionOutput{}, fmt.Errorf("%w: solution cache write failed", domain.ErrStorageUnavailable)
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
	if reader, ok := s.questions.(RuntimeQuestionReader); ok {
		return reader.LookupQuestion(ctx, domain.QuestionLookup{QuestionKey: domain.QuestionKey{Theme: theme, Topic: topic, Difficulty: difficulty, QuestionID: questionID}, Locale: locale})
	}
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
		return domain.QuestionSolution{}, false, fmt.Errorf("%w: solution cache read failed", domain.ErrStorageUnavailable)
	}
	if solution.QuestionHash != expectedQuestionHash || solution.CacheIdentity != key.CacheIdentity || strings.TrimSpace(solution.Explanation) == "" {
		return domain.QuestionSolution{}, false, nil
	}
	return solution, true, nil
}

func runQuestionSnapshot(run *domain.Run, questionID string) (domain.QuestionSnapshot, error) {
	if run.Version < 2 {
		// Old answered records contain no correct options. A matching ID or prompt
		// cannot prove that mutable current content is the historical answer.
		return domain.QuestionSnapshot{}, ErrSolutionUnavailable
	}
	for _, snapshot := range run.QuestionSnapshots {
		if snapshot.Key.QuestionID != questionID {
			continue
		}
		if snapshot.Key.Theme != run.Theme || snapshot.Key.Topic != run.Topic || snapshot.Key.Difficulty != run.Difficulty || snapshot.Digest != domain.SnapshotDigest(snapshot) {
			return domain.QuestionSnapshot{}, ErrSolutionUnavailable
		}
		return snapshot, nil
	}
	return domain.QuestionSnapshot{}, ErrQuestionNotFound
}

func prepareSolution(ctx context.Context, generator SolutionGenerator, theme string) (SolutionGenerationContext, error) {
	if prepared, ok := generator.(PreparedSolutionGenerator); ok {
		captured, err := prepared.PrepareSolution(ctx, theme)
		if err != nil {
			return SolutionGenerationContext{}, err
		}
		if strings.TrimSpace(captured.Instructions) == "" || captured.PromptDigest != hashText(captured.Instructions) || captured.GeneratorConfigDigest == "" {
			return SolutionGenerationContext{}, ErrSolutionUnavailable
		}
		return captured, nil
	}
	return SolutionGenerationContext{Instructions: DefaultSolutionPrompt, PromptDigest: hashText(DefaultSolutionPrompt), GeneratorConfigDigest: hashText(fmt.Sprintf("%T:%s", generator, generatorModel(generator))), Model: generatorModel(generator)}, nil
}

func solutionKeyFromSnapshot(question domain.Question, snapshotDigest string, prepared SolutionGenerationContext) domain.QuestionSolution {
	key := solutionKeyFromQuestion(question)
	payload, _ := json.Marshal([]string{"v2", snapshotDigest, questionHash(question), prepared.PromptDigest, prepared.GeneratorConfigDigest, prepared.Model, question.Locale})
	key.CacheIdentity = hashText(string(payload))
	return key
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
