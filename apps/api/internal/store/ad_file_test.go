package store

import (
	"os"
	"path/filepath"
	"testing"
)

func TestLoadAdsFromRootReadsAdsIndex(t *testing.T) {
	root := t.TempDir()
	writeTestFile(t, filepath.Join(root, "ads", "ads.json"), `{
		"ads": [
			{
				"id": "bee135e0-dda7-4e12-87b8-1632126d546b",
				"provider_id": "RNP49P-JU9A",
				"uri": "https://example.com/ad",
				"description": "Example ad",
				"image": "https://example.com/ad.webp",
				"created_at": "2026-05-15T22:04:00Z",
				"expires_in": "2026-06-15T22:04:00Z",
				"active": true,
				"emphasis": true,
				"themes": {
					"theme": "dev",
					"topics": ["php", "js", "php"]
				}
			}
		]
	}`)

	ads, err := LoadAdsFromRoot(root)
	if err != nil {
		t.Fatalf("LoadAdsFromRoot() error = %v", err)
	}

	if got := len(ads); got != 1 {
		t.Fatalf("expected one ad, got %d", got)
	}
	if ads[0].ID != "bee135e0-dda7-4e12-87b8-1632126d546b" || ads[0].ProviderID != "RNP49P-JU9A" {
		t.Fatalf("expected identifiers from ads index, got %#v", ads[0])
	}
	if ads[0].ExpiresIn == nil {
		t.Fatal("expected parsed expiration")
	}
	if !ads[0].Emphasis {
		t.Fatal("expected emphasis flag")
	}
	if got := ads[0].Targets; len(got) != 1 || got[0].Theme != "dev" {
		t.Fatalf("expected dev theme target, got %#v", got)
	}
	if got := ads[0].Targets[0].Topics; len(got) != 2 || got[0] != "php" || got[1] != "js" {
		t.Fatalf("expected php and js topics, got %#v", got)
	}
}

func TestLoadAdsFromRootReadsMultipleThemeTargets(t *testing.T) {
	root := t.TempDir()
	writeTestFile(t, filepath.Join(root, "ads", "ads.json"), `{
		"ads": [
			{
				"id": "bee135e0-dda7-4e12-87b8-1632126d546b",
				"uri": "https://example.com/ad",
				"description": "Example ad",
				"image": "https://example.com/ad.webp",
				"active": true,
				"themes": [
					{"theme": "dev", "topics": ["php"]},
					{"theme": "dslab", "topics": ["route53", "swarm"]}
				]
			}
		]
	}`)

	ads, err := LoadAdsFromRoot(root)
	if err != nil {
		t.Fatalf("LoadAdsFromRoot() error = %v", err)
	}

	if got := len(ads[0].Targets); got != 2 {
		t.Fatalf("expected two targets, got %#v", ads[0].Targets)
	}
	if ads[0].Targets[0].Theme != "dev" || ads[0].Targets[0].Topics[0] != "php" {
		t.Fatalf("expected dev/php target, got %#v", ads[0].Targets[0])
	}
	if ads[0].Targets[1].Theme != "dslab" || len(ads[0].Targets[1].Topics) != 2 {
		t.Fatalf("expected dslab target with two topics, got %#v", ads[0].Targets[1])
	}
}

func TestLoadAdsFromRootReadsLegacyThemes(t *testing.T) {
	root := t.TempDir()
	writeTestFile(t, filepath.Join(root, "ads", "ads.json"), `{
		"ads": [
			{
				"id": "bee135e0-dda7-4e12-87b8-1632126d546b",
				"uri": "https://example.com/ad",
				"description": "Example ad",
				"image": "https://example.com/ad.webp",
				"active": true,
				"themes": ["dev"]
			}
		]
	}`)

	ads, err := LoadAdsFromRoot(root)
	if err != nil {
		t.Fatalf("LoadAdsFromRoot() error = %v", err)
	}

	if got := ads[0].Targets; len(got) != 1 || got[0].Theme != "dev" {
		t.Fatalf("expected dev theme target, got %#v", got)
	}
	if len(ads[0].Targets[0].Topics) != 0 {
		t.Fatalf("expected no topics for legacy themes, got %#v", ads[0].Targets[0].Topics)
	}
}

func TestLoadAdsFromRootAllowsMissingAdsFile(t *testing.T) {
	ads, err := LoadAdsFromRoot(t.TempDir())
	if err != nil {
		t.Fatalf("LoadAdsFromRoot() error = %v", err)
	}
	if len(ads) != 0 {
		t.Fatalf("expected no ads, got %#v", ads)
	}
}

func TestLoadAdsFromRootRejectsDuplicateAdID(t *testing.T) {
	root := t.TempDir()
	content := `{
		"ads": [
			{
				"id": "bee135e0-dda7-4e12-87b8-1632126d546b",
				"uri": "https://example.com/ad-a",
				"description": "Example ad A",
				"image": "https://example.com/ad-a.webp",
				"active": true,
				"themes": ["dev"]
			},
			{
				"id": "bee135e0-dda7-4e12-87b8-1632126d546b",
				"uri": "https://example.com/ad-b",
				"description": "Example ad B",
				"image": "https://example.com/ad-b.webp",
				"active": true,
				"themes": ["dev"]
			}
		]
	}`
	if err := os.MkdirAll(filepath.Join(root, "ads"), 0o755); err != nil {
		t.Fatalf("MkdirAll() error = %v", err)
	}
	if err := os.WriteFile(filepath.Join(root, "ads", "ads.json"), []byte(content), 0o644); err != nil {
		t.Fatalf("WriteFile() error = %v", err)
	}

	if _, err := LoadAdsFromRoot(root); err == nil {
		t.Fatal("expected duplicate ad id to fail")
	}
}
