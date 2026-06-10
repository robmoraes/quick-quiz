package store

import (
	"context"
	"testing"

	"quickquiz/api/internal/domain"
)

func TestMemoryQuestionStoreMetadataIncludesTopicDetailsAndQuestionCounts(t *testing.T) {
	questionStore := NewMemoryQuestionStoreWithMetadata(
		[]domain.Question{
			testStoreQuestion("go-1-001", "go", domain.DifficultyEasy),
			testStoreQuestion("php-1-001", "php", domain.DifficultyEasy),
			testStoreQuestion("php-2-001", "php", domain.DifficultyNormal),
		},
		[]domain.TopicOption{
			{
				Theme:       "dev",
				Locale:      "en-US",
				ID:          "go",
				Label:       "General Go",
				Description: "Version-agnostic Go topic fundamentals",
				Weight:      200,
				CreatedAt:   "2026-01-01T00:00:00-03:00",
			},
			{
				Theme:       "dev",
				Locale:      "en-US",
				ID:          "php",
				Label:       "General PHP",
				Description: "Version-agnostic PHP topic fundamentals",
				Weight:      100,
				CreatedAt:   "2026-01-01T00:00:00-03:00",
			},
		},
	)

	metadata, err := questionStore.ListMetadata(context.Background(), "dev", "en-US", "en-US")
	if err != nil {
		t.Fatalf("ListMetadata() error = %v", err)
	}

	if got := len(metadata.Topics); got != 2 {
		t.Fatalf("expected two topics, got %d", got)
	}
	if metadata.Topics[0].ID != "php" {
		t.Fatalf("expected topics sorted by weight, got %#v", metadata.Topics)
	}
	if metadata.Topics[0].Difficulties[0].QuestionCount != 1 {
		t.Fatalf("expected PHP easy question count 1, got %d", metadata.Topics[0].Difficulties[0].QuestionCount)
	}
	if metadata.Topics[0].Difficulties[1].ID != domain.DifficultyNormal {
		t.Fatalf("expected PHP normal difficulty, got %#v", metadata.Topics[0].Difficulties)
	}
	if metadata.Difficulties[0].QuestionCount != 2 {
		t.Fatalf("expected global easy question count 2, got %d", metadata.Difficulties[0].QuestionCount)
	}
}

func testStoreQuestion(id, topic string, difficulty domain.Difficulty) domain.Question {
	return domain.Question{
		ID:             id,
		Theme:          "dev",
		Locale:         "en-US",
		Topic:          topic,
		Difficulty:     difficulty,
		Prompt:         "Fake question",
		CorrectOptions: []string{"correct answer"},
		WrongOptions: []string{
			"wrong answer 01",
			"wrong answer 02",
			"wrong answer 03",
			"wrong answer 04",
			"wrong answer 05",
			"wrong answer 06",
			"wrong answer 07",
			"wrong answer 08",
			"wrong answer 09",
			"wrong answer 10",
			"wrong answer 11",
			"wrong answer 12",
			"wrong answer 13",
			"wrong answer 14",
			"wrong answer 15",
			"wrong answer 16",
			"wrong answer 17",
			"wrong answer 18",
			"wrong answer 19",
			"wrong answer 20",
		},
	}
}
