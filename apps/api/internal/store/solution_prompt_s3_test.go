package store

import (
	"context"
	"testing"
)

func TestS3SolutionPromptSourceLoadsConfiguredObject(t *testing.T) {
	client := &fakeS3QuestionClient{objects: map[string]string{
		"questions/dev/ai-prompts/question-solution-prompt.txt": "S3 prompt.",
	}}
	source := newS3SolutionPromptSource(client, "quickquiz-content", "/questions/")

	prompt, err := source.Load(context.Background(), "dev")
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}
	if prompt != "S3 prompt." {
		t.Fatalf("unexpected prompt: %q", prompt)
	}
	if !client.wasRead("questions/dev/ai-prompts/question-solution-prompt.txt") {
		t.Fatal("expected configured S3 prompt object to be read")
	}
}

func TestS3SolutionPromptSourceRejectsUnsafeTheme(t *testing.T) {
	source := newS3SolutionPromptSource(&fakeS3QuestionClient{}, "quickquiz-content", "questions")
	if _, err := source.Load(context.Background(), "../dev"); err == nil {
		t.Fatal("expected unsafe theme to fail")
	}
}

func TestNewS3SolutionPromptSourceRequiresBucket(t *testing.T) {
	if _, err := NewS3SolutionPromptSource(context.Background(), S3ContentSourceConfig{}); err == nil {
		t.Fatal("expected missing S3 bucket to fail")
	}
}
