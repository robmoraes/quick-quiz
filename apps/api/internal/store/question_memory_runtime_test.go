package store

import (
	"context"
	"errors"
	"fmt"
	"testing"

	"quickquiz/api/internal/domain"
)

func TestMemoryRuntimeSelectionCapturesTranslationsAndDoesNotAliasContent(t *testing.T) {
	ctx := context.Background()
	questions := make([]domain.Question, 0, 16)
	for n := 0; n < 8; n++ {
		for _, locale := range []string{"en-US", "pt-BR"} {
			q := testStoreQuestion(fmt.Sprintf("q-%d", n), "go", 1)
			q.Locale = locale
			questions = append(questions, q)
		}
	}
	reader := NewMemoryQuestionStore(questions)
	reader.SetProvenanceProvider("s3")
	selected, err := reader.SelectRunPackages(ctx, domain.RunPackageSelection{Theme: "dev", Topic: "go", Locale: "pt-BR", FallbackLocale: "en-US", Difficulty: 1, Limit: 5, SupportedLocales: []string{"en-US", "pt-BR"}, UsedQuestionIDs: map[string]bool{"q-0": true}})
	if err != nil {
		t.Fatal(err)
	}
	if len(selected.Packages) != 5 || selected.Provider != "s3" || selected.Revision != nil || selected.SourceDigest == "" {
		t.Fatal("incorrect legacy snapshot size/provenance")
	}
	seen := make(map[string]bool)
	for _, snapshot := range selected.Packages {
		if snapshot.Key.QuestionID == "q-0" || seen[snapshot.Key.QuestionID] || len(snapshot.Translations) != 2 {
			t.Fatal("consumed/duplicate/missing translation in selection")
		}
		seen[snapshot.Key.QuestionID] = true
	}
	first := selected.Packages[0]
	q := first.Translations["en-US"]
	q.CorrectOptions[0] = "mutated returned alternative"
	lookup, err := reader.LookupQuestion(ctx, domain.QuestionLookup{QuestionKey: first.Key, Locale: "en-US"})
	if err != nil || lookup.CorrectOptions[0] != "correct answer" {
		t.Fatalf("returned snapshot modified source: %v", err)
	}
	questions[0].CorrectOptions[0] = "mutated caller input"
	lookup, err = reader.LookupQuestion(ctx, domain.QuestionLookup{QuestionKey: domain.QuestionKey{Theme: "dev", Topic: "go", Difficulty: 1, QuestionID: "q-0"}, Locale: "en-US"})
	if err != nil || lookup.CorrectOptions[0] != "correct answer" {
		t.Fatal("caller input aliases loaded catalog")
	}
}

func TestMemoryRuntimeCatalogCountsCanonicalConsumptionAcrossLocales(t *testing.T) {
	questions := []domain.Question{testStoreQuestion("q-1", "go", 1), testStoreQuestion("q-2", "go", 1), testStoreQuestion("q-3", "go", 2)}
	reader := NewMemoryQuestionStore(questions)
	catalog, err := reader.ReadCatalog(context.Background(), domain.CatalogReadInput{Theme: "dev", Locale: "pt-BR", FallbackLocale: "en-US", Consumption: domain.QuestionConsumption{"go": {1: {"q-1", "q-1", "absent-id"}}}})
	if err != nil {
		t.Fatal(err)
	}
	if catalog.Metadata.Locale != "en-US" || catalog.AvailableCounts["go"][1] != 1 || catalog.AvailableCounts["go"][2] != 1 || catalog.Metadata.Difficulties[0].QuestionCount != 2 {
		t.Fatalf("incorrect grouped/fallback counts: %+v", catalog)
	}
}

func TestMemoryRuntimeInactiveThemeRejectsNewReads(t *testing.T) {
	reader := NewMemoryQuestionStoreWithThemeMetadata(nil, nil, []domain.Theme{{ID: "draft", Active: false}})
	_, err := reader.ReadCatalog(context.Background(), domain.CatalogReadInput{Theme: "draft", Locale: "en-US", FallbackLocale: "en-US"})
	if !errors.Is(err, domain.ErrThemeInactive) {
		t.Fatalf("inactive theme became playable: %v", err)
	}
	_, err = reader.LookupQuestion(context.Background(), domain.QuestionLookup{QuestionKey: domain.QuestionKey{Theme: "missing", Topic: "go", Difficulty: 1, QuestionID: "q"}, Locale: "en-US"})
	if !errors.Is(err, domain.ErrThemeNotFound) {
		t.Fatalf("missing theme lost resource distinction: %v", err)
	}
}
