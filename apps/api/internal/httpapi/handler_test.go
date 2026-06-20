package httpapi

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
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

func TestRunStateEndpointReturnsActiveRunState(t *testing.T) {
	router := testRouter()

	createRequest := httptest.NewRequest(http.MethodPost, "/api/runs", strings.NewReader(`{"topic":"go","difficulty":1}`))
	createRequest.Header.Set("Content-Type", "application/json")
	createRequest.Header.Set("X-QuickQuiz-Theme", "dev")
	createRequest.Header.Set("X-QuickQuiz-Session-ID", "session_state_001")
	createResponse := httptest.NewRecorder()

	router.ServeHTTP(createResponse, createRequest)

	if createResponse.Code != http.StatusCreated {
		t.Fatalf("expected create status 201, got %d: %s", createResponse.Code, createResponse.Body.String())
	}
	var createPayload struct {
		RunID string `json:"runId"`
	}
	if err := json.NewDecoder(createResponse.Body).Decode(&createPayload); err != nil {
		t.Fatalf("decode create response: %v", err)
	}
	if createPayload.RunID == "" {
		t.Fatal("expected created run id")
	}

	stateRequest := httptest.NewRequest(http.MethodGet, "/api/runs/"+createPayload.RunID+"/state", nil)
	stateRequest.Header.Set("X-QuickQuiz-Theme", "dev")
	stateResponse := httptest.NewRecorder()

	router.ServeHTTP(stateResponse, stateRequest)

	if stateResponse.Code != http.StatusOK {
		t.Fatalf("expected state status 200, got %d: %s", stateResponse.Code, stateResponse.Body.String())
	}
	body := stateResponse.Body.String()
	for _, expected := range []string{
		`"runId":"` + createPayload.RunID + `"`,
		`"status":"active"`,
		`"finished":false`,
		`"answered":0`,
		`"total":1`,
		`"question"`,
	} {
		if !strings.Contains(body, expected) {
			t.Fatalf("expected state response to contain %s, got %s", expected, body)
		}
	}
}

func TestRunStateEndpointReturnsRunNotFound(t *testing.T) {
	router := testRouter()

	request := httptest.NewRequest(http.MethodGet, "/api/runs/run_missing/state", nil)
	request.Header.Set("X-QuickQuiz-Theme", "dev")
	response := httptest.NewRecorder()

	router.ServeHTTP(response, request)

	if response.Code != http.StatusNotFound {
		t.Fatalf("expected status 404, got %d: %s", response.Code, response.Body.String())
	}
	if !strings.Contains(response.Body.String(), `"code":"run_not_found"`) {
		t.Fatalf("expected run_not_found error, got %s", response.Body.String())
	}
}

func TestHandlerLogsUnexpectedApplicationErrors(t *testing.T) {
	var logs bytes.Buffer
	handler := NewHandler(nil, nil, nil, slog.New(slog.NewJSONHandler(&logs, nil)))
	request := httptest.NewRequest(http.MethodGet, "/api/test", nil)
	response := httptest.NewRecorder()

	handler.writeAppError(response, request, errors.New("storage is read-only"))

	if response.Code != http.StatusInternalServerError {
		t.Fatalf("expected status 500, got %d", response.Code)
	}
	logLine := logs.String()
	if !strings.Contains(logLine, `"msg":"http application error"`) {
		t.Fatalf("expected application error log, got %s", logLine)
	}
	if !strings.Contains(logLine, `"path":"/api/test"`) {
		t.Fatalf("expected request path in log, got %s", logLine)
	}
	if !strings.Contains(logLine, `"error":"storage is read-only"`) {
		t.Fatalf("expected original error in log, got %s", logLine)
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
