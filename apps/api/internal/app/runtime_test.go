package app

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/i18n"
	"quickquiz/api/internal/store"
)

type intentReaderSpy struct {
	*store.MemoryQuestionStore
	catalogCalls, selectCalls, lookupCalls int
	consumption                            domain.QuestionConsumption
	unavailable                            bool
	retired                                bool
}

func (s *intentReaderSpy) ReadCatalog(ctx context.Context, input domain.CatalogReadInput) (domain.CatalogReadResult, error) {
	s.catalogCalls++
	s.consumption = input.Consumption
	if s.unavailable {
		return domain.CatalogReadResult{}, domain.ErrStorageUnavailable
	}
	if s.retired {
		return domain.CatalogReadResult{}, domain.ErrThemeInactive
	}
	return s.MemoryQuestionStore.ReadCatalog(ctx, input)
}

func (s *intentReaderSpy) SelectRunPackages(ctx context.Context, input domain.RunPackageSelection) (domain.SelectedRunPackages, error) {
	s.selectCalls++
	if s.unavailable {
		return domain.SelectedRunPackages{}, domain.ErrStorageUnavailable
	}
	if s.retired {
		return domain.SelectedRunPackages{}, domain.ErrThemeInactive
	}
	return s.MemoryQuestionStore.SelectRunPackages(ctx, input)
}

func (s *intentReaderSpy) LookupQuestion(context.Context, domain.QuestionLookup) (domain.Question, error) {
	s.lookupCalls++
	return domain.Question{}, domain.ErrStorageUnavailable
}

func (s *intentReaderSpy) Theme(context.Context, string) (domain.Theme, error) {
	panic("legacy live theme lookup")
}
func (s *intentReaderSpy) ListMetadata(context.Context, string, string, string) (domain.CatalogMetadata, error) {
	panic("legacy metadata lookup")
}
func (s *intentReaderSpy) ListByThemeLocaleTopicAndDifficulty(context.Context, string, string, string, domain.Difficulty) ([]domain.Question, error) {
	panic("whole-list question lookup")
}

func runtimeQuestions(count int) []domain.Question {
	questions := make([]domain.Question, 0, count*2)
	for i := 0; i < count; i++ {
		for _, locale := range []string{"en-US", "pt-BR"} {
			questions = append(questions, testLocalizedQuestion(fmt.Sprintf("go-%03d", i), locale, "go", domain.DifficultyEasy))
		}
	}
	return questions
}

func TestRuntimeRunCapturesBoundedTranslationsAndOnlyConsumesPresented(t *testing.T) {
	ctx := context.Background()
	reader := &intentReaderSpy{MemoryQuestionStore: store.NewMemoryQuestionStore(runtimeQuestions(10))}
	runs := store.NewMemoryRunStore(time.Hour)
	service := NewRunService(reader, runs, 5, testLocaleManager())
	created, err := service.CreateRun(ctx, CreateRunInput{SessionID: "s", Theme: "dev", Topic: "go", Difficulty: 1, Locale: "pt-BR"})
	if err != nil {
		t.Fatal(err)
	}
	run, err := runs.Get(ctx, created.RunID)
	if err != nil {
		t.Fatal(err)
	}
	if run.Version != 2 || len(run.QuestionSnapshots) != 5 || run.QuestionLimit != 5 || len(run.UsedQuestionIDs) != 1 || reader.selectCalls != 1 {
		t.Fatalf("unexpected snapshot/consumption: version=%d snapshots=%d limit=%d used=%d", run.Version, len(run.QuestionSnapshots), run.QuestionLimit, len(run.UsedQuestionIDs))
	}
	if run.CatalogRevision != nil || run.CatalogDigest == "" {
		t.Fatal("legacy provenance needs digest, not an invented database revision")
	}
	for _, snapshot := range run.QuestionSnapshots {
		if len(snapshot.Translations) != 2 || snapshot.Digest != domain.SnapshotDigest(snapshot) {
			t.Fatal("missing translations or invalid content digest")
		}
	}
	public, _ := json.Marshal(created)
	if strings.Contains(string(public), "Snapshots") || strings.Contains(string(public), "correctOptions") || strings.Contains(string(public), "CorrectOptionID") {
		t.Fatal("private snapshot leaked")
	}
	if err := service.Finish(ctx, "dev", created.RunID); err != nil {
		t.Fatal(err)
	}
	availability, err := service.SessionDifficulties(ctx, SessionDifficultiesInput{SessionID: "s", Theme: "dev", Topic: "go", Locale: "en-US"})
	if err != nil {
		t.Fatal(err)
	}
	if reader.catalogCalls != 1 || len(reader.consumption["go"][1]) != 1 || availability.Difficulties[0].AvailableQuestionCount != 9 {
		t.Fatalf("queued questions were consumed or counts not batched: %+v", availability)
	}
}

