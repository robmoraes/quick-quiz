package domain

import (
	"errors"
	"time"
)

var (
	ErrAdNotFound    = errors.New("ad not found")
	ErrThemeNotFound = errors.New("theme not found")
	ErrTopicNotFound = errors.New("topic not found")
)

type Theme struct {
	ID          string
	Name        string
	Description string
	Active      bool
}

type Topic struct {
	Key    string
	Name   string
	Active bool
}

type Ad struct {
	ID          string
	ProviderID  string
	URI         string
	Description string
	Image       string
	CreatedAt   string
	ExpiresIn   *time.Time
	Active      bool
	Emphasis    bool
	Targets     []AdTarget
}

type AdTarget struct {
	Theme  string
	Topics []string
}

type Ads struct {
	Theme    string     `json:"theme"`
	Ads      []PublicAd `json:"ads"`
	Emphasis []PublicAd `json:"emphasis,omitempty"`
}

type PublicAd struct {
	ID          string `json:"id"`
	ProviderID  string `json:"providerId,omitempty"`
	URI         string `json:"uri"`
	Description string `json:"description"`
	Image       string `json:"image"`
}

type AdminAd struct {
	ID          string          `json:"id"`
	ProviderID  string          `json:"provider_id"`
	URI         string          `json:"uri"`
	Description string          `json:"description"`
	Image       string          `json:"image"`
	CreatedAt   string          `json:"created_at"`
	ExpiresIn   *string         `json:"expires_in"`
	Active      bool            `json:"active"`
	Emphasis    bool            `json:"emphasis"`
	Themes      []AdTargetAdmin `json:"themes"`
}

type AdTargetAdmin struct {
	Theme  string   `json:"theme"`
	Topics []string `json:"topics"`
}

func PublicAdFrom(ad Ad) PublicAd {
	return PublicAd{
		ID:          ad.ID,
		ProviderID:  ad.ProviderID,
		URI:         ad.URI,
		Description: ad.Description,
		Image:       ad.Image,
	}
}

func AdminAdFrom(ad Ad) AdminAd {
	var expiresIn *string
	if ad.ExpiresIn != nil {
		formatted := FormatUTC(*ad.ExpiresIn)
		expiresIn = &formatted
	}

	targets := make([]AdTargetAdmin, 0, len(ad.Targets))
	for _, target := range ad.Targets {
		targets = append(targets, AdTargetAdmin{
			Theme:  target.Theme,
			Topics: append([]string(nil), target.Topics...),
		})
	}

	return AdminAd{
		ID:          ad.ID,
		ProviderID:  ad.ProviderID,
		URI:         ad.URI,
		Description: ad.Description,
		Image:       ad.Image,
		CreatedAt:   ad.CreatedAt,
		ExpiresIn:   expiresIn,
		Active:      ad.Active,
		Emphasis:    ad.Emphasis,
		Themes:      targets,
	}
}

func FormatUTC(value time.Time) string {
	return value.UTC().Format("2006-01-02T15:04:05") + "+00:00"
}
