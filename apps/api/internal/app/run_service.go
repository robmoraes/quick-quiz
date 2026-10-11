package app

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"math/big"
	"sort"
	"time"

	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/i18n"
)

var (
	ErrInvalidInput        = errors.New("invalid input")
	ErrThemeRequired       = errors.New("theme is required")
	ErrThemeNotFound       = domain.ErrThemeNotFound
	ErrThemeInactive       = domain.ErrThemeInactive
	ErrRunNotFound         = domain.ErrRunNotFound
	ErrRunFinished         = errors.New("run already finished")
	ErrQuestionMismatch    = errors.New("question does not match current run state")
	ErrQuestionNotFound    = domain.ErrQuestionNotFound
	ErrOptionNotFound      = errors.New("option not found")
	ErrNoQuestionsLeft     = errors.New("no questions left")
	ErrSolutionForbidden   = errors.New("solution forbidden")
	ErrSolutionRateLimited = errors.New("solution rate limited")
	ErrSolutionUnavailable = errors.New("solution unavailable")
)

type QuestionRepository interface {
	Theme(ctx context.Context, theme string) (domain.Theme, error)
	ListThemes(ctx context.Context) ([]domain.Theme, error)
	ListByThemeLocaleTopicAndDifficulty(ctx context.Context, theme, locale, topic string, difficulty domain.Difficulty) ([]domain.Question, error)
	ListMetadata(ctx context.Context, theme, locale, fallbackLocale string) (domain.CatalogMetadata, error)
}

type RunRepository interface {
	Create(ctx context.Context, run *domain.Run) error
	Get(ctx context.Context, id string) (*domain.Run, error)
	Save(ctx context.Context, run *domain.Run) error
	ListTracked(ctx context.Context) ([]domain.Run, error)
	DeleteBySession(ctx context.Context, sessionID, theme string) error
	UsedQuestionIDs(ctx context.Context, sessionID, theme, topic string, difficulty domain.Difficulty) (map[string]bool, error)
	TrackSolutionRequest(ctx context.Context, runID, questionID string, limit int) (bool, error)
}

type RunService struct {
	questions     QuestionRepository
	runs          RunRepository
	questionLimit int
	locales       i18n.Manager
}

func NewRunService(questions QuestionRepository, runs RunRepository, questionLimit int, locales i18n.Manager) *RunService {
	return &RunService{
		questions:     questions,
		runs:          runs,
		questionLimit: questionLimit,
		locales:       locales,
	}
}

type CreateRunInput struct {
	SessionID  string
	Theme      string
	Locale     string
	Topic      string
	Difficulty domain.Difficulty
}

type SessionDifficultiesInput struct {
	SessionID string
	Theme     string
	Locale    string
	Topic     string
}

type SessionTopicsInput struct {
	SessionID string
	Theme     string
	Locale    string
}

type CreateRunOutput struct {
	RunID    string                `json:"runId"`
	Theme    string                `json:"theme"`
	Locale   string                `json:"locale"`
	Question domain.PublicQuestion `json:"question"`
}

type AnswerOutput struct {
	Correct      bool                   `json:"correct"`
	Finished     bool                   `json:"finished"`
	FinishReason domain.FinishReason    `json:"finishReason,omitempty"`
	Question     *domain.PublicQuestion `json:"question,omitempty"`
}

func (s *RunService) Metadata(ctx context.Context, theme, locale string) (domain.CatalogMetadata, error) {
	if theme == "" {
		return domain.CatalogMetadata{}, ErrThemeRequired
	}
	if reader, ok := s.questions.(RuntimeQuestionReader); ok {
		result, err := reader.ReadCatalog(ctx, domain.CatalogReadInput{Theme: theme, Locale: s.locales.Resolve(locale, ""), FallbackLocale: s.locales.Fallback()})
		return result.Metadata, err
	}
	if err := validateTheme(ctx, s.questions, theme); err != nil {
		return domain.CatalogMetadata{}, err
	}

	resolvedLocale := s.locales.Resolve(locale, "")
	metadata, err := s.questions.ListMetadata(ctx, theme, resolvedLocale, s.locales.Fallback())
	if err != nil {
		return domain.CatalogMetadata{}, err
	}
	if len(metadata.Topics) > 0 || resolvedLocale == s.locales.Fallback() {
		return metadata, nil
	}
	return s.questions.ListMetadata(ctx, theme, s.locales.Fallback(), s.locales.Fallback())
}