func TestCapturedRunSurvivesCatalogOutageRetirementAndRestart(t *testing.T) {
	ctx := context.Background()
	reader := &intentReaderSpy{MemoryQuestionStore: store.NewMemoryQuestionStore(runtimeQuestions(4))}
	runs := store.NewMemoryRunStore(time.Hour)
	service := NewRunService(reader, runs, 3, testLocaleManager())
	created, err := service.CreateRun(ctx, CreateRunInput{SessionID: "s", Theme: "dev", Topic: "go", Difficulty: 1})
	if err != nil {
		t.Fatal(err)
	}
	reader.unavailable = true
	service = NewRunService(reader, runs, 3, testLocaleManager())
	state, err := service.RunState(ctx, "dev", created.RunID)
	if err != nil {
		t.Fatal(err)
	}
	if state.Question == nil || state.Question.Options[0].ID != created.Question.Options[0].ID {
		t.Fatal("served option identity changed on restart")
	}
	for !state.Finished {
		run, _ := runs.Get(ctx, created.RunID)
		if _, err := service.Answer(ctx, "dev", run.ID, run.CurrentQuestion.QuestionID, run.CurrentQuestion.CorrectOptionID); err != nil {
			t.Fatal(err)
		}
		state, err = service.RunState(ctx, "dev", created.RunID)
		if err != nil {
			t.Fatal(err)
		}
	}
	result, err := service.Result(ctx, "dev", created.RunID)
	if err != nil || result.Stats.Correct != 3 {
		t.Fatalf("snapshot grading failed: result=%+v err=%v", result, err)
	}
	if reader.selectCalls != 1 || reader.lookupCalls != 0 || reader.catalogCalls != 0 {
		t.Fatal("active run queried mutable content")
	}
	if _, err := service.SessionTopics(ctx, SessionTopicsInput{SessionID: "s", Theme: "dev"}); !errors.Is(err, domain.ErrStorageUnavailable) {
		t.Fatalf("outage became empty success: %v", err)
	}
	reader.unavailable = false
	reader.retired = true
	availability, err := service.SessionTopics(ctx, SessionTopicsInput{SessionID: "s", Theme: "dev"})
	if err != nil || len(availability.Topics) != 0 {
		t.Fatalf("retired session should close with empty availability: %+v %v", availability, err)
	}
	if _, err := service.CreateRun(ctx, CreateRunInput{SessionID: "new", Theme: "dev", Topic: "go", Difficulty: 1}); !errors.Is(err, ErrThemeInactive) {
		t.Fatalf("retired theme allowed new run: %v", err)
	}
	reader.unavailable = true
	if err := service.ResetSession(ctx, "s", "dev"); err != nil {
		t.Fatal(err)
	}
	if _, err := service.RunState(ctx, "dev", created.RunID); !errors.Is(err, ErrRunNotFound) {
		t.Fatalf("owned reset did not delete run: %v", err)
	}
}

type failedGetRunStore struct{ RunRepository }

func (s failedGetRunStore) Get(context.Context, string) (*domain.Run, error) {
	return nil, errors.New("dependency failed")
}

func TestRunStorageFailureNeverBecomesNotFound(t *testing.T) {
	service := NewRunService(nil, failedGetRunStore{}, 5, testLocaleManager())
	_, err := service.RunState(context.Background(), "dev", "run")
	if !errors.Is(err, domain.ErrStorageUnavailable) || errors.Is(err, ErrRunNotFound) {
		t.Fatalf("wrong error category: %v", err)
	}
}

type preparedRecordingGenerator struct {
	prompt string
	inputs []GenerateSolutionInput
}

