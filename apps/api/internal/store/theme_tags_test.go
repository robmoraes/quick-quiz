package store

import (
	"context"
	"encoding/json"
	"path/filepath"
	"reflect"
	"testing"

	"quickquiz/api/internal/domain"
)

func TestThemeIndexAcceptsMissingTagsAndRejectsNoncanonicalSets(t *testing.T) {
	for _, test := range []struct {
		tags  string
		valid bool
	}{
		{"", true}, {`,"tags":[]`, true}, {`,"tags":["cloud","dev"]`, true},
		{`,"tags":null`, false}, {`,"tags":{}`, false}, {`,"tags":"dev"`, false},
		{`,"tags":["Dev"]`, false}, {`,"tags":["dev","cloud"]`, false},
		{`,"tags":["dev","dev"]`, false}, {`,"tags":[" dev "]`, false},
		{`,"tags":["dev\n"]`, false}, {`,"tags":["Kubernetes"]`, false},
	} {
		t.Run(test.tags, func(t *testing.T) {
			var index themeIndex
			err := json.Unmarshal([]byte(`{"themes":[{"id":"dev","active":true`+test.tags+`}]}`), &index)
			if (err == nil) != test.valid {
				t.Fatalf("error = %v, want valid %v", err, test.valid)
			}
		})
	}
}

func TestLoadedThemeTagsAreSnapshotCopiesUntilReload(t *testing.T) {
	root := t.TempDir()
	writeTestQuestionFile(t, root, "en-US", "php", "1", "php-1-001.json")
	writeTestCentralIndexFile(t, root)
	index := `{"themes":[{"id":"dev","name":"Dev","active":true,"tags":["dev"]}]}`
	writeTestFile(t, filepath.Join(root, "themes.json"), index)
	dataset, err := LoadQuestionDatasetFromRoot(root, []string{"en-US"})
	if err != nil {
		t.Fatal(err)
	}
	memory := NewMemoryQuestionStoreWithThemeMetadata(dataset.Questions, dataset.Topics, dataset.Themes)
	dataset.Themes[0].Tags[0] = "outside"
	ctx := context.Background()
	listed, _ := memory.ListThemes(ctx)
	listed[0].Tags[0] = "changed"
	item, _ := memory.Theme(ctx, "dev")
	item.Tags[0] = "also-changed"
	writeTestFile(t, filepath.Join(root, "themes.json"), `{"themes":[{"id":"dev","active":true,"tags":["study"]}]}`)
	listed, _ = memory.ListThemes(ctx)
	if !reflect.DeepEqual(listed[0].Tags, domain.ThemeTags{"dev"}) {
		t.Fatalf("loaded snapshot was modified: %#v", listed)
	}
	reloaded, err := LoadQuestionDatasetFromRoot(root, []string{"en-US"})
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(reloaded.Themes[0].Tags, domain.ThemeTags{"study"}) {
		t.Fatalf("reload did not read publication: %#v", reloaded.Themes)
	}
}