func (s *RunService) CreateRun(ctx context.Context, input CreateRunInput) (CreateRunOutput, error) {
	if input.SessionID == "" || input.Topic == "" || !input.Difficulty.Valid() {
		return CreateRunOutput{}, ErrInvalidInput
	}
	if input.Theme == "" {
		return CreateRunOutput{}, ErrThemeRequired
	}
	usedQuestionIDs, err := s.runs.UsedQuestionIDs(ctx, input.SessionID, input.Theme, input.Topic, input.Difficulty)
	if err != nil {
		return CreateRunOutput{}, runStorageError(err)
	}
	selected, err := s.selectPackages(ctx, domain.RunPackageSelection{
		Theme: input.Theme, Topic: input.Topic, Difficulty: input.Difficulty,
		Locale: s.locales.Resolve(input.Locale, ""), FallbackLocale: s.locales.Fallback(),
		SupportedLocales: s.locales.Supported(), Limit: s.questionLimit, UsedQuestionIDs: usedQuestionIDs,
	})
	if err != nil {
		return CreateRunOutput{}, err
	}
	if len(selected.Packages) == 0 {
		return CreateRunOutput{}, ErrNoQuestionsLeft
	}

	now := time.Now().UTC()
	run := &domain.Run{
		Version: 2, QuestionSnapshots: selected.Packages, CatalogRevision: selected.Revision,
		CatalogProvider: selected.Provider, CatalogDigest: selected.SourceDigest,
		ID:              "run_" + randomID(16),
		SessionID:       input.SessionID,
		Theme:           input.Theme,
		Locale:          selected.Locale,
		Topic:           input.Topic,
		Difficulty:      input.Difficulty,
		QuestionLimit:   len(selected.Packages),
		UsedQuestionIDs: usedQuestionIDs,
		CreatedAt:       now,
		UpdatedAt:       now,
	}

	served, err := s.nextQuestion(ctx, run)
	if err != nil {
		return CreateRunOutput{}, err
	}
	run.CurrentQuestion = &served

	if err := s.runs.Create(ctx, run); err != nil {
		return CreateRunOutput{}, runStorageError(err)
	}

	return CreateRunOutput{
		RunID:    run.ID,
		Theme:    run.Theme,
		Locale:   run.Locale,
		Question: publicQuestion(served),
	}, nil
}

func (s *RunService) SessionDifficulties(ctx context.Context, input SessionDifficultiesInput) (domain.SessionDifficulties, error) {
	if input.SessionID == "" || input.Topic == "" {
		return domain.SessionDifficulties{}, ErrInvalidInput
	}
	catalog, err := s.sessionCatalog(ctx, input.SessionID, input.Theme, input.Locale)
	if err != nil {
		return domain.SessionDifficulties{}, err
	}
	difficulties := make([]domain.SessionDifficultyAvailability, 0)
	for _, topic := range catalog.Metadata.Topics {
		if topic.ID == input.Topic {
			difficulties = catalogDifficulties(topic, catalog.AvailableCounts[topic.ID])
			break
		}
	}

	return domain.SessionDifficulties{
		Theme:        input.Theme,
		Locale:       catalog.Metadata.Locale,
		Topic:        input.Topic,
		Difficulties: difficulties,
	}, nil
}

