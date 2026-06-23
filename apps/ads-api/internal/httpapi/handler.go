package httpapi

import (
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"strconv"
	"strings"

	"quickquiz/ads-api/internal/app"
	"quickquiz/ads-api/internal/domain"
)

type Handler struct {
	public *app.PublicAdService
	admin  *app.AdminAdService
	logger *slog.Logger
}

func NewHandler(public *app.PublicAdService, admin *app.AdminAdService, logger *slog.Logger) *Handler {
	if logger == nil {
		logger = slog.Default()
	}
	return &Handler{public: public, admin: admin, logger: logger}
}

func (h *Handler) Health(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (h *Handler) Ads(w http.ResponseWriter, r *http.Request) {
	limit, err := limitFromRequest(r)
	if err != nil {
		writeError(w, http.StatusBadRequest, "invalid_input", "Invalid input")
		return
	}
	emphasisLimit, err := emphasisLimitFromRequest(r)
	if err != nil {
		writeError(w, http.StatusBadRequest, "invalid_input", "Invalid input")
		return
	}

	ads, err := h.public.List(r.Context(), app.ListAdsInput{
		Theme:         themeFromRequest(r),
		Topic:         strings.TrimSpace(r.URL.Query().Get("topic")),
		Limit:         limit,
		EmphasisLimit: emphasisLimit,
	})
	if err != nil {
		h.writeAppError(w, r, err)
		return
	}

	writeJSON(w, http.StatusOK, ads)
}

func (h *Handler) AdminAdFile(w http.ResponseWriter, r *http.Request) {
	exists, err := h.admin.Exists(r.Context())
	if err != nil {
		h.writeAppError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]bool{"exists": exists})
}

func (h *Handler) CreateAdminAdFile(w http.ResponseWriter, r *http.Request) {
	if err := h.admin.CreateBaseFile(r.Context()); err != nil {
		h.writeAppError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]bool{"exists": true})
}

func (h *Handler) AdminAds(w http.ResponseWriter, r *http.Request) {
	ads, err := h.admin.List(r.Context(), strings.TrimSpace(r.URL.Query().Get("theme")))
	if err != nil {
		h.writeAppError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string][]domain.AdminAd{"ads": ads})
}

func (h *Handler) CreateAdminAd(w http.ResponseWriter, r *http.Request) {
	var input domain.AdminAd
	if err := decodeJSON(r, &input); err != nil {
		writeError(w, http.StatusBadRequest, "invalid_json", "Invalid JSON")
		return
	}

	ad, err := h.admin.Create(r.Context(), input)
	if err != nil {
		h.writeAppError(w, r, err)
		return
	}
	writeJSON(w, http.StatusCreated, ad)
}

func (h *Handler) AdminAd(w http.ResponseWriter, r *http.Request) {
	ad, err := h.admin.Ad(r.Context(), r.PathValue("id"))
	if err != nil {
		h.writeAppError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, ad)
}

func (h *Handler) UpdateAdminAd(w http.ResponseWriter, r *http.Request) {
	var input domain.AdminAd
	if err := decodeJSON(r, &input); err != nil {
		writeError(w, http.StatusBadRequest, "invalid_json", "Invalid JSON")
		return
	}

	ad, err := h.admin.Update(r.Context(), r.PathValue("id"), input)
	if err != nil {
		h.writeAppError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, ad)
}

func (h *Handler) DeleteAdminAd(w http.ResponseWriter, r *http.Request) {
	if err := h.admin.Delete(r.Context(), r.PathValue("id")); err != nil {
		h.writeAppError(w, r, err)
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

func (h *Handler) writeAppError(w http.ResponseWriter, r *http.Request, err error) {
	switch {
	case errors.Is(err, app.ErrThemeRequired):
		writeError(w, http.StatusBadRequest, "theme_required", "Theme is required")
	case errors.Is(err, app.ErrThemeNotFound):
		writeError(w, http.StatusBadRequest, "theme_not_found", "Theme not found")
	case errors.Is(err, app.ErrThemeInactive):
		writeError(w, http.StatusBadRequest, "theme_inactive", "Theme inactive")
	case errors.Is(err, app.ErrInvalidInput):
		writeError(w, http.StatusBadRequest, "invalid_input", "Invalid input")
	case errors.Is(err, app.ErrAdNotFound):
		writeError(w, http.StatusNotFound, "ad_not_found", "Ad not found")
	default:
		h.logger.Error("http application error",
			"method", r.Method,
			"path", r.URL.Path,
			"status", http.StatusInternalServerError,
			"code", "internal_error",
			"error", err,
		)
		writeError(w, http.StatusInternalServerError, "internal_error", "Internal error")
	}
}

func decodeJSON(r *http.Request, target any) error {
	decoder := json.NewDecoder(r.Body)
	decoder.DisallowUnknownFields()
	return decoder.Decode(target)
}

func themeFromRequest(r *http.Request) string {
	return strings.TrimSpace(r.Header.Get("X-QuickQuiz-Theme"))
}

func limitFromRequest(r *http.Request) (int, error) {
	raw := strings.TrimSpace(r.URL.Query().Get("limit"))
	if raw == "" {
		return 0, nil
	}

	limit, err := strconv.Atoi(raw)
	if err != nil || limit <= 0 {
		return 0, app.ErrInvalidInput
	}
	return limit, nil
}

func emphasisLimitFromRequest(r *http.Request) (int, error) {
	raw := strings.TrimSpace(r.URL.Query().Get("emphasis"))
	if raw == "" {
		return 0, nil
	}

	limit, err := strconv.Atoi(raw)
	if err != nil || limit <= 0 {
		return 0, app.ErrInvalidInput
	}
	return limit, nil
}
