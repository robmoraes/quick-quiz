package store

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"quickquiz/ads-api/internal/domain"
)

type FileAdStore struct {
	root string
	mu   sync.Mutex
}

func NewFileAdStore(root string) *FileAdStore {
	return &FileAdStore{root: root}
}

func (s *FileAdStore) Exists(_ context.Context) (bool, error) {
	_, err := os.Stat(s.adsPath())
	if err == nil {
		return true, nil
	}
	if errors.Is(err, os.ErrNotExist) {
		return false, nil
	}
	return false, err
}

func (s *FileAdStore) CreateBaseFile(ctx context.Context) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	exists, err := s.Exists(ctx)
	if err != nil || exists {
		return err
	}
	return s.writeIndex(adIndex{Ads: []adRecord{}})
}

func (s *FileAdStore) List(_ context.Context) ([]domain.Ad, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	index, err := s.readIndex()
	if err != nil {
		return nil, err
	}
	return index.toDomain(s.adsPath())
}

func (s *FileAdStore) ListByTheme(_ context.Context, theme string) ([]domain.Ad, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	index, err := s.readIndex()
	if err != nil {
		return nil, err
	}
	ads, err := index.toDomain(s.adsPath())
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

func (s *FileAdStore) Ad(_ context.Context, id string) (domain.Ad, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	index, err := s.readIndex()
	if err != nil {
		return domain.Ad{}, err
	}
	ads, err := index.toDomain(s.adsPath())
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

func (s *FileAdStore) Create(_ context.Context, ad domain.Ad) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	index, err := s.readIndex()
	if err != nil {
		return err
	}
	for _, existing := range index.Ads {
		if strings.TrimSpace(existing.ID) == ad.ID {
			return fmt.Errorf("duplicate ad id %s", ad.ID)
		}
	}
	index.Ads = append(index.Ads, recordFromDomain(ad))
	return s.writeIndex(index)
}

func (s *FileAdStore) Update(_ context.Context, id string, ad domain.Ad) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	index, err := s.readIndex()
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
	return s.writeIndex(index)
}

func (s *FileAdStore) Delete(_ context.Context, id string) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	index, err := s.readIndex()
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
	return s.writeIndex(index)
}

type adIndex struct {
	Ads []adRecord `json:"ads"`
}

type adRecord struct {
	ID          string    `json:"id"`
	ProviderID  string    `json:"provider_id"`
	URI         string    `json:"uri"`
	Description string    `json:"description"`
	Image       string    `json:"image"`
	CreatedAt   string    `json:"created_at"`
	ExpiresIn   *string   `json:"expires_in"`
	Active      bool      `json:"active"`
	Emphasis    bool      `json:"emphasis"`
	Themes      adTargets `json:"themes"`
}

type adTargets []domain.AdTarget

func (t *adTargets) UnmarshalJSON(bytes []byte) error {
	trimmed := strings.TrimSpace(string(bytes))

	var list []struct {
		Theme  string   `json:"theme"`
		Topics []string `json:"topics"`
	}
	if strings.HasPrefix(trimmed, "[") {
		if err := json.Unmarshal(bytes, &list); err == nil {
			targets := make([]domain.AdTarget, 0, len(list))
			for _, item := range list {
				targets = append(targets, domain.AdTarget{
					Theme:  item.Theme,
					Topics: item.Topics,
				})
			}
			*t = targets
			return nil
		}
	}

	var single adTarget
	if err := json.Unmarshal(bytes, &single); err == nil {
		*t = adTargets(single.targets())
		return nil
	}

	return errors.New("themes must be an object, string, string array, or target array")
}

type adTarget struct {
	Theme  string
	Topics []string
}

func (t *adTarget) UnmarshalJSON(bytes []byte) error {
	trimmed := strings.TrimSpace(string(bytes))

	var structured struct {
		Theme  string   `json:"theme"`
		Topics []string `json:"topics"`
	}
	if strings.HasPrefix(trimmed, "{") {
		if err := json.Unmarshal(bytes, &structured); err != nil {
			return err
		}
		t.Theme = structured.Theme
		t.Topics = structured.Topics
		return nil
	}

	var legacy []string
	if err := json.Unmarshal(bytes, &legacy); err == nil {
		if len(legacy) > 0 {
			t.Theme = legacy[0]
		}
		return nil
	}

	var single string
	if err := json.Unmarshal(bytes, &single); err == nil {
		t.Theme = single
		return nil
	}

	return errors.New("themes must be an object, string, or string array")
}

func (t adTarget) targets() []domain.AdTarget {
	theme := strings.TrimSpace(t.Theme)
	if theme == "" {
		return nil
	}
	return []domain.AdTarget{{
		Theme:  theme,
		Topics: normalizeStrings(t.Topics),
	}}
}

