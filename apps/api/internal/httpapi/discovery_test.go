package httpapi

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"

	"quickquiz/api/internal/app"
	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/i18n"
	"quickquiz/api/internal/store"
)

type discoveryTestStore struct {
	*store.MemoryQuestionStore
	query  domain.DiscoveryQuery
	method string
	err    error
}

func (s *discoveryTestStore) DiscoverThemes(_ context.Context, q domain.DiscoveryQuery) (domain.DiscoveryThemePage, error) {
	s.query, s.method = q, "themes"
	return domain.DiscoveryThemePage{Locale: q.Locale, FallbackLocale: "en-US", Themes: []domain.DiscoveryTheme{}, Pagination: domain.DiscoveryPagination{Page: q.Page, PageSize: q.PageSize}}, s.err
}
func (s *discoveryTestStore) DiscoveryTheme(_ context.Context, q domain.DiscoveryQuery) (domain.DiscoveryThemeDetail, error) {
	s.query, s.method = q, "theme"
	return domain.DiscoveryThemeDetail{Locale: q.Locale, FallbackLocale: "en-US"}, s.err
}
func (s *discoveryTestStore) DiscoverTopics(_ context.Context, q domain.DiscoveryQuery) (domain.DiscoveryTopicPage, error) {
	s.query, s.method = q, "topics"
	return domain.DiscoveryTopicPage{Locale: q.Locale, FallbackLocale: "en-US", Topics: []domain.DiscoveryTopic{}, Pagination: domain.DiscoveryPagination{Page: q.Page, PageSize: q.PageSize}}, s.err
}
func (s *discoveryTestStore) DiscoveryTopic(_ context.Context, q domain.DiscoveryQuery) (domain.DiscoveryTopicDetail, error) {
	s.query, s.method = q, "topic"
	return domain.DiscoveryTopicDetail{Locale: q.Locale, FallbackLocale: "en-US"}, s.err
}
func (s *discoveryTestStore) DiscoverTags(_ context.Context, q domain.DiscoveryQuery) (domain.DiscoveryTagPage, error) {
	s.query, s.method = q, "tags"
	return domain.DiscoveryTagPage{Locale: q.Locale, FallbackLocale: "en-US", Scope: q.Scope, Tags: []domain.DiscoveryTag{}, Pagination: domain.DiscoveryPagination{Page: q.Page, PageSize: q.PageSize}}, s.err
}

func newDiscoveryTestRouter(s *discoveryTestStore) http.Handler {
	s.MemoryQuestionStore = store.NewMemoryQuestionStore(nil)
	runs := app.NewRunService(s, store.NewMemoryRunStore(time.Hour), 5, i18n.NewManager("en-US", []string{"en-US", "pt-BR"}))
	return NewRouter(runs, nil, nil, nil)
}

func TestDiscoveryRoutesArePublicAndForwardCanonicalQueries(t *testing.T) {
	s := &discoveryTestStore{}
	router := newDiscoveryTestRouter(s)
	for _, tc := range []struct{ path, method string }{
		{"/api/discovery/themes?locale=pt-br", "themes"},
		{"/api/discovery/themes/My.Theme?locale=en-US", "theme"},
		{"/api/discovery/topics?locale=pt-BR&theme=My.Theme&themeTag=%20DEV%20&topicTag=%20AWS%20&q=%20containers%20&difficulty=3&page=2&pageSize=7", "topics"},
		{"/api/discovery/themes/My.Theme/topics/same-topic?locale=en-US", "topic"},
		{"/api/discovery/tags?locale=en-US&scope=topic", "tags"},
	} {
		t.Run(tc.method, func(t *testing.T) {
			r := httptest.NewRequest(http.MethodGet, tc.path, nil)
			r.Header.Set("X-QuickQuiz-Theme", "ignored-header")
			r.Header.Set("X-QuickQuiz-Locale", "fr-FR")
			w := httptest.NewRecorder()
			router.ServeHTTP(w, r)
			if w.Code != 200 || w.Header().Get("Cache-Control") != "no-store" || s.method != tc.method {
				t.Fatalf("status=%d headers=%v method=%s body=%s", w.Code, w.Header(), s.method, w.Body.String())
			}
			if tc.method == "topics" {
				q := s.query
				if q.Locale != "pt-BR" || q.Theme != "My.Theme" || q.ThemeTag != "dev" || q.TopicTag != "aws" || q.Search != "containers" || q.Difficulty != 3 || q.Page != 2 || q.PageSize != 7 {
					t.Fatalf("query not resolved: %+v", q)
				}
			} else if s.query.Page != 1 || s.query.PageSize != 24 {
				t.Fatalf("defaults=%+v", s.query)
			}
			if tc.method == "topic" && (s.query.Theme != "My.Theme" || s.query.Topic != "same-topic") {
				t.Fatalf("identity lost: %+v", s.query)
			}
		})
	}
}

