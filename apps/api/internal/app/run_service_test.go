package app

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/i18n"
	"quickquiz/api/internal/store"
)

func TestCreateRunReturnsQuestionWithoutCorrectAnswerLeak(t *testing.T) {
	service := NewRunService(
		store.NewMemoryQuestionStore(testQuestions()),
		store.NewMemoryRunStore(time.Hour),
		10,
		testLocaleManager(),
	)

	output, err := service.CreateRun(context.Background(), CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Topic:      "go",
		Difficulty: domain.DifficultyEasy,
	})
	if err != nil {
		t.Fatalf("CreateRun() error = %v", err)
	}

	if output.RunID == "" {
		t.Fatal("expected run id")
	}
	if output.Question.ID == "" {
		t.Fatal("expected question id")
	}
	if got := len(output.Question.Options); got != 3 {
		t.Fatalf("expected 3 options for easy difficulty, got %d", got)
	}
	if output.Question.Total != 1 {
		t.Fatalf("expected total to match available easy questions, got %d", output.Question.Total)
	}
}

func TestRunStateReturnsActiveQuestionAndFinishedSummary(t *testing.T) {
	service := NewRunService(
		store.NewMemoryQuestionStore(testQuestions()),
		store.NewMemoryRunStore(time.Hour),
		1,
		testLocaleManager(),
	)
	ctx := context.Background()

	run, err := service.CreateRun(ctx, CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Topic:      "go",
		Difficulty: domain.DifficultyEasy,
	})
	if err != nil {
		t.Fatalf("CreateRun() error = %v", err)
	}

	state, err := service.RunState(ctx, "dev", run.RunID)
	if err != nil {
		t.Fatalf("RunState() active error = %v", err)
	}
	if state.Status != domain.RunStatusActive || state.Finished {
		t.Fatalf("expected active run state, got %+v", state)
	}
	if state.Question == nil || state.Question.ID != run.Question.ID {
		t.Fatalf("expected current public question in active state, got %+v", state.Question)
	}
	if state.Answered != 0 || state.Total != 1 {
		t.Fatalf("expected active progress 0/1, got answered=%d total=%d", state.Answered, state.Total)
	}

	correctOptionID := ""
	for _, option := range run.Question.Options {
		if option.Text == "correct answer" {
			correctOptionID = option.ID
			break
		}
	}
	if correctOptionID == "" {
		t.Fatal("expected correct option in created run")
	}
	if _, err := service.Answer(ctx, "dev", run.RunID, run.Question.ID, correctOptionID); err != nil {
		t.Fatalf("Answer() error = %v", err)
	}

	state, err = service.RunState(ctx, "dev", run.RunID)
	if err != nil {
		t.Fatalf("RunState() finished error = %v", err)
	}
	if state.Status != domain.RunStatusFinished || !state.Finished {
		t.Fatalf("expected finished run state, got %+v", state)
	}
	if state.Question != nil {
		t.Fatalf("expected finished state without current question, got %+v", state.Question)
	}
	if state.FinishReason != domain.FinishReasonMaxQuestionsReached {
		t.Fatalf("expected max-questions finish reason, got %q", state.FinishReason)
	}
	if state.Answered != 1 || state.Total != 1 {
		t.Fatalf("expected finished progress 1/1, got answered=%d total=%d", state.Answered, state.Total)
	}
}

func TestCreateRunCapsTotalAtConfiguredQuestionLimit(t *testing.T) {
	service := NewRunService(
		store.NewMemoryQuestionStore([]domain.Question{
			testQuestion("go-easy-001", domain.DifficultyEasy),
			testQuestion("go-easy-002", domain.DifficultyEasy),
			testQuestion("go-easy-003", domain.DifficultyEasy),
		}),
		store.NewMemoryRunStore(time.Hour),
		2,
		testLocaleManager(),
	)

	output, err := service.CreateRun(context.Background(), CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Topic:      "go",
		Difficulty: domain.DifficultyEasy,
	})
	if err != nil {
		t.Fatalf("CreateRun() error = %v", err)
	}

	if output.Question.Total != 2 {
		t.Fatalf("expected total to be capped at configured limit, got %d", output.Question.Total)
	}
}

