package store

import (
	"context"
	"fmt"
	"strings"
	"sync"

	"quickquiz/ads-api/internal/domain"
)

type adIndexBackend interface {
	exists(context.Context) (bool, error)
	readIndex(context.Context) (adIndex, error)
	writeIndex(context.Context, adIndex) error
	location() string
}

type AdStore struct {
	backend adIndexBackend
	mu      sync.Mutex
}

func newAdStore(backend adIndexBackend) *AdStore {
	return &AdStore{backend: backend}
}

func (s *AdStore) Exists(ctx context.Context) (bool, error) {
	return s.backend.exists(ctx)
}

func (s *AdStore) CreateBaseFile(ctx context.Context) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	exists, err := s.backend.exists(ctx)
	if err != nil || exists {
		return err
	}
	return s.backend.writeIndex(ctx, adIndex{Ads: []adRecord{}})
}

func (s *AdStore) List(ctx context.Context) ([]domain.Ad, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	index, err := s.backend.readIndex(ctx)
	if err != nil {
		return nil, err
	}
	return index.toDomain(s.backend.location())
}

func (s *AdStore) ListByTheme(ctx context.Context, theme string) ([]domain.Ad, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	index, err := s.backend.readIndex(ctx)
	if err != nil {
		return nil, err
	}
	ads, err := index.toDomain(s.backend.location())
	if err != nil {
		return nil, err
	}

	output := make([]domain.Ad, 0)
	for _, ad := range ads {
		if target, ok := adTargetForTheme(ad, theme); ok {
			ad.Targets = []domain.AdTarget{target}
			output = append(output, ad)
		}
	}
	return output, nil
}

func (s *AdStore) Ad(ctx context.Context, id string) (domain.Ad, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	index, err := s.backend.readIndex(ctx)
	if err != nil {
		return domain.Ad{}, err
	}
	ads, err := index.toDomain(s.backend.location())
	if err != nil {
		return domain.Ad{}, err
	}
	for _, ad := range ads {
		if ad.ID == id {
			return ad, nil
		}
	}
	return domain.Ad{}, domain.ErrAdNotFound
}

func (s *AdStore) Create(ctx context.Context, ad domain.Ad) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	index, err := s.backend.readIndex(ctx)
	if err != nil {
		return err
	}
	for _, existing := range index.Ads {
		if strings.TrimSpace(existing.ID) == ad.ID {
			return fmt.Errorf("duplicate ad id %s", ad.ID)
		}
	}
	index.Ads = append(index.Ads, recordFromDomain(ad))
	return s.backend.writeIndex(ctx, index)
}

func (s *AdStore) Update(ctx context.Context, id string, ad domain.Ad) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	index, err := s.backend.readIndex(ctx)
	if err != nil {
		return err
	}
	found := false
	for i, existing := range index.Ads {
		if strings.TrimSpace(existing.ID) != id {
			continue
		}
		index.Ads[i] = recordFromDomain(ad)
		found = true
		break
	}
	if !found {
		return domain.ErrAdNotFound
	}
	return s.backend.writeIndex(ctx, index)
}

func (s *AdStore) Delete(ctx context.Context, id string) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	index, err := s.backend.readIndex(ctx)
	if err != nil {
		return err
	}
	originalCount := len(index.Ads)
	filtered := index.Ads[:0]
	for _, ad := range index.Ads {
		if strings.TrimSpace(ad.ID) != id {
			filtered = append(filtered, ad)
		}
	}
	if len(filtered) == originalCount {
		return domain.ErrAdNotFound
	}
	index.Ads = filtered
	return s.backend.writeIndex(ctx, index)
}
