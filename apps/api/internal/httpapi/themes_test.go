package httpapi

import (
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"

	"quickquiz/api/internal/app"
	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/i18n"
	"quickquiz/api/internal/store"
)

func TestThemeDiscoveryFiltersActiveSnapshotWithoutHeaders(t *testing.T) {
	themes := []domain.Theme{
		{ID: "kubernetes", Name: "Kubernetes", Weight: 20, Active: true, Tags: domain.ThemeTags{"cloud", "dev"}},
		{ID: "dev", Name: "Development", Weight: 20, Active: true, Tags: domain.ThemeTags{"dev"}},
		{ID: "literature", Name: "Literature", Weight: 10, Active: true, Tags: domain.ThemeTags{"arts"}},
		{ID: "untagged", Name: "Untagged", Weight: 30, Active: true},
		{ID: "draft", Name: "Draft", Weight: 0, Active: false, Tags: domain.ThemeTags{"dev"}},
	}
	questions := store.NewMemoryQuestionStoreWithThemeMetadata(nil, nil, themes)
	// No question files, object storage, or database are available to the handler.
	runs := app.NewRunService(questions, nil, 10, i18n.NewManager("en-US", []string{"en-US"}))
	router := NewRouter(runs, nil, slog.New(slog.NewTextHandler(io.Discard, nil)), nil)
	for _, test := range []struct {
		query  string
		status int
		ids    []string
	}{
		{"", 200, []string{"literature", "dev", "kubernetes", "untagged"}},
		{"?tag=DEV", 200, []string{"dev", "kubernetes"}},
		{"?tag=%20dev%09", 200, []string{"dev", "kubernetes"}},
		{"?tag=de", 200, []string{}},
		{"?tag=unknown", 200, []string{}},
		{"?tag=", 400, nil},
		{"?tag=dev&tag=arts", 400, nil},
		{"?tag=dev%20ops", 400, nil},
		{"?tag=%ZZ", 400, nil},
		{"?tag=dev;arts", 400, nil},
		{"?tag=" + strings.Repeat("a", 51), 400, nil},
	} {
		t.Run(test.query, func(t *testing.T) {
			response := httptest.NewRecorder()
			router.ServeHTTP(response, httptest.NewRequest(http.MethodGet, "/api/themes"+test.query, nil))
			if response.Code != test.status {
				t.Fatalf("status %d: %s", response.Code, response.Body)
			}
			if test.status == 400 {
				if !strings.Contains(response.Body.String(), `"code":"invalid_tag"`) {
					t.Fatal(response.Body.String())
				}
				return
			}
			var body struct {
				Themes []domain.Theme `json:"themes"`
			}
			if err := json.Unmarshal(response.Body.Bytes(), &body); err != nil {
				t.Fatal(err)
			}
			ids := make([]string, 0, len(body.Themes))
			for _, theme := range body.Themes {
				ids = append(ids, theme.ID)
				if theme.Tags == nil {
					t.Fatal("untagged themes must serialize an empty array")
				}
			}
			if !reflect.DeepEqual(ids, test.ids) || body.Themes == nil {
				t.Fatalf("themes = %#v, want %#v", body.Themes, test.ids)
			}
		})
	}
}