func TestCreateRunSkipsQuestionsAlreadyUsedInSession(t *testing.T) {
	service := NewRunService(
		store.NewMemoryQuestionStore([]domain.Question{
			testQuestion("go-easy-001", domain.DifficultyEasy),
			testQuestion("go-easy-002", domain.DifficultyEasy),
		}),
		store.NewMemoryRunStore(time.Hour),
		1,
		testLocaleManager(),
	)

	first, err := service.CreateRun(context.Background(), CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Topic:      "go",
		Difficulty: domain.DifficultyEasy,
	})
	if err != nil {
		t.Fatalf("first CreateRun() error = %v", err)
	}

	second, err := service.CreateRun(context.Background(), CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Topic:      "go",
		Difficulty: domain.DifficultyEasy,
	})
	if err != nil {
		t.Fatalf("second CreateRun() error = %v", err)
	}

	if second.Question.ID == first.Question.ID {
		t.Fatalf("expected second run to skip previously used question %q", first.Question.ID)
	}
	if second.Question.Total != 1 {
		t.Fatalf("expected second run total to match remaining questions, got %d", second.Question.Total)
	}
}

func TestCreateRunReturnsNoQuestionsLeftWhenSessionUsedAllQuestions(t *testing.T) {
	service := NewRunService(
		store.NewMemoryQuestionStore([]domain.Question{
			testQuestion("go-easy-001", domain.DifficultyEasy),
		}),
		store.NewMemoryRunStore(time.Hour),
		1,
		testLocaleManager(),
	)

	_, err := service.CreateRun(context.Background(), CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Topic:      "go",
		Difficulty: domain.DifficultyEasy,
	})
	if err != nil {
		t.Fatalf("first CreateRun() error = %v", err)
	}

	_, err = service.CreateRun(context.Background(), CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Topic:      "go",
		Difficulty: domain.DifficultyEasy,
	})
	if !errors.Is(err, ErrNoQuestionsLeft) {
		t.Fatalf("expected ErrNoQuestionsLeft, got %v", err)
	}
}

func TestCreateRunExhaustsQuestionPackageAcrossLocales(t *testing.T) {
	service := NewRunService(
		store.NewMemoryQuestionStore([]domain.Question{
			testLocalizedQuestion("go-easy-001", "en-US", "go", domain.DifficultyEasy),
			testLocalizedQuestion("go-easy-001", "pt-BR", "go", domain.DifficultyEasy),
		}),
		store.NewMemoryRunStore(time.Hour),
		1,
		testLocaleManager(),
	)

	_, err := service.CreateRun(context.Background(), CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Locale:     "pt-BR",
		Topic:      "go",
		Difficulty: domain.DifficultyEasy,
	})
	if err != nil {
		t.Fatalf("pt-BR CreateRun() error = %v", err)
	}

	_, err = service.CreateRun(context.Background(), CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Locale:     "en-US",
		Topic:      "go",
		Difficulty: domain.DifficultyEasy,
	})
	if !errors.Is(err, ErrNoQuestionsLeft) {
		t.Fatalf("expected ErrNoQuestionsLeft across locales, got %v", err)
	}
}

func TestCreateRunExhaustionIsScopedByTheme(t *testing.T) {
	service := NewRunService(
		store.NewMemoryQuestionStoreWithThemeMetadata(
			[]domain.Question{
				testThemedQuestion("shared-001", "dev", "en-US", "basics", domain.DifficultyEasy),
				testThemedQuestion("shared-001", "math", "en-US", "basics", domain.DifficultyEasy),
			},
			[]domain.TopicOption{
				{Theme: "dev", Locale: "en-US", ID: "basics", Label: "Dev Basics"},
				{Theme: "math", Locale: "en-US", ID: "basics", Label: "Math Basics"},
			},
			[]domain.Theme{
				{ID: "dev", Name: "Development", Active: true},
				{ID: "math", Name: "Math", Active: true},
			},
		),
		store.NewMemoryRunStore(time.Hour),
		1,
		testLocaleManager(),
	)

	_, err := service.CreateRun(context.Background(), CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Topic:      "basics",
		Difficulty: domain.DifficultyEasy,
	})
	if err != nil {
		t.Fatalf("dev CreateRun() error = %v", err)
	}

	_, err = service.CreateRun(context.Background(), CreateRunInput{
		SessionID:  "session_test",
		Theme:      "math",
		Topic:      "basics",
		Difficulty: domain.DifficultyEasy,
	})
	if err != nil {
		t.Fatalf("math CreateRun() should not be exhausted by dev run: %v", err)
	}
}

