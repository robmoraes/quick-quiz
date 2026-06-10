package httpapi

import (
	"log/slog"
	"net/http"

	"quickquiz/api/internal/app"
)

func NewRouter(runs *app.RunService, logger *slog.Logger) http.Handler {
	mux := http.NewServeMux()
	handler := NewHandler(runs)

	mux.HandleFunc("GET /healthz", handler.Health)
	mux.HandleFunc("GET /api/catalog", handler.Catalog)
	mux.HandleFunc("GET /api/session/topics", handler.SessionTopics)
	mux.HandleFunc("GET /api/session/difficulties", handler.SessionDifficulties)
	mux.HandleFunc("POST /api/session/reset", handler.ResetSession)
	mux.HandleFunc("POST /api/runs", handler.CreateRun)
	mux.HandleFunc("POST /api/runs/{runId}/answers", handler.Answer)
	mux.HandleFunc("POST /api/runs/{runId}/finish", handler.Finish)
	mux.HandleFunc("GET /api/runs/{runId}/result", handler.Result)

	return recoverer(requestLogger(logger)(cors(mux)))
}