func TestDiscoveryRejectsAmbiguousUnboundedAndUnsupportedQueries(t *testing.T) {
	s := &discoveryTestStore{}
	router := newDiscoveryTestRouter(s)
	for _, tc := range []struct{ query, code string }{
		{"", "invalid_locale"}, {"locale=", "invalid_locale"}, {"locale=en-US&locale=en-US", "invalid_locale"},
		{"locale=fr-FR", "invalid_locale"}, {"locale=en_US", "invalid_locale"}, {"locale=en-US&unexpected=1", "invalid_query"},
		{"locale=en-US&q=", "invalid_query"}, {"locale=en-US&q=%20%20", "invalid_query"}, {"locale=en-US&q=a&q=b", "invalid_query"},
		{"locale=en-US&q=" + strings.Repeat("a", 201), "invalid_query"}, {"locale=en-US&q=%FF", "invalid_query"},
		{"locale=en-US&themeTag=", "invalid_tag"}, {"locale=en-US&topicTag=%20%20", "invalid_tag"},
		{"locale=en-US&topicTag=aws&topicTag=dev", "invalid_tag"}, {"locale=en-US&topicTag=AWS%2Cdev", "invalid_tag"},
		{"locale=en-US&topicTag=" + url.QueryEscape("segurança"), "invalid_tag"}, {"locale=en-US&themeTag=" + strings.Repeat("a", 51), "invalid_tag"},
		{"locale=en-US&page=0", "invalid_query"}, {"locale=en-US&page=-1", "invalid_query"}, {"locale=en-US&page=1001", "invalid_query"},
		{"locale=en-US&page=1.5", "invalid_query"}, {"locale=en-US&page=1&page=2", "invalid_query"}, {"locale=en-US&page=99999999999999999999", "invalid_query"},
		{"locale=en-US&pageSize=0", "invalid_query"}, {"locale=en-US&pageSize=101", "invalid_query"}, {"locale=en-US&pageSize=", "invalid_query"},
		{"locale=en-US&difficulty=0", "invalid_query"}, {"locale=en-US&difficulty=5", "invalid_query"}, {"locale=en-US&difficulty=1e0", "invalid_query"},
		{"locale=en-US&theme=bad%2Fid", "invalid_query"}, {"locale=en-US&theme=", "invalid_query"},
		{"locale=en-US&excludeThemes=", "invalid_query"}, {"locale=en-US&excludeThemes=%20", "invalid_query"},
		{"locale=en-US&excludeThemes=dev,", "invalid_query"}, {"locale=en-US&excludeThemes=dev,,dslab", "invalid_query"},
		{"locale=en-US&excludeThemes=dev,dev", "invalid_query"}, {"locale=en-US&excludeThemes=bad%2Fid", "invalid_query"},
		{"locale=en-US&excludeThemes=dev&excludeThemes=dslab", "invalid_query"},
		{"locale=en-US&excludeThemes=" + strings.Repeat("x", 129), "invalid_query"},
		{"locale=en-US&excludeThemes=" + strings.Repeat("x,", 50) + "x", "invalid_query"},
	} {
		t.Run(tc.query, func(t *testing.T) {
			s.method = ""
			w := httptest.NewRecorder()
			router.ServeHTTP(w, httptest.NewRequest(http.MethodGet, "/api/discovery/topics?"+tc.query, nil))
			if w.Code != 400 || !strings.Contains(w.Body.String(), `"code":"`+tc.code+`"`) || w.Header().Get("Cache-Control") != "no-store" || s.method != "" {
				t.Fatalf("bad validation: %d %s method=%s", w.Code, w.Body.String(), s.method)
			}
		})
	}
	for _, path := range []string{"/api/discovery/tags?locale=en-US", "/api/discovery/tags?locale=en-US&scope=unknown", "/api/discovery/themes/dev?locale=en-US&page=1", "/api/discovery/themes/bad%20id?locale=en-US"} {
		w := httptest.NewRecorder()
		router.ServeHTTP(w, httptest.NewRequest(http.MethodGet, path, nil))
		if w.Code != 400 || !strings.Contains(w.Body.String(), `"code":"invalid_query"`) {
			t.Fatalf("%s: %d %s", path, w.Code, w.Body.String())
		}
	}
}

