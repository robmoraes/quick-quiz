package store

import (
	"context"
	"sync"

	"quickquiz/api/internal/domain"
)

type MemoryAdStore struct {
	mu  sync.RWMutex
	ads []domain.Ad
}

func NewMemoryAdStore(ads []domain.Ad) *MemoryAdStore {
	normalized := make([]domain.Ad, 0, len(ads))
	seen := make(map[string]bool, len(ads))
	for _, ad := range ads {
		if ad.ID == "" || ad.URI == "" || ad.Image == "" || ad.Description == "" || seen[ad.ID] {
			continue
		}
		seen[ad.ID] = true
		normalized = append(normalized, ad)
	}

	return &MemoryAdStore{ads: normalized}
}

func (s *MemoryAdStore) ListByTheme(_ context.Context, theme string) ([]domain.Ad, error) {
	s.mu.RLock()
	defer s.mu.RUnlock()

	ads := make([]domain.Ad, 0)
	for _, ad := range s.ads {
		if adHasTheme(ad, theme) {
			ads = append(ads, ad)
		}
	}
	return ads, nil
}

func adHasTheme(ad domain.Ad, theme string) bool {
	for _, candidate := range ad.Themes {
		if candidate == theme {
			return true
		}
	}
	return false
}