func (i adIndex) toDomain(path string) ([]domain.Ad, error) {
	ads := make([]domain.Ad, 0, len(i.Ads))
	seen := make(map[string]bool, len(i.Ads))
	for _, record := range i.Ads {
		ad, err := record.toDomain()
		if err != nil {
			return nil, fmt.Errorf("invalid ad %s in %s: %w", record.ID, path, err)
		}
		if seen[ad.ID] {
			return nil, fmt.Errorf("duplicate ad id %s in %s", ad.ID, path)
		}
		seen[ad.ID] = true
		ads = append(ads, ad)
	}
	return ads, nil
}

func (a adRecord) toDomain() (domain.Ad, error) {
	id := strings.TrimSpace(a.ID)
	uri := strings.TrimSpace(a.URI)
	image := strings.TrimSpace(a.Image)
	description := strings.TrimSpace(a.Description)
	if id == "" || uri == "" || image == "" || description == "" {
		return domain.Ad{}, errors.New("id, uri, image, and description are required")
	}

	var expiresIn *time.Time
	if a.ExpiresIn != nil && strings.TrimSpace(*a.ExpiresIn) != "" {
		parsed, err := time.Parse(time.RFC3339, strings.TrimSpace(*a.ExpiresIn))
		if err != nil {
			return domain.Ad{}, fmt.Errorf("expires_in must be RFC3339: %w", err)
		}
		utc := parsed.UTC()
		expiresIn = &utc
	}

	return domain.Ad{
		ID:          id,
		ProviderID:  strings.TrimSpace(a.ProviderID),
		URI:         uri,
		Description: description,
		Image:       image,
		CreatedAt:   strings.TrimSpace(a.CreatedAt),
		ExpiresIn:   expiresIn,
		Active:      a.Active,
		Emphasis:    a.Emphasis,
		Targets:     normalizeAdTargets(a.Themes),
	}, nil
}

func recordFromDomain(ad domain.Ad) adRecord {
	var expiresIn *string
	if ad.ExpiresIn != nil {
		formatted := domain.FormatUTC(*ad.ExpiresIn)
		expiresIn = &formatted
	}

	return adRecord{
		ID:          ad.ID,
		ProviderID:  ad.ProviderID,
		URI:         ad.URI,
		Description: ad.Description,
		Image:       ad.Image,
		CreatedAt:   ad.CreatedAt,
		ExpiresIn:   expiresIn,
		Active:      ad.Active,
		Emphasis:    ad.Emphasis,
		Themes:      normalizeAdTargets(ad.Targets),
	}
}

func (s *FileAdStore) readIndex() (adIndex, error) {
	bytes, err := os.ReadFile(s.adsPath())
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return adIndex{Ads: []adRecord{}}, nil
		}
		return adIndex{}, fmt.Errorf("read ads index %s: %w", s.adsPath(), err)
	}

	var index adIndex
	if err := json.Unmarshal(bytes, &index); err != nil {
		return adIndex{}, fmt.Errorf("decode ads index %s: %w", s.adsPath(), err)
	}
	if index.Ads == nil {
		index.Ads = []adRecord{}
	}
	return index, nil
}

func (s *FileAdStore) writeIndex(index adIndex) error {
	if err := os.MkdirAll(filepath.Dir(s.adsPath()), 0o775); err != nil {
		return fmt.Errorf("create ads directory: %w", err)
	}

	bytes, err := json.MarshalIndent(index, "", "  ")
	if err != nil {
		return fmt.Errorf("encode ads index: %w", err)
	}
	bytes = append(bytes, '\n')

	tmp := s.adsPath() + ".tmp"
	if err := os.WriteFile(tmp, bytes, 0o664); err != nil {
		return fmt.Errorf("write ads index temp file: %w", err)
	}
	if err := os.Rename(tmp, s.adsPath()); err != nil {
		_ = os.Remove(tmp)
		return fmt.Errorf("replace ads index: %w", err)
	}
	return nil
}

func (s *FileAdStore) adsPath() string {
	return filepath.Join(s.root, "ads", "ads.json")
}

func adTargetForTheme(ad domain.Ad, theme string) (domain.AdTarget, bool) {
	for _, target := range ad.Targets {
		if target.Theme == theme {
			return target, true
		}
	}
	return domain.AdTarget{}, false
}

func normalizeAdTargets(targets []domain.AdTarget) []domain.AdTarget {
	normalized := make([]domain.AdTarget, 0, len(targets))
	seen := make(map[string]bool, len(targets))
	for _, target := range targets {
		theme := strings.TrimSpace(target.Theme)
		if theme == "" || seen[theme] {
			continue
		}
		seen[theme] = true
		normalized = append(normalized, domain.AdTarget{
			Theme:  theme,
			Topics: normalizeStrings(target.Topics),
		})
	}
	return normalized
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