func TestSessionDifficultiesReturnsAvailabilityBySessionAndTopic(t *testing.T) {
	service := NewRunService(
		store.NewMemoryQuestionStore([]domain.Question{
			testQuestion("go-easy-001", domain.DifficultyEasy),
			testQuestion("go-easy-002", domain.DifficultyEasy),
			testQuestion("go-normal-001", domain.DifficultyNormal),
			{
				ID:             "php-easy-001",
				Theme:          "dev",
				Locale:         "en-US",
				Topic:          "php",
				Difficulty:     domain.DifficultyEasy,
				Prompt:         "Fake question",
				CorrectOptions: []string{"correct answer"},
				WrongOptions:   []string{"wrong answer 01", "wrong answer 02", "wrong answer 03"},
			},
		}),
		store.NewMemoryRunStore(time.Hour),
		1,
		testLocaleManager(),
	)

	_, err := service.CreateRun(context.Background(), CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Topic:      "go",
		Difficulty: domain.DifficultyEasy,
	})
	if err != nil {
		t.Fatalf("easy CreateRun() error = %v", err)
	}

	_, err = service.CreateRun(context.Background(), CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Topic:      "go",
		Difficulty: domain.DifficultyNormal,
	})
	if err != nil {
		t.Fatalf("normal CreateRun() error = %v", err)
	}

	output, err := service.SessionDifficulties(context.Background(), SessionDifficultiesInput{
		SessionID: "session_test",
		Theme:     "dev",
		Topic:     "go",
	})
	if err != nil {
		t.Fatalf("SessionDifficulties() error = %v", err)
	}

	availabilityByDifficulty := make(map[domain.Difficulty]domain.SessionDifficultyAvailability)
	for _, difficulty := range output.Difficulties {
		availabilityByDifficulty[difficulty.ID] = difficulty
	}

	easy := availabilityByDifficulty[domain.DifficultyEasy]
	if easy.QuestionCount != 2 || easy.AvailableQuestionCount != 1 || !easy.Available {
		t.Fatalf("expected easy to have 1 of 2 available, got %+v", easy)
	}

	normal := availabilityByDifficulty[domain.DifficultyNormal]
	if normal.QuestionCount != 1 || normal.AvailableQuestionCount != 0 || normal.Available {
		t.Fatalf("expected normal to be exhausted, got %+v", normal)
	}

	if _, ok := availabilityByDifficulty[domain.DifficultyHard]; ok {
		t.Fatal("did not expect hard difficulty without questions")
	}
}

func TestSessionTopicsReturnsAvailabilityBySession(t *testing.T) {
	service := NewRunService(
		store.NewMemoryQuestionStoreWithMetadata(
			[]domain.Question{
				testQuestion("go-easy-001", domain.DifficultyEasy),
				testLocalizedQuestion("php-easy-001", "en-US", "php", domain.DifficultyEasy),
			},
			[]domain.TopicOption{
				{Theme: "dev", Locale: "en-US", ID: "go", Label: "Go", Weight: 200},
				{Theme: "dev", Locale: "en-US", ID: "php", Label: "PHP", Weight: 100},
			},
		),
		store.NewMemoryRunStore(time.Hour),
		1,
		testLocaleManager(),
	)

	_, err := service.CreateRun(context.Background(), CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Topic:      "go",
		Difficulty: domain.DifficultyEasy,
	})
	if err != nil {
		t.Fatalf("CreateRun() error = %v", err)
	}

	output, err := service.SessionTopics(context.Background(), SessionTopicsInput{
		SessionID: "session_test",
		Theme:     "dev",
	})
	if err != nil {
		t.Fatalf("SessionTopics() error = %v", err)
	}

	availabilityByTopic := make(map[string]domain.SessionTopicAvailability)
	for _, topic := range output.Topics {
		availabilityByTopic[topic.ID] = topic
	}

	goTopic := availabilityByTopic["go"]
	if goTopic.QuestionCount != 1 || goTopic.AvailableQuestionCount != 0 || goTopic.Available {
		t.Fatalf("expected Go to be exhausted, got %+v", goTopic)
	}

	phpTopic := availabilityByTopic["php"]
	if phpTopic.QuestionCount != 1 || phpTopic.AvailableQuestionCount != 1 || !phpTopic.Available {
		t.Fatalf("expected PHP to remain available, got %+v", phpTopic)
	}
}