func (s *RunService) SessionTopics(ctx context.Context, input SessionTopicsInput) (domain.SessionTopics, error) {
	if input.SessionID == "" {
		return domain.SessionTopics{}, ErrInvalidInput
	}
	catalog, err := s.sessionCatalog(ctx, input.SessionID, input.Theme, input.Locale)
	if err != nil {
		return domain.SessionTopics{}, err
	}
	metadata := catalog.Metadata

	topics := make([]domain.SessionTopicAvailability, 0, len(metadata.Topics))
	for _, topic := range metadata.Topics {
		difficulties := catalogDifficulties(topic, catalog.AvailableCounts[topic.ID])

		questionCount := 0
		availableQuestionCount := 0
		for _, difficulty := range difficulties {
			questionCount += difficulty.QuestionCount
			availableQuestionCount += difficulty.AvailableQuestionCount
		}

		topics = append(topics, domain.SessionTopicAvailability{
			ID:                     topic.ID,
			Label:                  topic.Label,
			Description:            topic.Description,
			Weight:                 topic.Weight,
			CreatedAt:              topic.CreatedAt,
			QuestionCount:          questionCount,
			AvailableQuestionCount: availableQuestionCount,
			Available:              availableQuestionCount > 0,
			Difficulties:           difficulties,
		})
	}

	return domain.SessionTopics{
		Theme:          metadata.Theme,
		Locale:         metadata.Locale,
		FallbackLocale: metadata.FallbackLocale,
		Topics:         topics,
	}, nil
}

func (s *RunService) ResetSession(ctx context.Context, sessionID, theme string) error {
	if sessionID == "" {
		return ErrInvalidInput
	}
	if theme == "" {
		return ErrThemeRequired
	}
	if err := s.runs.DeleteBySession(ctx, sessionID, theme); err != nil {
		return runStorageError(err)
	}
	return nil
}

func (s *RunService) RunState(ctx context.Context, theme, runID string) (domain.RunState, error) {
	if runID == "" {
		return domain.RunState{}, ErrInvalidInput
	}
	if theme == "" {
		return domain.RunState{}, ErrThemeRequired
	}

	run, err := s.runs.Get(ctx, runID)
	if err != nil {
		return domain.RunState{}, runStorageError(err)
	}
	if run.Theme != theme {
		return domain.RunState{}, ErrRunNotFound
	}

	state := domain.RunState{
		RunID:      run.ID,
		Theme:      run.Theme,
		Locale:     run.Locale,
		Topic:      run.Topic,
		Difficulty: run.Difficulty,
		Status:     domain.RunStatusActive,
		Finished:   run.Finished,
		Answered:   len(run.Answers),
		Total:      run.QuestionLimit,
	}
	if run.Finished {
		state.Status = domain.RunStatusFinished
		state.FinishReason = run.FinishReason
		return state, nil
	}
	if run.CurrentQuestion != nil {
		question := publicQuestion(*run.CurrentQuestion)
		state.Question = &question
	}
	return state, nil
}

func (s *RunService) ActiveSessions(ctx context.Context) (domain.ActiveSessions, error) {
	runs, err := s.runs.ListTracked(ctx)
	if err != nil {
		return domain.ActiveSessions{}, runStorageError(err)
	}

	sort.SliceStable(runs, func(i, j int) bool {
		return runs[i].UpdatedAt.After(runs[j].UpdatedAt)
	})

	now := time.Now().UTC()
	sessions := make([]domain.ActiveSession, 0, len(runs))
	for _, run := range runs {
		session := domain.ActiveSession{
			RunID:       run.ID,
			SessionID:   run.SessionID,
			Theme:       run.Theme,
			Locale:      run.Locale,
			Topic:       run.Topic,
			Difficulty:  run.Difficulty,
			Status:      domain.RunStatusActive,
			Finished:    run.Finished,
			Answered:    len(run.Answers),
			Total:       run.QuestionLimit,
			CreatedAt:   run.CreatedAt,
			UpdatedAt:   run.UpdatedAt,
			IdleSeconds: int64(now.Sub(run.UpdatedAt).Seconds()),
		}
		if run.Finished {
			session.Status = domain.RunStatusFinished
			session.FinishReason = run.FinishReason
		}
		if session.IdleSeconds < 0 {
			session.IdleSeconds = 0
		}
		if run.CurrentQuestion != nil {
			session.CurrentQuestionID = run.CurrentQuestion.QuestionID
			session.CurrentQuestionPosition = run.CurrentQuestion.Position
		}
		sessions = append(sessions, session)
	}

	return domain.ActiveSessions{
		GeneratedAt: now,
		Total:       len(sessions),
		Sessions:    sessions,
	}, nil
}

