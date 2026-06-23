package httpapi

import (
	"log/slog"
	"net/http"

	"quickquiz/api/internal/app"
)

func NewRouter(runs *app.RunService, solutions *app.SolutionService, logger *slog.Logger) http.Handler {
	if logger == nil {
		logger = slog.Default()
	}

	mux := http.NewServeMux()
	handler := NewHandler(runs, solutions, logger)

	mux.HandleFunc("GET /healthz", handler.Health)
	mux.HandleFunc("GET /api/catalog", handler.Catalog)
	mux.HandleFunc("GET /api/session/topics", handler.SessionTopics)
	mux.HandleFunc("GET /api/session/difficulties", handler.SessionDifficulties)
	mux.HandleFunc("POST /api/session/reset", handler.ResetSession)
	mux.HandleFunc("POST /api/runs", handler.CreateRun)
	mux.HandleFunc("POST /api/runs/{runId}/answers", handler.Answer)
	mux.HandleFunc("POST /api/runs/{runId}/finish", handler.Finish)
	mux.HandleFunc("GET /api/runs/{runId}/state", handler.RunState)
	mux.HandleFunc("GET /api/runs/{runId}/result", handler.Result)
	mux.HandleFunc("GET /api/runs/{runId}/questions/{questionId}/solution", handler.QuestionSolution)
	mux.HandleFunc("GET /api/sessions/active", handler.ActiveSessions)

	return recoverer(requestLogger(logger)(cors(mux)))
}
