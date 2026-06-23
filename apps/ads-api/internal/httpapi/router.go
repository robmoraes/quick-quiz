package httpapi

import (
	"log/slog"
	"net/http"

	"quickquiz/ads-api/internal/app"
)

func NewRouter(public *app.PublicAdService, admin *app.AdminAdService, logger *slog.Logger) http.Handler {
	if logger == nil {
		logger = slog.Default()
	}

	mux := http.NewServeMux()
	handler := NewHandler(public, admin, logger)

	mux.HandleFunc("GET /healthz", handler.Health)
	mux.HandleFunc("GET /api/ads", handler.Ads)
	mux.HandleFunc("GET /api/admin/ads/file", handler.AdminAdFile)
	mux.HandleFunc("PUT /api/admin/ads/file", handler.CreateAdminAdFile)
	mux.HandleFunc("POST /api/admin/ads/file", handler.CreateAdminAdFile)
	mux.HandleFunc("GET /api/admin/ads", handler.AdminAds)
	mux.HandleFunc("POST /api/admin/ads", handler.CreateAdminAd)
	mux.HandleFunc("GET /api/admin/ads/{id}", handler.AdminAd)
	mux.HandleFunc("PUT /api/admin/ads/{id}", handler.UpdateAdminAd)
	mux.HandleFunc("DELETE /api/admin/ads/{id}", handler.DeleteAdminAd)

	return recoverer(requestLogger(logger)(cors(mux)))
}
