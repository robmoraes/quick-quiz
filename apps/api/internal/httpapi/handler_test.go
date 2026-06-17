package httpapi

import (
	"context"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"quickquiz/api/internal/app"
	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/i18n"
	"quickquiz/api/internal/store"
)

func TestAdsEndpointReturnsAdsForRequestedTheme(t *testing.T) {
	router := testRouter()

	request := httptest.NewRequest(http.MethodGet, "/api/ads?limit=3&emphasis=2", nil)
	request.Header.Set("X-QuickQuiz-Theme", "dev")
	response := httptest.NewRecorder()

	router.ServeHTTP(response, request)

	if response.Code != http.StatusOK {
		t.Fatalf("expected status 200, got %d: %s", response.Code, response.Body.String())
	}
	if !strings.Contains(response.Body.String(), `"theme":"dev"`) {
		t.Fatalf("expected dev theme response, got %s", response.Body.String())
	}
	if !strings.Contains(response.Body.String(), `"providerId":"provider-ad-a"`) {
		t.Fatalf("expected public ad payload, got %s", response.Body.String())
	}
	if !strings.Contains(response.Body.String(), `"emphasis"`) || !strings.Contains(response.Body.String(), `"providerId":"provider-ad-emphasis"`) {
		t.Fatalf("expected emphasis ad payload, got %s", response.Body.String())
	}
	if !strings.Contains(response.Body.String(), `"emphasis":[`) {
		t.Fatalf("expected emphasis list payload, got %s", response.Body.String())
	}
	if strings.Contains(response.Body.String(), "active") || strings.Contains(response.Body.String(), "themes") {
		t.Fatalf("expected control fields to be hidden, got %s", response.Body.String())
	}
}

func TestAdsEndpointRejectsInvalidLimit(t *testing.T) {
	router := testRouter()

	request := httptest.NewRequest(http.MethodGet, "/api/ads?limit=zero", nil)
	request.Header.Set("X-QuickQuiz-Theme", "dev")
	response := httptest.NewRecorder()

	router.ServeHTTP(response, request)

	if response.Code != http.StatusBadRequest {
		t.Fatalf("expected status 400, got %d", response.Code)
	}
	if !strings.Contains(response.Body.String(), `"code":"invalid_input"`) {
		t.Fatalf("expected invalid_input error, got %s", response.Body.String())
	}
}

func TestAdsEndpointPrioritizesRequestedTopic(t *testing.T) {
	router := testRouter()

	request := httptest.NewRequest(http.MethodGet, "/api/ads?limit=1&topic=php", nil)
	request.Header.Set("X-QuickQuiz-Theme", "dev")
	response := httptest.NewRecorder()

	router.ServeHTTP(response, request)

	if response.Code != http.StatusOK {
		t.Fatalf("expected status 200, got %d: %s", response.Code, response.Body.String())
	}
	if !strings.Contains(response.Body.String(), `"providerId":"provider-ad-topic"`) {
		t.Fatalf("expected topic ad payload, got %s", response.Body.String())
	}
	if strings.Contains(response.Body.String(), `"providerId":"provider-ad-a"`) {
		t.Fatalf("expected topic ad to be prioritized over fallback, got %s", response.Body.String())
	}
}

func TestAdsEndpointRejectsInvalidEmphasisLimit(t *testing.T) {
	router := testRouter()

	request := httptest.NewRequest(http.MethodGet, "/api/ads?limit=2&emphasis=true", nil)
	request.Header.Set("X-QuickQuiz-Theme", "dev")
	response := httptest.NewRecorder()

	router.ServeHTTP(response, request)

	if response.Code != http.StatusBadRequest {
		t.Fatalf("expected status 400, got %d", response.Code)
	}
	if !strings.Contains(response.Body.String(), `"code":"invalid_input"`) {
		t.Fatalf("expected invalid_input error, got %s", response.Body.String())
	}
}

