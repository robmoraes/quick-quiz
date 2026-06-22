package app

import (
	"context"
	"errors"
	"testing"
	"time"

	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/store"
)

func TestAdServiceListFiltersEligibleAdsAndLimitsRandomSample(t *testing.T) {
	now := time.Date(2026, 6, 15, 12, 0, 0, 0, time.UTC)
	past := now.Add(-time.Hour)
	future := now.Add(time.Hour)
	adStore := store.NewMemoryAdStore([]domain.Ad{
		testAd("active-a", true, nil, "dev"),
		testAd("active-b", true, &future, "dev"),
		testAd("expired", true, &past, "dev"),
		testAd("inactive", false, nil, "dev"),
		testAd("other-theme", true, nil, "math"),
	})
	themeStore := store.NewMemoryQuestionStoreWithThemeMetadata(
		[]domain.Question{testThemedQuestion("go-easy-001", "dev", "en-US", "go", domain.DifficultyEasy)},
		nil,
		[]domain.Theme{{ID: "dev", Name: "Development", Active: true}},
	)
	service := NewAdService(adStore, themeStore)
	service.now = func() time.Time { return now }

	output, err := service.List(context.Background(), ListAdsInput{Theme: "dev", Limit: 1})
	if err != nil {
		t.Fatalf("List() error = %v", err)
	}

	if output.Theme != "dev" {
		t.Fatalf("expected dev theme, got %q", output.Theme)
	}
	if got := len(output.Ads); got != 1 {
		t.Fatalf("expected one ad due to limit, got %d", got)
	}
	if output.Ads[0].ID != "active-a" && output.Ads[0].ID != "active-b" {
		t.Fatalf("expected eligible active ad, got %#v", output.Ads[0])
	}
	if output.Ads[0].Active || output.Ads[0].ExpiresIn != nil || len(output.Ads[0].Targets) != 0 {
		t.Fatalf("expected public ad to hide control fields, got %#v", output.Ads[0])
	}
}

func TestAdServiceDefaultsAndCapsLimit(t *testing.T) {
	adStore := store.NewMemoryAdStore([]domain.Ad{
		testAd("active-a", true, nil, "dev"),
		testAd("active-b", true, nil, "dev"),
	})
	themeStore := store.NewMemoryQuestionStoreWithThemeMetadata(
		[]domain.Question{testThemedQuestion("go-easy-001", "dev", "en-US", "go", domain.DifficultyEasy)},
		nil,
		[]domain.Theme{{ID: "dev", Name: "Development", Active: true}},
	)
	service := NewAdService(adStore, themeStore)

	defaulted, err := service.List(context.Background(), ListAdsInput{Theme: "dev"})
	if err != nil {
		t.Fatalf("default List() error = %v", err)
	}
	if got := len(defaulted.Ads); got != DefaultAdLimit {
		t.Fatalf("expected default limit %d, got %d", DefaultAdLimit, got)
	}

	capped, err := service.List(context.Background(), ListAdsInput{Theme: "dev", Limit: MaxAdLimit + 1})
	if err != nil {
		t.Fatalf("capped List() error = %v", err)
	}
	if got := len(capped.Ads); got != 2 {
		t.Fatalf("expected all available ads below cap, got %d", got)
	}
}

func TestAdServiceReturnsEmphasisSeparatelyWhenRequested(t *testing.T) {
	emphasisAd := testAd("emphasis-a", true, nil, "dev")
	emphasisAd.Emphasis = true
	emphasisAdB := testAd("emphasis-b", true, nil, "dev")
	emphasisAdB.Emphasis = true
	adStore := store.NewMemoryAdStore([]domain.Ad{
		testAd("active-a", true, nil, "dev"),
		testAd("active-b", true, nil, "dev"),
		emphasisAd,
		emphasisAdB,
	})
	themeStore := store.NewMemoryQuestionStoreWithThemeMetadata(
		[]domain.Question{testThemedQuestion("go-easy-001", "dev", "en-US", "go", domain.DifficultyEasy)},
		nil,
		[]domain.Theme{{ID: "dev", Name: "Development", Active: true}},
	)
	service := NewAdService(adStore, themeStore)

	output, err := service.List(context.Background(), ListAdsInput{
		Theme:         "dev",
		Limit:         2,
		EmphasisLimit: 2,
	})
	if err != nil {
		t.Fatalf("List() error = %v", err)
	}

	if got := len(output.Emphasis); got != 2 {
		t.Fatalf("expected two emphasis ads, got %d: %#v", got, output.Emphasis)
	}
	emphasisIDs := map[string]bool{}
	for _, ad := range output.Emphasis {
		emphasisIDs[ad.ID] = true
	}
	if !emphasisIDs["emphasis-a"] || !emphasisIDs["emphasis-b"] {
		t.Fatalf("expected emphasis-a and emphasis-b, got %#v", output.Emphasis)
	}
	if got := len(output.Ads); got != 2 {
		t.Fatalf("expected two regular ads, got %d", got)
	}
	for _, ad := range output.Ads {
		if emphasisIDs[ad.ID] {
			t.Fatalf("emphasis ad should not be included in regular ads: %#v", output)
		}
	}
}