func TestDiscoveryExclusionsAreOptionalAndForwardedOnEveryRoute(t *testing.T) {
	s := &discoveryTestStore{}
	router := newDiscoveryTestRouter(s)
	for _, path := range []string{"themes", "themes/My.Theme", "topics", "themes/My.Theme/topics/example", "tags"} {
		endpoint := "/api/discovery/" + path + "?locale=en-US"
		if path == "tags" {
			endpoint += "&scope=theme"
		}
		for _, excluded := range []bool{true, false} {
			request := endpoint
			if excluded {
				request += "&excludeThemes=%20dev%20,dslab"
			}
			w := httptest.NewRecorder()
			router.ServeHTTP(w, httptest.NewRequest(http.MethodGet, request, nil))
			if w.Code != 200 {
				t.Fatalf("%s: %d %s", request, w.Code, w.Body.String())
			}
			if excluded && strings.Join(s.query.ExcludedThemes, ",") != "dev,dslab" {
				t.Fatalf("exclusions not normalized/forwarded: %+v", s.query)
			}
			if !excluded && len(s.query.ExcludedThemes) != 0 {
				t.Fatal("exclusions leaked into a later request", s.query)
			}
		}
	}
}

func TestDiscoveryDependencyFailuresNeverLookLikeMissingOrEmptyContent(t *testing.T) {
	s := &discoveryTestStore{}
	router := newDiscoveryTestRouter(s)
	for _, tc := range []struct {
		err    error
		status int
		code   string
	}{
		{domain.ErrThemeNotFound, 404, "theme_not_found"}, {domain.ErrTopicNotFound, 404, "topic_not_found"},
		{domain.ErrStorageUnavailable, 503, "storage_unavailable"}, {errors.New("private database details"), 500, "internal_error"},
	} {
		s.err = tc.err
		w := httptest.NewRecorder()
		router.ServeHTTP(w, httptest.NewRequest(http.MethodGet, "/api/discovery/themes/dev/topics/go?locale=en-US", nil))
		if w.Code != tc.status || !strings.Contains(w.Body.String(), `"code":"`+tc.code+`"`) || w.Header().Get("Cache-Control") != "no-store" || strings.Contains(w.Body.String(), "private") {
			t.Fatalf("%d %s", w.Code, w.Body.String())
		}
	}
	legacy := testRouter()
	for _, path := range []string{"themes", "themes/dev", "topics", "themes/dev/topics/go", "tags"} {
		endpoint := "/api/discovery/" + path + "?locale=en-US"
		if path == "tags" {
			endpoint += "&scope=topic"
		}
		w := httptest.NewRecorder()
		legacy.ServeHTTP(w, httptest.NewRequest(http.MethodGet, endpoint, nil))
		if w.Code != 503 || !strings.Contains(w.Body.String(), `"code":"discovery_unavailable"`) || w.Header().Get("Cache-Control") != "no-store" {
			t.Fatalf("legacy %s: %d %s", path, w.Code, w.Body.String())
		}
	}
	// A local/S3 adapter remains healthy and its original catalog remains usable.
	w := httptest.NewRecorder()
	legacy.ServeHTTP(w, httptest.NewRequest(http.MethodGet, "/readyz", nil))
	if w.Code != 200 {
		t.Fatal("legacy readiness changed", w.Code)
	}
	r := httptest.NewRequest(http.MethodGet, "/api/catalog", nil)
	r.Header.Set("X-QuickQuiz-Theme", "dev")
	w = httptest.NewRecorder()
	legacy.ServeHTTP(w, r)
	if w.Code != 200 {
		t.Fatal("legacy catalog changed", w.Code, w.Body.String())
	}
}
