package app

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"math/big"
	"net/url"
	"regexp"
	"sort"
	"strings"
	"time"

	"quickquiz/ads-api/internal/domain"
)

const DefaultAdLimit = 1
const MaxAdLimit = 20

var uuidPattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)

type AdRepository interface {
	Exists(ctx context.Context) (bool, error)
	CreateBaseFile(ctx context.Context) error
	List(ctx context.Context) ([]domain.Ad, error)
	ListByTheme(ctx context.Context, theme string) ([]domain.Ad, error)
	Ad(ctx context.Context, id string) (domain.Ad, error)
	Create(ctx context.Context, ad domain.Ad) error
	Update(ctx context.Context, id string, ad domain.Ad) error
	Delete(ctx context.Context, id string) error
}

type CatalogRepository interface {
	Theme(ctx context.Context, theme string) (domain.Theme, error)
	Topics(ctx context.Context, theme string) ([]domain.Topic, error)
}

type PublicAdService struct {
	ads     AdRepository
	catalog CatalogRepository
	now     func() time.Time
}

func NewPublicAdService(ads AdRepository, catalog CatalogRepository) *PublicAdService {
	return &PublicAdService{
		ads:     ads,
		catalog: catalog,
		now:     func() time.Time { return time.Now().UTC() },
	}
}

type ListAdsInput struct {
	Theme         string
	Topic         string
	Limit         int
	EmphasisLimit int
}

func (s *PublicAdService) List(ctx context.Context, input ListAdsInput) (domain.Ads, error) {
	if err := validateActiveTheme(ctx, s.catalog, input.Theme); err != nil {
		return domain.Ads{}, err
	}

	limit := input.Limit
	if limit <= 0 {
		limit = DefaultAdLimit
	}
	if limit > MaxAdLimit {
		limit = MaxAdLimit
	}

	emphasisLimit := input.EmphasisLimit
	if emphasisLimit < 0 {
		emphasisLimit = 0
	}
	if emphasisLimit > MaxAdLimit {
		emphasisLimit = MaxAdLimit
	}

	ads, err := s.ads.ListByTheme(ctx, strings.TrimSpace(input.Theme))
	if err != nil {
		return domain.Ads{}, err
	}

	now := s.now()
	eligible := make([]domain.Ad, 0, len(ads))
	emphasisEligible := make([]domain.Ad, 0, len(ads))
	for _, ad := range ads {
		if !ad.Active || adExpired(ad, now) {
			continue
		}
		if emphasisLimit > 0 && ad.Emphasis {
			emphasisEligible = append(emphasisEligible, ad)
			continue
		}
		eligible = append(eligible, ad)
	}

	selected := selectAds(eligible, limit, input.Topic)
	var emphasis []domain.PublicAd
	if emphasisLimit > 0 && len(emphasisEligible) > 0 {
		emphasis = publicAds(selectAds(emphasisEligible, emphasisLimit, input.Topic))
	}

	return domain.Ads{
		Theme:    strings.TrimSpace(input.Theme),
		Ads:      publicAds(selected),
		Emphasis: emphasis,
	}, nil
}

type AdminAdService struct {
	ads     AdRepository
	catalog CatalogRepository
	now     func() time.Time
}

func NewAdminAdService(ads AdRepository, catalog CatalogRepository) *AdminAdService {
	return &AdminAdService{
		ads:     ads,
		catalog: catalog,
		now:     func() time.Time { return time.Now().UTC() },
	}
}

func (s *AdminAdService) Exists(ctx context.Context) (bool, error) {
	return s.ads.Exists(ctx)
}

func (s *AdminAdService) CreateBaseFile(ctx context.Context) error {
	return s.ads.CreateBaseFile(ctx)
}

func (s *AdminAdService) List(ctx context.Context, theme string) ([]domain.AdminAd, error) {
	ads, err := s.ads.List(ctx)
	if err != nil {
		return nil, err
	}

	theme = strings.TrimSpace(theme)
	output := make([]domain.AdminAd, 0, len(ads))
	for _, ad := range ads {
		if theme != "" && !adTargetsTheme(ad, theme) {
			continue
		}
		output = append(output, domain.AdminAdFrom(ad))
	}
	return output, nil
}

func (s *AdminAdService) Ad(ctx context.Context, id string) (domain.AdminAd, error) {
	ad, err := s.ads.Ad(ctx, strings.TrimSpace(id))
	if err != nil {
		if errors.Is(err, domain.ErrAdNotFound) {
			return domain.AdminAd{}, ErrAdNotFound
		}
		return domain.AdminAd{}, err
	}
	return domain.AdminAdFrom(ad), nil
}

