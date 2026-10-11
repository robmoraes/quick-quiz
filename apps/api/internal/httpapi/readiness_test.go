package httpapi

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"quickquiz/api/internal/domain"
)

func TestReadinessFailureDoesNotFailLivenessOrExposeDetails(t *testing.T) {
	router := NewRouter(nil, nil, nil, nil, func(context.Context) error { return errors.New("postgres://private-password@database/internal") })
	for _, test := range []struct {
		path   string
		status int
		body   string
	}{{"/readyz", 503, "not_ready"}, {"/healthz", 200, "ok"}} {
		recorder := httptest.NewRecorder()
		router.ServeHTTP(recorder, httptest.NewRequest(http.MethodGet, test.path, nil))
		if recorder.Code != test.status || !strings.Contains(recorder.Body.String(), test.body) || strings.Contains(recorder.Body.String(), "password") {
			t.Fatalf("%s: status=%d body=%s", test.path, recorder.Code, recorder.Body.String())
		}
	}
}
func TestStorageUnavailableIsNotNotFound(t *testing.T) {
	h := NewHandler(nil, nil, nil)
	recorder := httptest.NewRecorder()
	h.writeAppError(recorder, httptest.NewRequest(http.MethodGet, "/api/catalog", nil), domain.ErrStorageUnavailable)
	if recorder.Code != http.StatusServiceUnavailable || !strings.Contains(recorder.Body.String(), `"code":"storage_unavailable"`) {
		t.Fatalf("status=%d body=%s", recorder.Code, recorder.Body.String())
	}
}