func TestHardcoreWrongAnswerResetsThemeSession(t *testing.T) {
	service := NewRunService(
		store.NewMemoryQuestionStoreWithThemeMetadata(
			[]domain.Question{
				testQuestion("go-easy-001", domain.DifficultyEasy),
				testQuestion("go-easy-002", domain.DifficultyEasy),
				testQuestion("go-hardcore-001", domain.DifficultyHardcore),
				testThemedQuestion("math-easy-001", "math", "en-US", "basics", domain.DifficultyEasy),
			},
			[]domain.TopicOption{
				{Theme: "dev", Locale: "en-US", ID: "go", Label: "Go"},
				{Theme: "math", Locale: "en-US", ID: "basics", Label: "Math Basics"},
			},
			[]domain.Theme{
				{ID: "dev", Name: "Development", Active: true},
				{ID: "math", Name: "Math", Active: true},
			},
		),
		store.NewMemoryRunStore(time.Hour),
		10,
		testLocaleManager(),
	)
	ctx := context.Background()

	_, err := service.CreateRun(ctx, CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Topic:      "go",
		Difficulty: domain.DifficultyEasy,
	})
	if err != nil {
		t.Fatalf("dev easy CreateRun() error = %v", err)
	}

	_, err = service.CreateRun(ctx, CreateRunInput{
		SessionID:  "session_test",
		Theme:      "math",
		Topic:      "basics",
		Difficulty: domain.DifficultyEasy,
	})
	if err != nil {
		t.Fatalf("math easy CreateRun() error = %v", err)
	}

	run, err := service.CreateRun(ctx, CreateRunInput{
		SessionID:  "session_test",
		Theme:      "dev",
		Topic:      "go",
		Difficulty: domain.DifficultyHardcore,
	})
	if err != nil {
		t.Fatalf("CreateRun() error = %v", err)
	}

	optionID := ""
	for _, option := range run.Question.Options {
		if strings.Contains(option.Text, "wrong") {
			optionID = option.ID
			break
		}
	}
	if optionID == "" {
		t.Fatal("expected at least one wrong option")
	}

	answer, err := service.Answer(ctx, "dev", run.RunID, run.Question.ID, optionID)
	if err != nil {
		t.Fatalf("Answer() error = %v", err)
	}

	if answer.Correct {
		t.Fatal("expected wrong answer")
	}
	if !answer.Finished {
		t.Fatal("expected hardcore wrong answer to finish run")
	}
	if answer.FinishReason != domain.FinishReasonHardcoreWrongAnswer {
		t.Fatalf("expected hardcore finish reason, got %q", answer.FinishReason)
	}

	_, err = service.Result(ctx, "dev", run.RunID)
	if !errors.Is(err, ErrRunNotFound) {
		t.Fatalf("expected failed hardcore run result to be deleted, got %v", err)
	}

	devAvailability, err := service.SessionDifficulties(ctx, SessionDifficultiesInput{
		SessionID: "session_test",
		Theme:     "dev",
		Topic:     "go",
	})
	if err != nil {
		t.Fatalf("dev SessionDifficulties() error = %v", err)
	}
	for _, difficulty := range devAvailability.Difficulties {
		if difficulty.AvailableQuestionCount != difficulty.QuestionCount {
			t.Fatalf("expected dev difficulty availability reset, got %+v", difficulty)
		}
	}

	mathAvailability, err := service.SessionDifficulties(ctx, SessionDifficultiesInput{
		SessionID: "session_test",
		Theme:     "math",
		Topic:     "basics",
	})
	if err != nil {
		t.Fatalf("math SessionDifficulties() error = %v", err)
	}
	if got := mathAvailability.Difficulties[0].AvailableQuestionCount; got != 0 {
		t.Fatalf("expected math progress to remain exhausted, got %d available", got)
	}
}

func testQuestions() []domain.Question {
	return []domain.Question{
		testQuestion("go-easy-001", domain.DifficultyEasy),
		testQuestion("go-hardcore-001", domain.DifficultyHardcore),
	}
}

func testLocaleManager() i18n.Manager {
	return i18n.NewManager("en-US", []string{"en-US", "pt-BR"})
}

func testQuestion(id string, difficulty domain.Difficulty) domain.Question {
	return testLocalizedQuestion(id, "en-US", "go", difficulty)
}

func testLocalizedQuestion(id, locale, topic string, difficulty domain.Difficulty) domain.Question {
	return testThemedQuestion(id, "dev", locale, topic, difficulty)
}

func testThemedQuestion(id, theme, locale, topic string, difficulty domain.Difficulty) domain.Question {
	return domain.Question{
		ID:             id,
		Theme:          theme,
		Locale:         locale,
		Topic:          topic,
		Difficulty:     difficulty,
		Prompt:         "Fake question",
		CorrectOptions: []string{"correct answer"},
		WrongOptions: []string{
			"wrong answer 01",
			"wrong answer 02",
			"wrong answer 03",
			"wrong answer 04",
			"wrong answer 05",
			"wrong answer 06",
			"wrong answer 07",
			"wrong answer 08",
			"wrong answer 09",
			"wrong answer 10",
			"wrong answer 11",
			"wrong answer 12",
			"wrong answer 13",
			"wrong answer 14",
			"wrong answer 15",
			"wrong answer 16",
			"wrong answer 17",
			"wrong answer 18",
			"wrong answer 19",
			"wrong answer 20",
		},
	}
}
