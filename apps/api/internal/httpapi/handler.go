package httpapi

import (
	"encoding/json"
	"errors"
	"net/http"
	"strings"

	"quickquiz/api/internal/app"
	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/i18n"
)

type Handler struct {
	runs *app.RunService
}

func NewHandler(runs *app.RunService) *Handler {
	return &Handler{runs: runs}
}

func (h *Handler) Health(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (h *Handler) Catalog(w http.ResponseWriter, r *http.Request) {
	catalog, err := h.runs.Metadata(r.Context(), themeFromRequest(r), localeFromRequest(r, ""))
	if err != nil {
		h.writeAppError(w, err)
		return
	}

	writeJSON(w, http.StatusOK, catalog)
}

func (h *Handler) SessionTopics(w http.ResponseWriter, r *http.Request) {
	topics, err := h.runs.SessionTopics(r.Context(), app.SessionTopicsInput{
		SessionID: sessionID(r),
		Theme:     themeFromRequest(r),
		Locale:    localeFromRequest(r, ""),
	})
	if err != nil {
		h.writeAppError(w, err)
		return
	}

	writeJSON(w, http.StatusOK, topics)
}

func (h *Handler) SessionDifficulties(w http.ResponseWriter, r *http.Request) {
	difficulties, err := h.runs.SessionDifficulties(r.Context(), app.SessionDifficultiesInput{
		SessionID: sessionID(r),
		Theme:     themeFromRequest(r),
		Locale:    localeFromRequest(r, ""),
		Topic:     r.URL.Query().Get("topic"),
	})
	if err != nil {
		h.writeAppError(w, err)
		return
	}

	writeJSON(w, http.StatusOK, difficulties)
}

func (h *Handler) CreateRun(w http.ResponseWriter, r *http.Request) {
	var request struct {
		Topic      string            `json:"topic"`
		Difficulty domain.Difficulty `json:"difficulty"`
		Locale     string            `json:"locale"`
	}
	if err := decodeJSON(r, &request); err != nil {
		writeError(w, http.StatusBadRequest, "invalid_json", "Invalid JSON")
		return
	}

	output, err := h.runs.CreateRun(r.Context(), app.CreateRunInput{
		SessionID:  sessionID(r),
		Theme:      themeFromRequest(r),
		Locale:     localeFromRequest(r, request.Locale),
		Topic:      request.Topic,
		Difficulty: request.Difficulty,
	})
	if err != nil {
		h.writeAppError(w, err)
		return
	}

	writeJSON(w, http.StatusCreated, output)
}

func (h *Handler) ResetSession(w http.ResponseWriter, r *http.Request) {
	if err := h.runs.ResetSession(r.Context(), sessionID(r), themeFromRequest(r)); err != nil {
		h.writeAppError(w, err)
		return
	}

	writeJSON(w, http.StatusOK, map[string]bool{"reset": true})
}

func (h *Handler) Answer(w http.ResponseWriter, r *http.Request) {
	var request struct {
		QuestionID string `json:"questionId"`
		OptionID   string `json:"optionId"`
	}
	if err := decodeJSON(r, &request); err != nil {
		writeError(w, http.StatusBadRequest, "invalid_json", "Invalid JSON")
		return
	}

	output, err := h.runs.Answer(r.Context(), themeFromRequest(r), r.PathValue("runId"), request.QuestionID, request.OptionID)
	if err != nil {
		h.writeAppError(w, err)
		return
	}

	writeJSON(w, http.StatusOK, output)
}

func (h *Handler) Finish(w http.ResponseWriter, r *http.Request) {
	if err := h.runs.Finish(r.Context(), themeFromRequest(r), r.PathValue("runId")); err != nil {
		h.writeAppError(w, err)
		return
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"finished":     true,
		"finishReason": domain.FinishReasonPlayerQuit,
	})
}

func (h *Handler) Result(w http.ResponseWriter, r *http.Request) {
	result, err := h.runs.Result(r.Context(), themeFromRequest(r), r.PathValue("runId"))
	if err != nil {
		h.writeAppError(w, err)
		return
	}

	writeJSON(w, http.StatusOK, result)
}

func (h *Handler) writeAppError(w http.ResponseWriter, err error) {
	switch {
	case errors.Is(err, app.ErrThemeRequired):
		writeError(w, http.StatusBadRequest, "theme_required", "Theme is required")
	case errors.Is(err, app.ErrThemeNotFound):
		writeError(w, http.StatusBadRequest, "theme_not_found", "Theme not found")
	case errors.Is(err, app.ErrThemeInactive):
		writeError(w, http.StatusBadRequest, "theme_inactive", "Theme inactive")
	case errors.Is(err, app.ErrInvalidInput):
		writeError(w, http.StatusBadRequest, "invalid_input", "Invalid input")
	case errors.Is(err, app.ErrRunNotFound):
		writeError(w, http.StatusNotFound, "run_not_found", "Run not found")
	case errors.Is(err, app.ErrRunFinished):
		writeError(w, http.StatusConflict, "run_finished", "Run already finished")
	case errors.Is(err, app.ErrQuestionMismatch):
		writeError(w, http.StatusConflict, "question_mismatch", "Question does not match current run state")
	case errors.Is(err, app.ErrOptionNotFound):
		writeError(w, http.StatusBadRequest, "option_not_found", "Option not found")
	case errors.Is(err, app.ErrNoQuestionsLeft):
		writeError(w, http.StatusConflict, "no_questions_left", "No questions available")
	default:
		writeError(w, http.StatusInternalServerError, "internal_error", "Internal error")
	}
}

func decodeJSON(r *http.Request, target any) error {
	decoder := json.NewDecoder(r.Body)
	decoder.DisallowUnknownFields()
	return decoder.Decode(target)
}

func sessionID(r *http.Request) string {
	return r.Header.Get("X-QuickQuiz-Session-ID")
}

func themeFromRequest(r *http.Request) string {
	return strings.TrimSpace(r.Header.Get("X-QuickQuiz-Theme"))
}

func localeFromRequest(r *http.Request, explicit string) string {
	if explicit != "" {
		return explicit
	}
	if queryLocale := r.URL.Query().Get("locale"); queryLocale != "" {
		return queryLocale
	}
	if headerLocale := r.Header.Get(i18n.LocaleHeader); headerLocale != "" {
		return headerLocale
	}
	return r.Header.Get("Accept-Language")
}
