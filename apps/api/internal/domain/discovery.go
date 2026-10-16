package domain

import "errors"

var (
	ErrDiscoveryUnavailable  = errors.New("discovery unavailable")
	ErrInvalidDiscoveryQuery = errors.New("invalid discovery query")
	ErrInvalidLocale         = errors.New("invalid locale")
	ErrTopicNotFound         = errors.New("topic not found")
)

// DiscoveryQuery is validated by the application before reaching a reader.
// Empty optional strings and zero difficulty mean no filter.
type DiscoveryQuery struct {
	Locale     string
	Search     string
	Theme      string
	Topic      string
	ThemeTag   string
	TopicTag   string
	Difficulty Difficulty
	Scope      string
	Page       int
	PageSize   int
}

type DiscoveryPagination struct {
	Page       int   `json:"page"`
	PageSize   int   `json:"pageSize"`
	Total      int64 `json:"total"`
	TotalPages int64 `json:"totalPages"`
}

type DiscoveryDifficulty struct {
	ID            Difficulty `json:"id"`
	QuestionCount int64      `json:"questionCount"`
}

type DiscoveryThemeIdentity struct {
	ID          string   `json:"id"`
	Name        string   `json:"name"`
	Description string   `json:"description"`
	Weight      int      `json:"weight"`
	Tags        []string `json:"tags"`
}

type DiscoveryTheme struct {
	DiscoveryThemeIdentity
	TopicCount         int64                 `json:"topicCount"`
	PlayableTopicCount int64                 `json:"playableTopicCount"`
	QuestionCount      int64                 `json:"questionCount"`
	Difficulties       []DiscoveryDifficulty `json:"difficulties"`
	Playable           bool                  `json:"playable"`
}

type DiscoveryTopic struct {
	Theme         DiscoveryThemeIdentity `json:"theme"`
	ID            string                 `json:"id"`
	Label         string                 `json:"label"`
	Description   string                 `json:"description"`
	Weight        int                    `json:"weight"`
	Tags          []string               `json:"tags"`
	QuestionCount int64                  `json:"questionCount"`
	Difficulties  []DiscoveryDifficulty  `json:"difficulties"`
	Playable      bool                   `json:"playable"`
}

type DiscoveryTag struct {
	Slug          string `json:"slug"`
	ThemeCount    int64  `json:"themeCount"`
	TopicCount    int64  `json:"topicCount"`
	QuestionCount int64  `json:"questionCount"`
}

type DiscoveryThemePage struct {
	Locale         string              `json:"locale"`
	FallbackLocale string              `json:"fallbackLocale"`
	Themes         []DiscoveryTheme    `json:"themes"`
	Pagination     DiscoveryPagination `json:"pagination"`
}

type DiscoveryTopicPage struct {
	Locale         string              `json:"locale"`
	FallbackLocale string              `json:"fallbackLocale"`
	Topics         []DiscoveryTopic    `json:"topics"`
	Pagination     DiscoveryPagination `json:"pagination"`
}

type DiscoveryTagPage struct {
	Locale         string              `json:"locale"`
	FallbackLocale string              `json:"fallbackLocale"`
	Scope          string              `json:"scope"`
	Tags           []DiscoveryTag      `json:"tags"`
	Pagination     DiscoveryPagination `json:"pagination"`
}

type DiscoveryThemeDetail struct {
	Locale         string         `json:"locale"`
	FallbackLocale string         `json:"fallbackLocale"`
	Theme          DiscoveryTheme `json:"theme"`
}

type DiscoveryTopicDetail struct {
	Locale         string         `json:"locale"`
	FallbackLocale string         `json:"fallbackLocale"`
	Topic          DiscoveryTopic `json:"topic"`
}