func (s *AdminAdService) Create(ctx context.Context, input domain.AdminAd) (domain.AdminAd, error) {
	id, err := newUUIDV4()
	if err != nil {
		return domain.AdminAd{}, err
	}

	ad, err := s.validatedAd(ctx, input, id, true)
	if err != nil {
		return domain.AdminAd{}, err
	}
	if err := s.ads.Create(ctx, ad); err != nil {
		return domain.AdminAd{}, err
	}
	return domain.AdminAdFrom(ad), nil
}

func (s *AdminAdService) Update(ctx context.Context, id string, input domain.AdminAd) (domain.AdminAd, error) {
	ad, err := s.validatedAd(ctx, input, strings.TrimSpace(id), false)
	if err != nil {
		return domain.AdminAd{}, err
	}
	if err := s.ads.Update(ctx, ad.ID, ad); err != nil {
		if errors.Is(err, domain.ErrAdNotFound) {
			return domain.AdminAd{}, ErrAdNotFound
		}
		return domain.AdminAd{}, err
	}
	return domain.AdminAdFrom(ad), nil
}

func (s *AdminAdService) Delete(ctx context.Context, id string) error {
	if err := s.ads.Delete(ctx, strings.TrimSpace(id)); err != nil {
		if errors.Is(err, domain.ErrAdNotFound) {
			return ErrAdNotFound
		}
		return err
	}
	return nil
}

func (s *AdminAdService) validatedAd(ctx context.Context, input domain.AdminAd, id string, creating bool) (domain.Ad, error) {
	id = strings.ToLower(strings.TrimSpace(id))
	if !uuidPattern.MatchString(id) {
		return domain.Ad{}, ErrInvalidInput
	}

	uri := strings.TrimSpace(input.URI)
	image := strings.TrimSpace(input.Image)
	description := strings.TrimSpace(input.Description)
	if !validHTTPURL(uri) || !validHTTPURL(image) || description == "" {
		return domain.Ad{}, ErrInvalidInput
	}

	createdAt := strings.TrimSpace(input.CreatedAt)
	if createdAt == "" && creating {
		createdAt = domain.FormatUTC(s.now())
	}
	if createdAt != "" {
		normalized, err := normalizeDatetime(createdAt)
		if err != nil {
			return domain.Ad{}, ErrInvalidInput
		}
		createdAt = normalized
	}

	var expiresIn *time.Time
	if input.ExpiresIn != nil && strings.TrimSpace(*input.ExpiresIn) != "" {
		parsed, err := time.Parse(time.RFC3339, strings.TrimSpace(*input.ExpiresIn))
		if err != nil {
			return domain.Ad{}, ErrInvalidInput
		}
		utc := parsed.UTC()
		expiresIn = &utc
	}

	targets := normalizeTargets(input.Themes)
	if err := s.validateTargets(ctx, targets); err != nil {
		return domain.Ad{}, err
	}

	return domain.Ad{
		ID:          id,
		ProviderID:  strings.TrimSpace(input.ProviderID),
		URI:         uri,
		Description: description,
		Image:       image,
		CreatedAt:   createdAt,
		ExpiresIn:   expiresIn,
		Active:      input.Active,
		Emphasis:    input.Emphasis,
		Targets:     targets,
	}, nil
}

func (s *AdminAdService) validateTargets(ctx context.Context, targets []domain.AdTarget) error {
	for _, target := range targets {
		if _, err := s.catalog.Theme(ctx, target.Theme); err != nil {
			if errors.Is(err, domain.ErrThemeNotFound) {
				return ErrThemeNotFound
			}
			return err
		}
		if len(target.Topics) == 0 {
			continue
		}
		topics, err := s.catalog.Topics(ctx, target.Theme)
		if err != nil {
			return err
		}
		known := make(map[string]bool, len(topics))
		for _, topic := range topics {
			known[topic.Key] = true
		}
		for _, topic := range target.Topics {
			if !known[topic] {
				return ErrInvalidInput
			}
		}
	}
	return nil
}

func validateActiveTheme(ctx context.Context, catalog CatalogRepository, theme string) error {
	theme = strings.TrimSpace(theme)
	if theme == "" {
		return ErrThemeRequired
	}
	found, err := catalog.Theme(ctx, theme)
	if err != nil {
		if errors.Is(err, domain.ErrThemeNotFound) {
			return ErrThemeNotFound
		}
		return err
	}
	if !found.Active {
		return ErrThemeInactive
	}
	return nil
}

func adExpired(ad domain.Ad, now time.Time) bool {
	return ad.ExpiresIn != nil && !ad.ExpiresIn.After(now)
}

