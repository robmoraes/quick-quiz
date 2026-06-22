package store

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"quickquiz/api/internal/domain"
)

type adIndex struct {
	Ads []adIndexAd `json:"ads"`
}

type adIndexAd struct {
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
	var list []struct {
		Theme  string   `json:"theme"`
		Topics []string `json:"topics"`
	}
	if err := json.Unmarshal(bytes, &list); err == nil && strings.HasPrefix(strings.TrimSpace(string(bytes)), "[") {
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
	var structured struct {
		Theme  string   `json:"theme"`
		Topics []string `json:"topics"`
	}
	if err := json.Unmarshal(bytes, &structured); err == nil && strings.HasPrefix(strings.TrimSpace(string(bytes)), "{") {
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

func LoadAdsFromRoot(root string) ([]domain.Ad, error) {
	path := filepath.Join(root, "ads", "ads.json")
	bytes, err := os.ReadFile(path)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return nil, nil
		}
		return nil, fmt.Errorf("read ads index %s: %w", path, err)
	}

	var index adIndex
	if err := json.Unmarshal(bytes, &index); err != nil {
		return nil, fmt.Errorf("decode ads index %s: %w", path, err)
	}

	ads := make([]domain.Ad, 0, len(index.Ads))
	seen := make(map[string]bool, len(index.Ads))
	for _, indexed := range index.Ads {
		ad, err := indexed.toDomain()
		if err != nil {
			return nil, fmt.Errorf("invalid ad %s in %s: %w", indexed.ID, path, err)
		}
		if seen[ad.ID] {
			return nil, fmt.Errorf("duplicate ad id %s in %s", ad.ID, path)
		}
		seen[ad.ID] = true
		ads = append(ads, ad)
	}

	return ads, nil
}

func (a adIndexAd) toDomain() (domain.Ad, error) {
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
