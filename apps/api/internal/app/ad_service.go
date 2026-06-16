package app

import (
	"context"
	"crypto/rand"
	"errors"
	"math/big"
	"strings"
	"time"

	"quickquiz/api/internal/domain"
)

const DefaultAdLimit = 1
const MaxAdLimit = 20

type AdRepository interface {
	ListByTheme(ctx context.Context, theme string) ([]domain.Ad, error)
}

type ThemeRepository interface {
	Theme(ctx context.Context, theme string) (domain.Theme, error)
}

type AdService struct {
	ads    AdRepository
	themes ThemeRepository
	now    func() time.Time
}

func NewAdService(ads AdRepository, themes ThemeRepository) *AdService {
	return &AdService{
		ads:    ads,
		themes: themes,
		now:    func() time.Time { return time.Now().UTC() },
	}
}

type ListAdsInput struct {
	Theme         string
	Topic         string
	Limit         int
	EmphasisLimit int
}

func (s *AdService) List(ctx context.Context, input ListAdsInput) (domain.Ads, error) {
	if err := validateTheme(ctx, s.themes, input.Theme); err != nil {
		return domain.Ads{}, err
	}

	limit := input.Limit
	if limit <= 0 {
		limit = DefaultAdLimit
	}
	if limit > MaxAdLimit {
		limit = MaxAdLimit
	}

	ads, err := s.ads.ListByTheme(ctx, input.Theme)
	if err != nil {
		return domain.Ads{}, err
	}

	now := s.now()
	emphasisLimit := input.EmphasisLimit
	if emphasisLimit < 0 {
		emphasisLimit = 0
	}
	if emphasisLimit > MaxAdLimit {
		emphasisLimit = MaxAdLimit
	}

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

	eligible = selectAds(eligible, limit, input.Topic)

	var emphasis []domain.Ad
	if emphasisLimit > 0 && len(emphasisEligible) > 0 {
		emphasis = selectAds(emphasisEligible, emphasisLimit, input.Topic)
	}

	return domain.Ads{
		Theme:    input.Theme,
		Ads:      eligible,
		Emphasis: emphasis,
	}, nil
}

func validateTheme(ctx context.Context, themes ThemeRepository, theme string) error {
	if theme == "" {
		return ErrThemeRequired
	}
	found, err := themes.Theme(ctx, theme)
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

func publicAd(ad domain.Ad) domain.Ad {
	ad.Active = false
	ad.Emphasis = false
	ad.Themes = nil
	ad.Topics = nil
	ad.ExpiresIn = nil
	ad.CreatedAt = ""
	return ad
}

func selectAds(ads []domain.Ad, limit int, topic string) []domain.Ad {
	topic = strings.TrimSpace(topic)
	if topic == "" {
		shuffleAds(ads)
		if len(ads) > limit {
			ads = ads[:limit]
		}
		return publicAds(ads)
	}

	matching := make([]domain.Ad, 0, len(ads))
	fallback := make([]domain.Ad, 0, len(ads))
	for _, ad := range ads {
		if adHasTopic(ad, topic) {
			matching = append(matching, ad)
			continue
		}
		fallback = append(fallback, ad)
	}

	shuffleAds(matching)
	shuffleAds(fallback)
	selected := make([]domain.Ad, 0, limit)
	selected = appendLimited(selected, matching, limit)
	if len(selected) < limit {
		selected = appendLimited(selected, fallback, limit)
	}

	return publicAds(selected)
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

func publicAds(ads []domain.Ad) []domain.Ad {
	public := make([]domain.Ad, 0, len(ads))
	for _, ad := range ads {
		public = append(public, publicAd(ad))
	}
	return public
}

func adHasTopic(ad domain.Ad, topic string) bool {
	for _, candidate := range ad.Topics {
		if candidate == topic {
			return true
		}
	}
	return false
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