func (s *RunService) Answer(ctx context.Context, theme, runID, questionID, optionID string) (AnswerOutput, error) {
	if runID == "" || questionID == "" || optionID == "" {
		return AnswerOutput{}, ErrInvalidInput
	}
	if theme == "" {
		return AnswerOutput{}, ErrThemeRequired
	}

	run, err := s.runs.Get(ctx, runID)
	if err != nil {
		return AnswerOutput{}, runStorageError(err)
	}
	if run.Theme != theme {
		return AnswerOutput{}, ErrRunNotFound
	}
	if run.Finished {
		return AnswerOutput{}, ErrRunFinished
	}
	if run.CurrentQuestion == nil || run.CurrentQuestion.QuestionID != questionID {
		return AnswerOutput{}, ErrQuestionMismatch
	}
	if !optionExists(run.CurrentQuestion.Options, optionID) {
		return AnswerOutput{}, ErrOptionNotFound
	}

	correct := optionID == run.CurrentQuestion.CorrectOptionID
	run.Answers = append(run.Answers, domain.AnswerRecord{
		QuestionID: questionID,
		Prompt:     run.CurrentQuestion.Prompt,
		Correct:    correct,
	})

	if !correct && run.Difficulty == domain.DifficultyHardcore {
		s.finish(run, domain.FinishReasonHardcoreWrongAnswer)
		// Fatal hardcore loss is a server-side game rule; deleting the theme session here
		// prevents clients from preserving progress after a wrong answer.
		if err := s.runs.DeleteBySession(ctx, run.SessionID, run.Theme); err != nil {
			return AnswerOutput{}, runStorageError(err)
		}
		return AnswerOutput{Correct: false, Finished: true, FinishReason: run.FinishReason}, nil
	}

	if len(run.Answers) >= run.QuestionLimit {
		s.finish(run, domain.FinishReasonMaxQuestionsReached)
		return AnswerOutput{Correct: correct, Finished: true, FinishReason: run.FinishReason}, saveRun(ctx, s.runs, run)
	}

	next, err := s.nextQuestion(ctx, run)
	if err != nil {
		if errors.Is(err, ErrNoQuestionsLeft) {
			s.finish(run, domain.FinishReasonNoQuestionsLeft)
			return AnswerOutput{Correct: correct, Finished: true, FinishReason: run.FinishReason}, saveRun(ctx, s.runs, run)
		}
		return AnswerOutput{}, err
	}

	run.CurrentQuestion = &next
	run.UpdatedAt = time.Now().UTC()

	if err := saveRun(ctx, s.runs, run); err != nil {
		return AnswerOutput{}, err
	}

	nextPublic := publicQuestion(next)
	return AnswerOutput{
		Correct:  correct,
		Finished: false,
		Question: &nextPublic,
	}, nil
}

func (s *RunService) Finish(ctx context.Context, theme, runID string) error {
	if runID == "" {
		return ErrInvalidInput
	}
	if theme == "" {
		return ErrThemeRequired
	}

	run, err := s.runs.Get(ctx, runID)
	if err != nil {
		return runStorageError(err)
	}
	if run.Theme != theme {
		return ErrRunNotFound
	}
	if !run.Finished {
		s.finish(run, domain.FinishReasonPlayerQuit)
	}
	return saveRun(ctx, s.runs, run)
}

