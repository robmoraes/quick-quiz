package httpapi

import (
	"errors"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"

	"quickquiz/api/internal/domain"
)

var discoveryInteger = regexp.MustCompile(`^[0-9]+$`)

// Query cardinality and allowed keys belong to the transport boundary; metadata
// normalization and semantic bounds are validated by the application service.
func discoveryQuery(r *http.Request, allowed ...string) (domain.DiscoveryQuery, error) {
	query := domain.DiscoveryQuery{Theme: r.PathValue("theme"), Topic: r.PathValue("topic")}
	values, err := url.ParseQuery(r.URL.RawQuery)
	if err != nil {
		return query, domain.ErrInvalidDiscoveryQuery
	}
	keys := map[string]bool{"locale": true, "excludeThemes": true}
	for _, key := range allowed {
		keys[key] = true
	}
	if len(values["locale"]) != 1 || values.Get("locale") == "" {
		return query, domain.ErrInvalidLocale
	}
	for key, list := range values {
		if !keys[key] {
			return query, domain.ErrInvalidDiscoveryQuery
		}
		if len(list) != 1 || list[0] == "" {
			switch key {
			case "locale":
				return query, domain.ErrInvalidLocale
			case "themeTag", "topicTag":
				return query, domain.ErrInvalidTag
			default:
				return query, domain.ErrInvalidDiscoveryQuery
			}
		}
	}
	query.Locale, query.Search = values.Get("locale"), values.Get("q")
	query.ThemeTag, query.TopicTag, query.Scope = values.Get("themeTag"), values.Get("topicTag"), values.Get("scope")
	if excluded, ok := values["excludeThemes"]; ok {
		query.ExcludedThemes = strings.SplitN(excluded[0], ",", 51)
	}
	if keys["theme"] {
		query.Theme = values.Get("theme")
	}
	for _, parameter := range []struct {
		key    string
		target *int
	}{{"page", &query.Page}, {"pageSize", &query.PageSize}} {
		if raw, ok := values[parameter.key]; ok {
			value, err := strconv.Atoi(raw[0])
			if err != nil || !discoveryInteger.MatchString(raw[0]) || value == 0 {
				return query, domain.ErrInvalidDiscoveryQuery
			}
			*parameter.target = value
		}
	}
	if raw, ok := values["difficulty"]; ok {
		value, err := strconv.Atoi(raw[0])
		if err != nil || !discoveryInteger.MatchString(raw[0]) || value == 0 {
			return query, domain.ErrInvalidDiscoveryQuery
		}
		query.Difficulty = domain.Difficulty(value)
	}
	return query, nil
}

func (h *Handler) discoveryError(w http.ResponseWriter, r *http.Request, err error) {
	switch {
	case errors.Is(err, domain.ErrInvalidDiscoveryQuery):
		writeError(w, 400, "invalid_query", "Invalid discovery query")
	case errors.Is(err, domain.ErrInvalidLocale):
		writeError(w, 400, "invalid_locale", "Invalid locale")
	case errors.Is(err, domain.ErrInvalidTag):
		writeError(w, 400, "invalid_tag", "Invalid tag")
	case errors.Is(err, domain.ErrDiscoveryUnavailable):
		writeError(w, 503, "discovery_unavailable", "Discovery unavailable for the configured content provider")
	case errors.Is(err, domain.ErrThemeNotFound):
		writeError(w, 404, "theme_not_found", "Theme not found")
	case errors.Is(err, domain.ErrTopicNotFound):
		writeError(w, 404, "topic_not_found", "Topic not found")
	default:
		h.writeAppError(w, r, err)
	}
}

func (h *Handler) DiscoverThemes(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	query, err := discoveryQuery(r, "q", "themeTag", "topicTag", "difficulty", "page", "pageSize")
	if err != nil {
		h.discoveryError(w, r, err)
		return
	}
	result, err := h.discovery.Themes(r.Context(), query)
	if err != nil {
		h.discoveryError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, result)
}

func (h *Handler) DiscoveryTheme(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	query, err := discoveryQuery(r)
	if err != nil {
		h.discoveryError(w, r, err)
		return
	}
	result, err := h.discovery.Theme(r.Context(), query)
	if err != nil {
		h.discoveryError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, result)
}

func (h *Handler) DiscoverTopics(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	query, err := discoveryQuery(r, "q", "theme", "themeTag", "topicTag", "difficulty", "page", "pageSize")
	if err != nil {
		h.discoveryError(w, r, err)
		return
	}
	result, err := h.discovery.Topics(r.Context(), query)
	if err != nil {
		h.discoveryError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, result)
}

func (h *Handler) DiscoveryTopic(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	query, err := discoveryQuery(r)
	if err != nil {
		h.discoveryError(w, r, err)
		return
	}
	result, err := h.discovery.Topic(r.Context(), query)
	if err != nil {
		h.discoveryError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, result)
}

func (h *Handler) DiscoverTags(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	query, err := discoveryQuery(r, "scope", "q", "theme", "themeTag", "topicTag", "difficulty", "page", "pageSize")
	if err != nil {
		h.discoveryError(w, r, err)
		return
	}
	result, err := h.discovery.Tags(r.Context(), query)
	if err != nil {
		h.discoveryError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, result)
}