func TestAdServiceFiltersByRequestedTopicAndThemeWideAds(t *testing.T) {
	topicAd := testAd("topic-php", true, nil, "dev")
	topicAd.Targets[0].Topics = []string{"php"}
	otherTopicAd := testAd("topic-js", true, nil, "dev")
	otherTopicAd.Targets[0].Topics = []string{"js"}
	generalAd := testAd("general", true, nil, "dev")
	adStore := store.NewMemoryAdStore([]domain.Ad{
		otherTopicAd,
		generalAd,
		topicAd,
	})
	themeStore := store.NewMemoryQuestionStoreWithThemeMetadata(
		[]domain.Question{testThemedQuestion("go-easy-001", "dev", "en-US", "go", domain.DifficultyEasy)},
		nil,
		[]domain.Theme{{ID: "dev", Name: "Development", Active: true}},
	)
	service := NewAdService(adStore, themeStore)

	output, err := service.List(context.Background(), ListAdsInput{
		Theme: "dev",
		Topic: "php",
		Limit: 2,
	})
	if err != nil {
		t.Fatalf("List() error = %v", err)
	}

	if got := len(output.Ads); got != 2 {
		t.Fatalf("expected two ads, got %d", got)
	}
	if output.Ads[0].ID != "topic-php" {
		t.Fatalf("expected requested topic ad first, got %#v", output.Ads)
	}
	if output.Ads[1].ID != "general" {
		t.Fatalf("expected theme-wide ad to fill remaining slot, got %#v", output.Ads[1])
	}
	for _, ad := range output.Ads {
		if ad.ID == "topic-js" {
			t.Fatalf("expected unrelated topic ad to be filtered out, got %#v", output.Ads)
		}
	}
}

func TestAdServiceUsesTargetSpecificTopicsForRequestedTheme(t *testing.T) {
	multiThemeAd := testAd("multi-theme", true, nil, "dev")
	multiThemeAd.Targets = []domain.AdTarget{
		{Theme: "dev", Topics: []string{"php"}},
		{Theme: "dslab", Topics: []string{"route53"}},
	}
	dslabGeneralAd := testAd("dslab-general", true, nil, "dslab")
	adStore := store.NewMemoryAdStore([]domain.Ad{
		multiThemeAd,
		dslabGeneralAd,
	})
	themeStore := store.NewMemoryQuestionStoreWithThemeMetadata(
		[]domain.Question{testThemedQuestion("route53-easy-001", "dslab", "en-US", "route53", domain.DifficultyEasy)},
		nil,
		[]domain.Theme{{ID: "dslab", Name: "DSLab", Active: true}},
	)
	service := NewAdService(adStore, themeStore)

	output, err := service.List(context.Background(), ListAdsInput{
		Theme: "dslab",
		Topic: "route53",
		Limit: 1,
	})
	if err != nil {
		t.Fatalf("List() error = %v", err)
	}

	if got := output.Ads[0].ID; got != "multi-theme" {
		t.Fatalf("expected dslab route53 target to match, got %q", got)
	}
}

func TestAdServiceRequiresActiveTheme(t *testing.T) {
	service := NewAdService(store.NewMemoryAdStore(nil), store.NewMemoryQuestionStoreWithThemeMetadata(
		[]domain.Question{testThemedQuestion("go-easy-001", "dev", "en-US", "go", domain.DifficultyEasy)},
		nil,
		[]domain.Theme{{ID: "dev", Name: "Development", Active: false}},
	))

	_, err := service.List(context.Background(), ListAdsInput{Theme: "dev", Limit: 1})
	if !errors.Is(err, ErrThemeInactive) {
		t.Fatalf("expected ErrThemeInactive, got %v", err)
	}
}

func testAd(id string, active bool, expiresIn *time.Time, themes ...string) domain.Ad {
	return domain.Ad{
		ID:          id,
		ProviderID:  "provider-" + id,
		URI:         "https://example.com/" + id,
		Description: "Ad " + id,
		Image:       "https://example.com/" + id + ".webp",
		ExpiresIn:   expiresIn,
		Active:      active,
		Targets:     testTargets(themes...),
	}
}

func testTargets(themes ...string) []domain.AdTarget {
	targets := make([]domain.AdTarget, 0, len(themes))
	for _, theme := range themes {
		targets = append(targets, domain.AdTarget{Theme: theme})
	}
	return targets
}
