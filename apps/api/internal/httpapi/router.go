package httpapi

import (
	"context"
	"log/slog"
	"net/http"
	"time"

	"quickquiz/api/internal/app"
)

type ReadinessCheck func(context.Context) error

func NewRouter(runs *app.RunService, solutions *app.SolutionService, logger *slog.Logger, allowedOrigins []string, readiness ...ReadinessCheck) http.Handler {
	if logger == nil {
		logger = slog.Default()
	}

	mux := http.NewServeMux()
	handler := NewHandler(runs, solutions, logger)

	mux.HandleFunc("GET /healthz", handler.Health)
	mux.HandleFunc("GET /readyz", func(w http.ResponseWriter, r *http.Request) {
		ctx, cancel := context.WithTimeout(r.Context(), 2*time.Second)
		defer cancel()
		for _, check := range readiness {
			if err := check(ctx); err != nil {
				writeJSON(w, http.StatusServiceUnavailable, map[string]string{"status": "not_ready"})
				return
			}
		}
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})
	mux.HandleFunc("GET /api/catalog", handler.Catalog)
	mux.HandleFunc("GET /api/themes", handler.Themes)
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

	return recoverer(requestLogger(logger)(cors(allowedOrigins, mux)))
}