func (s *RunService) Result(ctx context.Context, theme, runID string) (domain.Result, error) {
	if runID == "" {
		return domain.Result{}, ErrInvalidInput
	}
	if theme == "" {
		return domain.Result{}, ErrThemeRequired
	}

	run, err := s.runs.Get(ctx, runID)
	if err != nil {
		return domain.Result{}, runStorageError(err)
	}
	if run.Theme != theme {
		return domain.Result{}, ErrRunNotFound
	}

	stats := domain.ResultStats{Answered: len(run.Answers)}
	for _, answer := range run.Answers {
		if answer.Correct {
			stats.Correct++
		} else {
			stats.Wrong++
		}
	}
	if stats.Answered > 0 {
		stats.AccuracyPercent = int(float64(stats.Correct) / float64(stats.Answered) * 100)
	}

	return domain.Result{
		RunID:        run.ID,
		Theme:        run.Theme,
		Locale:       run.Locale,
		Topic:        run.Topic,
		Difficulty:   run.Difficulty,
		FinishReason: run.FinishReason,
		Stats:        stats,
		Answers:      append([]domain.AnswerRecord(nil), run.Answers...),
	}, nil
}

func (s *RunService) nextQuestion(ctx context.Context, run *domain.Run) (domain.ServedQuestion, error) {
	if run.Version >= 2 {
		index := len(run.Answers)
		if index >= len(run.QuestionSnapshots) {
			return domain.ServedQuestion{}, ErrNoQuestionsLeft
		}
		snapshot := run.QuestionSnapshots[index]
		question, ok := snapshot.Translations[run.Locale]
		if !ok || question.Validate() != nil {
			return domain.ServedQuestion{}, fmt.Errorf("%w: invalid run snapshot", domain.ErrStorageUnavailable)
		}
		run.SnapshotIndex = index
		return s.serveQuestion(run, question), nil
	}
	questions, err := s.questions.ListByThemeLocaleTopicAndDifficulty(ctx, run.Theme, run.Locale, run.Topic, run.Difficulty)
	if err != nil {
		return domain.ServedQuestion{}, err
	}

	return s.nextQuestionFromList(run, questions)
}

func (s *RunService) questionsForDifficulty(ctx context.Context, theme, locale, topic string, difficulty domain.Difficulty) (string, []domain.Question, error) {
	questions, err := s.questions.ListByThemeLocaleTopicAndDifficulty(ctx, theme, locale, topic, difficulty)
	if err != nil {
		return locale, nil, err
	}
	if len(questions) > 0 || locale == s.locales.Fallback() {
		return locale, questions, nil
	}

	fallbackLocale := s.locales.Fallback()
	questions, err = s.questions.ListByThemeLocaleTopicAndDifficulty(ctx, theme, fallbackLocale, topic, difficulty)
	if err != nil {
		return fallbackLocale, nil, err
	}
	return fallbackLocale, questions, nil
}

func (s *RunService) sessionDifficultyAvailability(ctx context.Context, sessionID, theme, locale, topic string) (string, []domain.SessionDifficultyAvailability, error) {
	effectiveLocale := locale
	difficulties := make([]domain.SessionDifficultyAvailability, 0, 4)

	for _, difficulty := range []domain.Difficulty{
		domain.DifficultyEasy,
		domain.DifficultyNormal,
		domain.DifficultyHard,
		domain.DifficultyHardcore,
	} {
		questionLocale, questions, err := s.questionsForDifficulty(ctx, theme, locale, topic, difficulty)
		if err != nil {
			return effectiveLocale, nil, err
		}
		if len(questions) == 0 {
			continue
		}
		effectiveLocale = questionLocale

		usedQuestionIDs, err := s.runs.UsedQuestionIDs(ctx, sessionID, theme, topic, difficulty)
		if err != nil {
			return effectiveLocale, nil, err
		}
		availableCount := countAvailableQuestions(questions, usedQuestionIDs)

		difficulties = append(difficulties, domain.SessionDifficultyAvailability{
			ID:                     difficulty,
			OptionCount:            difficulty.OptionCount(),
			QuestionCount:          len(questions),
			AvailableQuestionCount: availableCount,
			Available:              availableCount > 0,
			Hardcore:               difficulty == domain.DifficultyHardcore,
		})
	}

	return effectiveLocale, difficulties, nil
}