func TestQuestionSolutionEndpointReturnsGeneratedSolution(t *testing.T) {
	router := testRouter()

	request := httptest.NewRequest(http.MethodGet, "/api/runs/run_solution_001/questions/go-easy-001/solution", nil)
	request.Header.Set("X-QuickQuiz-Theme", "dev")
	request.Header.Set("X-QuickQuiz-Locale", "en-US")
	response := httptest.NewRecorder()

	router.ServeHTTP(response, request)

	if response.Code != http.StatusOK {
		t.Fatalf("expected status 200, got %d: %s", response.Code, response.Body.String())
	}
	if !strings.Contains(response.Body.String(), `"questionId":"go-easy-001"`) {
		t.Fatalf("expected solution question id, got %s", response.Body.String())
	}
	if !strings.Contains(response.Body.String(), `"explanation":"Generated test solution for en-US"`) {
		t.Fatalf("expected generated explanation, got %s", response.Body.String())
	}
	if !strings.Contains(response.Body.String(), `"cached":false`) {
		t.Fatalf("expected first solution response to be uncached, got %s", response.Body.String())
	}
}

func testRouter() http.Handler {
	questionStore := store.NewMemoryQuestionStoreWithThemeMetadata(
		[]domain.Question{{
			ID:             "go-easy-001",
			Theme:          "dev",
			Locale:         "en-US",
			Topic:          "go",
			Difficulty:     domain.DifficultyEasy,
			Prompt:         "Fake question",
			CorrectOptions: []string{"correct answer"},
			WrongOptions:   []string{"wrong answer 01", "wrong answer 02"},
		}},
		nil,
		[]domain.Theme{{ID: "dev", Name: "Development", Active: true}},
	)
	runStore := store.NewMemoryRunStore(time.Hour)
	seedSolutionRun(runStore)
	runService := app.NewRunService(questionStore, runStore, 10, i18n.NewManager("en-US", []string{"en-US"}))
	adService := app.NewAdService(store.NewMemoryAdStore([]domain.Ad{
		{
			ID:          "ad-a",
			ProviderID:  "provider-ad-a",
			URI:         "https://example.com/ad-a",
			Description: "Ad A",
			Image:       "https://example.com/ad-a.webp",
			Active:      true,
			Themes:      []string{"dev"},
		},
		{
			ID:          "ad-emphasis",
			ProviderID:  "provider-ad-emphasis",
			URI:         "https://example.com/ad-emphasis",
			Description: "Ad Emphasis",
			Image:       "https://example.com/ad-emphasis.webp",
			Active:      true,
			Emphasis:    true,
			Themes:      []string{"dev"},
		},
		{
			ID:          "ad-topic",
			ProviderID:  "provider-ad-topic",
			URI:         "https://example.com/ad-topic",
			Description: "Ad Topic",
			Image:       "https://example.com/ad-topic.webp",
			Active:      true,
			Themes:      []string{"dev"},
			Topics:      []string{"php"},
		},
	}), questionStore)
	solutionService := app.NewSolutionService(
		questionStore,
		runStore,
		store.NewMemorySolutionStore(nil),
		&testSolutionGenerator{},
		i18n.NewManager("en-US", []string{"en-US"}),
	)

	return NewRouter(runService, adService, solutionService, slog.New(slog.NewTextHandler(io.Discard, nil)))
}

type testSolutionGenerator struct{}

func (g *testSolutionGenerator) GenerateSolution(_ context.Context, input app.GenerateSolutionInput) (string, error) {
	return "Generated test solution for " + input.Locale, nil
}

func (g *testSolutionGenerator) Model() string {
	return "test-model"
}

func seedSolutionRun(runStore *store.MemoryRunStore) {
	now := time.Now().UTC()
	_ = runStore.Create(context.Background(), &domain.Run{
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
		Answers: []domain.AnswerRecord{{
			QuestionID: "go-easy-001",
			Prompt:     "Fake question",
			Correct:    false,
		}},
		Finished:     true,
		FinishReason: domain.FinishReasonMaxQuestionsReached,
		CreatedAt:    now,
		UpdatedAt:    now,
	})
}
