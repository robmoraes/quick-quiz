package app

import (
	"context"
	"errors"
	"testing"
	"time"

	"quickquiz/ads-api/internal/domain"
)

func TestPublicAdServiceListFiltersThemeTopicAndEmphasis(t *testing.T) {
	now := time.Date(2026, 6, 20, 12, 0, 0, 0, time.UTC)
	expired := now.Add(-time.Hour)
	store := &memoryAdRepository{ads: []domain.Ad{
		{
			ID:          "regular-topic",
			URI:         "https://example.com/topic",
			Description: "Topic ad",
			Image:       "https://example.com/topic.webp",
			Active:      true,
			Targets:     []domain.AdTarget{{Theme: "dev", Topics: []string{"php"}}},
		},
		{
			ID:          "regular-general",
			URI:         "https://example.com/general",
			Description: "General ad",
			Image:       "https://example.com/general.webp",
			Active:      true,
			Targets:     []domain.AdTarget{{Theme: "dev"}},
		},
		{
			ID:          "emphasis",
			URI:         "https://example.com/emphasis",
			Description: "Emphasis ad",
			Image:       "https://example.com/emphasis.webp",
			Active:      true,
			Emphasis:    true,
			Targets:     []domain.AdTarget{{Theme: "dev", Topics: []string{"php"}}},
		},
		{
			ID:          "expired",
			URI:         "https://example.com/expired",
			Description: "Expired ad",
			Image:       "https://example.com/expired.webp",
			Active:      true,
			ExpiresIn:   &expired,
			Targets:     []domain.AdTarget{{Theme: "dev"}},
		},
	}}
	service := NewPublicAdService(store, memoryCatalogRepository{
		themes: map[string]domain.Theme{"dev": {ID: "dev", Active: true}},
	})
	service.now = func() time.Time { return now }

	output, err := service.List(context.Background(), ListAdsInput{
		Theme:         "dev",
		Topic:         "php",
		Limit:         2,
		EmphasisLimit: 1,
	})
	if err != nil {
		t.Fatalf("List returned error: %v", err)
	}

	if got := len(output.Ads); got != 2 {
		t.Fatalf("expected two regular ads, got %d: %#v", got, output.Ads)
	}
	if got := len(output.Emphasis); got != 1 || output.Emphasis[0].ID != "emphasis" {
		t.Fatalf("expected emphasis ad, got %#v", output.Emphasis)
	}
}

func TestAdminAdServiceCreateNormalizesAndValidates(t *testing.T) {
	store := &memoryAdRepository{}
	service := NewAdminAdService(store, memoryCatalogRepository{
		themes: map[string]domain.Theme{"dev": {ID: "dev", Active: true}},
		topics: map[string][]domain.Topic{"dev": {
			{Key: "php", Active: true},
			{Key: "go", Active: true},
		}},
	})
	service.now = func() time.Time {
		return time.Date(2026, 6, 20, 12, 0, 0, 0, time.UTC)
	}

	ad, err := service.Create(context.Background(), domain.AdminAd{
		URI:         "https://example.com/ad",
		Description: "Example ad",
		Image:       "https://example.com/ad.webp",
		CreatedAt:   "2026-06-20T09:00:00-03:00",
		Active:      true,
		Themes: []domain.AdTargetAdmin{
			{Theme: "dev", Topics: []string{"php", "go", "php"}},
		},
	})
	if err != nil {
		t.Fatalf("Create returned error: %v", err)
	}

	if ad.ID == "" || ad.CreatedAt != "2026-06-20T12:00:00+00:00" {
		t.Fatalf("expected generated id and normalized created_at, got %#v", ad)
	}
	if got := ad.Themes[0].Topics; len(got) != 2 || got[0] != "php" || got[1] != "go" {
		t.Fatalf("expected deduplicated topics, got %#v", got)
	}
}

type memoryCatalogRepository struct {
	themes map[string]domain.Theme
	topics map[string][]domain.Topic
}

func (r memoryCatalogRepository) Theme(_ context.Context, theme string) (domain.Theme, error) {
	found, ok := r.themes[theme]
	if !ok {
		return domain.Theme{}, domain.ErrThemeNotFound
	}
	return found, nil
}

func (r memoryCatalogRepository) Topics(_ context.Context, theme string) ([]domain.Topic, error) {
	return r.topics[theme], nil
}

type memoryAdRepository struct {
	ads []domain.Ad
}

func (r *memoryAdRepository) Exists(context.Context) (bool, error) {
	return true, nil
}

func (r *memoryAdRepository) CreateBaseFile(context.Context) error {
	return nil
}

func (r *memoryAdRepository) List(context.Context) ([]domain.Ad, error) {
	return append([]domain.Ad(nil), r.ads...), nil
}

func (r *memoryAdRepository) ListByTheme(_ context.Context, theme string) ([]domain.Ad, error) {
	output := make([]domain.Ad, 0)
	for _, ad := range r.ads {
		for _, target := range ad.Targets {
			if target.Theme == theme {
				ad.Targets = []domain.AdTarget{target}
				output = append(output, ad)
			}
		}
	}
	return output, nil
}

func (r *memoryAdRepository) Ad(_ context.Context, id string) (domain.Ad, error) {
	for _, ad := range r.ads {
		if ad.ID == id {
			return ad, nil
		}
	}
	return domain.Ad{}, domain.ErrAdNotFound
}

func (r *memoryAdRepository) Create(_ context.Context, ad domain.Ad) error {
	r.ads = append(r.ads, ad)
	return nil
}

func (r *memoryAdRepository) Update(_ context.Context, id string, ad domain.Ad) error {
	for i, existing := range r.ads {
		if existing.ID == id {
			r.ads[i] = ad
			return nil
		}
	}
	return domain.ErrAdNotFound
}

func (r *memoryAdRepository) Delete(_ context.Context, id string) error {
	for i, ad := range r.ads {
		if ad.ID == id {
			r.ads = append(r.ads[:i], r.ads[i+1:]...)
			return nil
		}
	}
	return domain.ErrAdNotFound
}

func TestAdminAdServiceRejectsUnknownTopic(t *testing.T) {
	service := NewAdminAdService(&memoryAdRepository{}, memoryCatalogRepository{
		themes: map[string]domain.Theme{"dev": {ID: "dev", Active: true}},
		topics: map[string][]domain.Topic{"dev": {{Key: "php", Active: true}}},
	})

	_, err := service.Create(context.Background(), domain.AdminAd{
		URI:         "https://example.com/ad",
		Description: "Example ad",
		Image:       "https://example.com/ad.webp",
		Active:      true,
		Themes:      []domain.AdTargetAdmin{{Theme: "dev", Topics: []string{"missing"}}},
	})
	if !errors.Is(err, ErrInvalidInput) {
		t.Fatalf("expected invalid input, got %v", err)
	}
}