func (s *RunService) nextQuestionFromList(run *domain.Run, questions []domain.Question) (domain.ServedQuestion, error) {
	available := make([]domain.Question, 0, len(questions))
	for _, question := range questions {
		if !run.UsedQuestionIDs[question.ID] {
			available = append(available, question)
		}
	}
	if len(available) == 0 {
		return domain.ServedQuestion{}, ErrNoQuestionsLeft
	}

	question := available[randomInt(len(available))]
	return s.serveQuestion(run, question), nil
}

func (s *RunService) serveQuestion(run *domain.Run, question domain.Question) domain.ServedQuestion {
	if run.UsedQuestionIDs == nil {
		run.UsedQuestionIDs = make(map[string]bool)
	}
	run.UsedQuestionIDs[question.ID] = true

	correctText := question.CorrectOptions[randomInt(len(question.CorrectOptions))]
	wrongCount := run.Difficulty.OptionCount() - 1
	wrongTexts := sampleStrings(question.WrongOptions, wrongCount)

	options := make([]domain.Option, 0, wrongCount+1)
	options = append(options, domain.Option{ID: "opt_" + randomID(8), Text: correctText})
	correctOptionID := options[0].ID
	for _, text := range wrongTexts {
		options = append(options, domain.Option{ID: "opt_" + randomID(8), Text: text})
	}
	shuffleOptions(options)

	return domain.ServedQuestion{
		QuestionID:      question.ID,
		Prompt:          question.Prompt,
		Options:         options,
		CorrectOptionID: correctOptionID,
		Position:        len(run.Answers) + 1,
		Total:           run.QuestionLimit,
	}
}

func runStorageError(err error) error {
	if errors.Is(err, domain.ErrRunNotFound) || errors.Is(err, domain.ErrStorageUnavailable) {
		return err
	}
	return fmt.Errorf("%w: run storage operation failed", domain.ErrStorageUnavailable)
}

func saveRun(ctx context.Context, repository RunRepository, run *domain.Run) error {
	if err := repository.Save(ctx, run); err != nil {
		return runStorageError(err)
	}
	return nil
}

func minInt(a, b int) int {
	if a < b {
		return a
	}
	return b
}

func countAvailableQuestions(questions []domain.Question, usedQuestionIDs map[string]bool) int {
	count := 0
	for _, question := range questions {
		if !usedQuestionIDs[question.ID] {
			count++
		}
	}
	return count
}

func (s *RunService) finish(run *domain.Run, reason domain.FinishReason) {
	run.Finished = true
	run.FinishReason = reason
	run.CurrentQuestion = nil
	run.UpdatedAt = time.Now().UTC()
}

func publicQuestion(question domain.ServedQuestion) domain.PublicQuestion {
	return domain.PublicQuestion{
		ID:      question.QuestionID,
		Prompt:  question.Prompt,
		Options: append([]domain.Option(nil), question.Options...),
		Current: question.Position,
		Total:   question.Total,
	}
}

func optionExists(options []domain.Option, id string) bool {
	for _, option := range options {
		if option.ID == id {
			return true
		}
	}
	return false
}

func sampleStrings(values []string, count int) []string {
	pool := append([]string(nil), values...)
	for i := len(pool) - 1; i > 0; i-- {
		j := randomInt(i + 1)
		pool[i], pool[j] = pool[j], pool[i]
	}
	return pool[:count]
}

func shuffleOptions(options []domain.Option) {
	for i := len(options) - 1; i > 0; i-- {
		j := randomInt(i + 1)
		options[i], options[j] = options[j], options[i]
	}
}

func randomID(bytesLen int) string {
	bytes := make([]byte, bytesLen)
	if _, err := rand.Read(bytes); err != nil {
		panic(err)
	}
	return hex.EncodeToString(bytes)
}

func randomInt(max int) int {
	if max <= 0 {
		return 0
	}
	n, err := rand.Int(rand.Reader, big.NewInt(int64(max)))
	if err != nil {
		panic(err)
	}
	return int(n.Int64())
}