func selectAds(ads []domain.Ad, limit int, topic string) []domain.Ad {
	topic = strings.TrimSpace(topic)
	if topic == "" {
		shuffleAds(ads)
		if len(ads) > limit {
			ads = ads[:limit]
		}
		return ads
	}

	matching := make([]domain.Ad, 0, len(ads))
	general := make([]domain.Ad, 0, len(ads))
	for _, ad := range ads {
		if adHasTopic(ad, topic) {
			matching = append(matching, ad)
			continue
		}
		if adTargetsAllTopics(ad) {
			general = append(general, ad)
			continue
		}
	}

	shuffleAds(matching)
	shuffleAds(general)
	selected := make([]domain.Ad, 0, limit)
	selected = appendLimited(selected, matching, limit)
	if len(selected) < limit {
		selected = appendLimited(selected, general, limit)
	}

	return selected
}

func appendLimited(target []domain.Ad, source []domain.Ad, limit int) []domain.Ad {
	remaining := limit - len(target)
	if remaining <= 0 {
		return target
	}
	if len(source) > remaining {
		source = source[:remaining]
	}
	return append(target, source...)
}

func publicAds(ads []domain.Ad) []domain.PublicAd {
	public := make([]domain.PublicAd, 0, len(ads))
	for _, ad := range ads {
		public = append(public, domain.PublicAdFrom(ad))
	}
	return public
}

func adHasTopic(ad domain.Ad, topic string) bool {
	for _, target := range ad.Targets {
		for _, candidate := range target.Topics {
			if candidate == topic {
				return true
			}
		}
	}
	return false
}

func adTargetsAllTopics(ad domain.Ad) bool {
	for _, target := range ad.Targets {
		if len(target.Topics) == 0 {
			return true
		}
	}
	return false
}

func adTargetsTheme(ad domain.Ad, theme string) bool {
	for _, target := range ad.Targets {
		if target.Theme == theme {
			return true
		}
	}
	return false
}

func normalizeTargets(values []domain.AdTargetAdmin) []domain.AdTarget {
	byTheme := make(map[string][]string, len(values))
	for _, value := range values {
		theme := strings.TrimSpace(value.Theme)
		if theme == "" {
			continue
		}
		topics := normalizeStrings(value.Topics)
		current, ok := byTheme[theme]
		if !ok {
			byTheme[theme] = topics
			continue
		}
		if len(current) == 0 || len(topics) == 0 {
			byTheme[theme] = nil
			continue
		}
		byTheme[theme] = normalizeStrings(append(current, topics...))
	}

	targets := make([]domain.AdTarget, 0, len(byTheme))
	for theme, topics := range byTheme {
		targets = append(targets, domain.AdTarget{Theme: theme, Topics: topics})
	}
	sort.Slice(targets, func(i, j int) bool {
		return targets[i].Theme < targets[j].Theme
	})
	return targets
}

func normalizeStrings(values []string) []string {
	normalized := make([]string, 0, len(values))
	seen := make(map[string]bool, len(values))
	for _, value := range values {
		value = strings.TrimSpace(value)
		if value == "" || seen[value] {
			continue
		}
		seen[value] = true
		normalized = append(normalized, value)
	}
	return normalized
}

func normalizeDatetime(value string) (string, error) {
	if strings.TrimSpace(value) == "" {
		return "", nil
	}
	parsed, err := time.Parse(time.RFC3339, strings.TrimSpace(value))
	if err != nil {
		return "", err
	}
	return domain.FormatUTC(parsed), nil
}

func validHTTPURL(value string) bool {
	parsed, err := url.ParseRequestURI(value)
	if err != nil {
		return false
	}
	return (parsed.Scheme == "http" || parsed.Scheme == "https") && parsed.Host != ""
}

func shuffleAds(ads []domain.Ad) {
	for i := len(ads) - 1; i > 0; i-- {
		n, err := rand.Int(rand.Reader, big.NewInt(int64(i+1)))
		if err != nil {
			continue
		}
		j := int(n.Int64())
		ads[i], ads[j] = ads[j], ads[i]
	}
}

func newUUIDV4() (string, error) {
	bytes := make([]byte, 16)
	if _, err := rand.Read(bytes); err != nil {
		return "", err
	}
	bytes[6] = (bytes[6] & 0x0f) | 0x40
	bytes[8] = (bytes[8] & 0x3f) | 0x80

	encoded := make([]byte, 32)
	hex.Encode(encoded, bytes)
	return string(encoded[0:8]) + "-" +
		string(encoded[8:12]) + "-" +
		string(encoded[12:16]) + "-" +
		string(encoded[16:20]) + "-" +
		string(encoded[20:32]), nil
}