func (g *preparedRecordingGenerator) PrepareSolution(context.Context, string) (SolutionGenerationContext, error) {
	return SolutionGenerationContext{Instructions: g.prompt, PromptDigest: hashText(g.prompt), GeneratorConfigDigest: hashText("fake-config"), Model: "fake"}, nil
}
func (g *preparedRecordingGenerator) GenerateSolution(_ context.Context, in GenerateSolutionInput) (string, error) {
	g.inputs = append(g.inputs, in)
	return "Explanation for " + in.CorrectOptions[0], nil
}

func TestSnapshotSolutionsKeepOldCorrectAnswerAndInvalidatePromptCache(t *testing.T) {
	ctx := context.Background()
	runs := store.NewMemoryRunStore(time.Hour)
	createSolutionTestRun(t, runs, "old", false)
	old, _ := runs.Get(ctx, "old")
	newRun := *old
	newRun.ID = "new"
	newSnapshot := solutionTestSnapshot()
	for locale, q := range newSnapshot.Translations {
		q.CorrectOptions = []string{"Replacement correct answer"}
		newSnapshot.Translations[locale] = q
	}
	newSnapshot.Digest = domain.SnapshotDigest(newSnapshot)
	newRun.QuestionSnapshots = []domain.QuestionSnapshot{newSnapshot}
	if err := runs.Create(ctx, &newRun); err != nil {
		t.Fatal(err)
	}
	generator := &preparedRecordingGenerator{prompt: "Prompt A"}
	service := NewSolutionService(nil, runs, store.NewMemorySolutionStore(nil), generator, i18n.NewManager("en-US", []string{"en-US"}))
	request := QuestionSolutionInput{Theme: "dev", Locale: "en-US", RunID: "old", QuestionID: "go-easy-001"}
	oldOutput, err := service.QuestionSolution(ctx, request)
	if err != nil {
		t.Fatal(err)
	}
	request.RunID = "new"
	newOutput, err := service.QuestionSolution(ctx, request)
	if err != nil {
		t.Fatal(err)
	}
	if oldOutput.Explanation == newOutput.Explanation || len(generator.inputs) != 2 || generator.inputs[0].CorrectOptions[0] != "Formats Go source code" || generator.inputs[1].CorrectOptions[0] != "Replacement correct answer" {
		t.Fatal("mutable answer or cross-version cache reuse")
	}
	request.RunID = "old"
	cached, err := service.QuestionSolution(ctx, request)
	if err != nil {
		t.Fatal(err)
	}
	if !cached.Cached || len(generator.inputs) != 2 {
		t.Fatal("old version cache was overwritten by new version")
	}
	generator.prompt = "Prompt B"
	refreshed, err := service.QuestionSolution(ctx, request)
	if err != nil {
		t.Fatal(err)
	}
	if refreshed.Cached || len(generator.inputs) != 3 || generator.inputs[2].EffectiveInstructions != "Prompt B" {
		t.Fatal("edited prompt reused stale generation")
	}
}

func TestLegacyAnsweredQuestionDoesNotInventHistoricalCorrectOptions(t *testing.T) {
	ctx := context.Background()
	runs := store.NewMemoryRunStore(time.Hour)
	createSolutionTestRun(t, runs, "legacy", false)
	run, _ := runs.Get(ctx, "legacy")
	run.Version = 0
	run.QuestionSnapshots = nil
	if err := runs.Save(ctx, run); err != nil {
		t.Fatal(err)
	}
	generator := &countingSolutionGenerator{}
	service := NewSolutionService(nil, runs, store.NewMemorySolutionStore(nil), generator, testLocaleManager())
	_, err := service.QuestionSolution(ctx, QuestionSolutionInput{Theme: "dev", Locale: "en-US", RunID: "legacy", QuestionID: "go-easy-001"})
	if !errors.Is(err, ErrSolutionUnavailable) || generator.Count() != 0 {
		t.Fatalf("unprovable legacy history was generated: %v", err)
	}
}

func TestNeutralSolutionDefaultMatchesManager(t *testing.T) {
	path := filepath.Join("..", "..", "..", "manager", "src", "Service", "AiPromptDefaults.php")
	source, err := os.ReadFile(path)
	if os.IsNotExist(err) {
		t.Skip("Manager sources absent in API-only checkout")
	}
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(source), DefaultSolutionPrompt) {
		t.Fatal("Manager and API solution defaults differ")
	}
}
