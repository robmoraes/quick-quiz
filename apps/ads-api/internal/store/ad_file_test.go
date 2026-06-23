package store

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
	"time"

	"quickquiz/ads-api/internal/domain"
)

func TestFileAdStoreReadsLegacyTargetsAndWritesCanonicalTargets(t *testing.T) {
	root := t.TempDir()
	writeTestFile(t, filepath.Join(root, "ads", "ads.json"), `{
		"ads": [
			{
				"id": "bee135e0-dda7-4e12-87b8-1632126d546b",
				"provider_id": "AD-1",
				"uri": "https://example.com/ad",
				"description": "Example ad",
				"image": "https://example.com/ad.webp",
				"created_at": "2026-06-20T12:00:00+00:00",
				"expires_in": null,
				"active": true,
				"emphasis": true,
				"themes": ["dev"]
			}
		]
	}`)
	store := NewFileAdStore(root)

	ads, err := store.ListByTheme(context.Background(), "dev")
	if err != nil {
		t.Fatalf("ListByTheme returned error: %v", err)
	}
	if got := len(ads); got != 1 || ads[0].ProviderID != "AD-1" {
		t.Fatalf("expected legacy ad, got %#v", ads)
	}

	expiresIn := time.Date(2026, 6, 21, 12, 0, 0, 0, time.UTC)
	if err := store.Update(context.Background(), ads[0].ID, domain.Ad{
		ID:          ads[0].ID,
		ProviderID:  "AD-2",
		URI:         "https://example.com/updated",
		Description: "Updated ad",
		Image:       "https://example.com/updated.webp",
		CreatedAt:   "2026-06-20T12:00:00+00:00",
		ExpiresIn:   &expiresIn,
		Active:      true,
		Targets:     []domain.AdTarget{{Theme: "dev", Topics: []string{"php"}}},
	}); err != nil {
		t.Fatalf("Update returned error: %v", err)
	}

	var payload struct {
		Ads []struct {
			ProviderID string `json:"provider_id"`
			ExpiresIn  string `json:"expires_in"`
			Themes     []struct {
				Theme  string   `json:"theme"`
				Topics []string `json:"topics"`
			} `json:"themes"`
		} `json:"ads"`
	}
	bytes, err := os.ReadFile(filepath.Join(root, "ads", "ads.json"))
	if err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(bytes, &payload); err != nil {
		t.Fatal(err)
	}

	if payload.Ads[0].ProviderID != "AD-2" || payload.Ads[0].ExpiresIn != "2026-06-21T12:00:00+00:00" {
		t.Fatalf("expected canonical updated ad, got %#v", payload.Ads[0])
	}
	if got := payload.Ads[0].Themes; len(got) != 1 || got[0].Theme != "dev" || got[0].Topics[0] != "php" {
		t.Fatalf("expected canonical theme target, got %#v", got)
	}
}

func writeTestFile(t *testing.T, path string, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}
