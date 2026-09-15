package httpapi

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestCORSUsesConfiguredOrigins(t *testing.T) {
	next := http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	})
	handler := cors([]string{"https://dev.example.com", "https://dslab.example.com"}, next)

	allowed := httptest.NewRequest(http.MethodGet, "/", nil)
	allowed.Header.Set("Origin", "https://dslab.example.com")
	allowedResponse := httptest.NewRecorder()
	handler.ServeHTTP(allowedResponse, allowed)
	if got := allowedResponse.Header().Get("Access-Control-Allow-Origin"); got != "https://dslab.example.com" {
		t.Fatalf("unexpected allowed origin header: %q", got)
	}

	rejected := httptest.NewRequest(http.MethodGet, "/", nil)
	rejected.Header.Set("Origin", "https://untrusted.example.com")
	rejectedResponse := httptest.NewRecorder()
	handler.ServeHTTP(rejectedResponse, rejected)
	if got := rejectedResponse.Header().Get("Access-Control-Allow-Origin"); got != "" {
		t.Fatalf("unexpected rejected origin header: %q", got)
	}
}
