package app

import (
	"context"
	"regexp"
	"strings"
	"unicode/utf8"

	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/i18n"
)

type DiscoveryRepository interface {
	DiscoverThemes(context.Context, domain.DiscoveryQuery) (domain.DiscoveryThemePage, error)
	DiscoveryTheme(context.Context, domain.DiscoveryQuery) (domain.DiscoveryThemeDetail, error)
	DiscoverTopics(context.Context, domain.DiscoveryQuery) (domain.DiscoveryTopicPage, error)
	DiscoveryTopic(context.Context, domain.DiscoveryQuery) (domain.DiscoveryTopicDetail, error)
	DiscoverTags(context.Context, domain.DiscoveryQuery) (domain.DiscoveryTagPage, error)
}

type DiscoveryService struct {
	repository DiscoveryRepository
	locales    i18n.Manager
}

func NewDiscoveryService(repository DiscoveryRepository, locales i18n.Manager) *DiscoveryService {
	return &DiscoveryService{repository: repository, locales: locales}
}

// Discovery keeps discovery outside gameplay while preserving router constructors.
// Legacy adapters deliberately have no synthetic/empty discovery implementation.
func (s *RunService) Discovery() *DiscoveryService {
	if s == nil {
		return NewDiscoveryService(nil, i18n.NewManager("en-US", nil))
	}
	repository, _ := s.questions.(DiscoveryRepository)
	return NewDiscoveryService(repository, s.locales)
}

var discoveryIdentifier = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]*$`)
var discoveryLocale = regexp.MustCompile(`^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$`)

func (s *DiscoveryService) validate(query domain.DiscoveryQuery) (domain.DiscoveryQuery, error) {
	if len(query.Locale) > 35 || !discoveryLocale.MatchString(query.Locale) {
		return query, domain.ErrInvalidLocale
	}
	matchedLocale := ""
	for _, supported := range s.locales.Supported() {
		if strings.EqualFold(supported, query.Locale) {
			matchedLocale = supported
			break
		}
	}
	if matchedLocale == "" {
		return query, domain.ErrInvalidLocale
	}
	query.Locale = matchedLocale
	if !utf8.ValidString(query.Search) || len(query.Search) > 800 || utf8.RuneCountInString(query.Search) > 200 || (query.Search != "" && strings.TrimSpace(query.Search) == "") {
		return query, domain.ErrInvalidDiscoveryQuery
	}
	query.Search = strings.TrimSpace(query.Search)
	for _, id := range []string{query.Theme, query.Topic} {
		if id != "" && !discoveryIdentifier.MatchString(id) {
			return query, domain.ErrInvalidDiscoveryQuery
		}
	}
	for _, tag := range []*string{&query.ThemeTag, &query.TopicTag} {
		if *tag != "" {
			normalized, err := domain.NormalizeTag(*tag)
			if err != nil {
				return query, err
			}
			*tag = normalized
		}
	}
	if query.Difficulty != 0 && !query.Difficulty.Valid() {
		return query, domain.ErrInvalidDiscoveryQuery
	}
	if query.Page == 0 {
		query.Page = 1
	}
	if query.PageSize == 0 {
		query.PageSize = 24
	}
	if query.Page < 1 || query.Page > 1000 || query.PageSize < 1 || query.PageSize > 100 {
		return query, domain.ErrInvalidDiscoveryQuery
	}
	if s.repository == nil {
		return query, domain.ErrDiscoveryUnavailable
	}
	return query, nil
}

func (s *DiscoveryService) Themes(ctx context.Context, query domain.DiscoveryQuery) (domain.DiscoveryThemePage, error) {
	query, err := s.validate(query)
	if err != nil {
		return domain.DiscoveryThemePage{}, err
	}
	return s.repository.DiscoverThemes(ctx, query)
}

func (s *DiscoveryService) Theme(ctx context.Context, query domain.DiscoveryQuery) (domain.DiscoveryThemeDetail, error) {
	query, err := s.validate(query)
	if err != nil {
		return domain.DiscoveryThemeDetail{}, err
	}
	if query.Theme == "" {
		return domain.DiscoveryThemeDetail{}, domain.ErrInvalidDiscoveryQuery
	}
	return s.repository.DiscoveryTheme(ctx, query)
}

func (s *DiscoveryService) Topics(ctx context.Context, query domain.DiscoveryQuery) (domain.DiscoveryTopicPage, error) {
	query, err := s.validate(query)
	if err != nil {
		return domain.DiscoveryTopicPage{}, err
	}
	return s.repository.DiscoverTopics(ctx, query)
}

func (s *DiscoveryService) Topic(ctx context.Context, query domain.DiscoveryQuery) (domain.DiscoveryTopicDetail, error) {
	query, err := s.validate(query)
	if err != nil {
		return domain.DiscoveryTopicDetail{}, err
	}
	if query.Theme == "" || query.Topic == "" {
		return domain.DiscoveryTopicDetail{}, domain.ErrInvalidDiscoveryQuery
	}
	return s.repository.DiscoveryTopic(ctx, query)
}

func (s *DiscoveryService) Tags(ctx context.Context, query domain.DiscoveryQuery) (domain.DiscoveryTagPage, error) {
	if query.Scope != "theme" && query.Scope != "topic" {
		return domain.DiscoveryTagPage{}, domain.ErrInvalidDiscoveryQuery
	}
	query, err := s.validate(query)
	if err != nil {
		return domain.DiscoveryTagPage{}, err
	}
	return s.repository.DiscoverTags(ctx, query)
}
