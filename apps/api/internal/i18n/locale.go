package i18n

import (
	"sort"
	"strings"
)

const LocaleHeader = "X-QuickQuiz-Locale"

type Manager struct {
	fallback  string
	supported map[string]bool
}

func NewManager(fallback string, supported []string) Manager {
	normalizedFallback := NormalizeLocale(fallback)
	if normalizedFallback == "" {
		normalizedFallback = "en-US"
	}

	locales := make(map[string]bool, len(supported)+1)
	for _, locale := range supported {
		normalized := NormalizeLocale(locale)
		if normalized != "" {
			locales[normalized] = true
		}
	}
	locales[normalizedFallback] = true

	return Manager{fallback: normalizedFallback, supported: locales}
}

func (m Manager) Fallback() string {
	return m.fallback
}

func (m Manager) Supported() []string {
	locales := make([]string, 0, len(m.supported))
	for locale := range m.supported {
		locales = append(locales, locale)
	}
	sort.Strings(locales)
	return locales
}

func (m Manager) Resolve(explicit, acceptLanguage string) string {
	for _, candidate := range []string{explicit, firstAcceptedLocale(acceptLanguage)} {
		normalized := NormalizeLocale(candidate)
		if m.supported[normalized] {
			return normalized
		}
	}
	return m.fallback
}

func NormalizeLocale(locale string) string {
	locale = strings.TrimSpace(locale)
	if locale == "" {
		return ""
	}

	parts := strings.FieldsFunc(locale, func(r rune) bool {
		return r == '-' || r == '_'
	})
	if len(parts) == 0 {
		return ""
	}

	language := strings.ToLower(parts[0])
	if len(parts) == 1 {
		return language
	}

	region := strings.ToUpper(parts[1])
	return language + "-" + region
}

func firstAcceptedLocale(header string) string {
	if header == "" {
		return ""
	}

	first := strings.Split(header, ",")[0]
	return strings.Split(first, ";")[0]
}
