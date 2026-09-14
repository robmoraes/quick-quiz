package store

import (
	"context"
	"fmt"
	"io"
	"sort"
	"strconv"
	"strings"
	"testing"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/aws/aws-sdk-go-v2/service/s3/types"
)

func TestLoadQuestionDatasetFromS3UsesConfiguredPrefixAndIgnoresDerivedContent(t *testing.T) {
	client := &fakeS3QuestionClient{
		pageSize: 2,
		objects: map[string]string{
			"questions/themes.json": `{
				"themes": [{
					"id": "dev",
					"name": "Development",
					"description": "Development quizzes",
					"weight": 100,
					"createdAt": "2026-01-01T00:00:00-03:00",
					"active": true
				}]
			}`,
			"questions/dev/index.json": `{
				"topics": [{
					"key": "php",
					"name": "General PHP",
					"description": "PHP fundamentals",
					"weight": 100,
					"created_at": "2026-01-01T00:00:00-03:00",
					"active": true
				}]
			}`,
			"questions/dev/en-US/index.json": `{
				"topics": [{
					"key": "php",
					"name": "Localized PHP"
				}]
			}`,
			"questions/dev/en-US/php/1/php-1-001.json": `{
				"prompt": "Fake question",
				"correctOptions": ["correct answer"],
				"wrongOptions": [
					"wrong answer 01", "wrong answer 02", "wrong answer 03", "wrong answer 04",
					"wrong answer 05", "wrong answer 06", "wrong answer 07", "wrong answer 08",
					"wrong answer 09", "wrong answer 10", "wrong answer 11", "wrong answer 12",
					"wrong answer 13", "wrong answer 14", "wrong answer 15", "wrong answer 16",
					"wrong answer 17", "wrong answer 18", "wrong answer 19", "wrong answer 20"
				]
			}`,
			"questions/dev/.solutions/en-US/php/1/php-1-001.json":   `{"explanation":"derived"}`,
			"questions/dev/ai-prompts/question-solution-prompt.txt": "ignored",
		},
	}

	dataset, err := loadQuestionDatasetFromS3WithClient(context.Background(), client, S3QuestionSourceConfig{
		Bucket: "question-bucket",
		Prefix: "/questions/",
	}, "en-US", []string{"en-US"})
	if err != nil {
		t.Fatalf("loadQuestionDatasetFromS3WithClient() error = %v", err)
	}

	if got := len(dataset.Questions); got != 1 {
		t.Fatalf("expected one question, got %d", got)
	}
	if dataset.Questions[0].ID != "php-1-001" {
		t.Fatalf("expected question id from object key, got %q", dataset.Questions[0].ID)
	}
	if got := dataset.Topics[0].Label; got != "Localized PHP" {
		t.Fatalf("expected localized topic label, got %q", got)
	}
	if client.listCalls < 2 {
		t.Fatalf("expected paginated listing, got %d call(s)", client.listCalls)
	}
	if client.wasRead("questions/dev/.solutions/en-US/php/1/php-1-001.json") {
		t.Fatal("derived solution object must not be loaded as question content")
	}
	if client.wasRead("questions/dev/ai-prompts/question-solution-prompt.txt") {
		t.Fatal("non-JSON prompt object must not be loaded as question content")
	}
}

func TestLoadQuestionDatasetFromS3RequiresBucket(t *testing.T) {
	client := &fakeS3QuestionClient{}

	_, err := loadQuestionDatasetFromS3WithClient(context.Background(), client, S3QuestionSourceConfig{}, "en-US", []string{"en-US"})
	if err == nil || !strings.Contains(err.Error(), "S3_BUCKET is required") {
		t.Fatalf("expected missing bucket error, got %v", err)
	}
	if client.listCalls != 0 {
		t.Fatalf("expected no S3 calls, got %d", client.listCalls)
	}
}

type fakeS3QuestionClient struct {
	objects   map[string]string
	pageSize  int
	listCalls int
	getCalls  []string
}

func (c *fakeS3QuestionClient) ListObjectsV2(_ context.Context, input *s3.ListObjectsV2Input, _ ...func(*s3.Options)) (*s3.ListObjectsV2Output, error) {
	c.listCalls++
	prefix := aws.ToString(input.Prefix)
	keys := make([]string, 0, len(c.objects))
	for key := range c.objects {
		if strings.HasPrefix(key, prefix) {
			keys = append(keys, key)
		}
	}
	sort.Strings(keys)

	start := 0
	if token := aws.ToString(input.ContinuationToken); token != "" {
		value, err := strconv.Atoi(token)
		if err != nil {
			return nil, fmt.Errorf("invalid continuation token %q", token)
		}
		start = value
	}

	end := len(keys)
	if c.pageSize > 0 && start+c.pageSize < end {
		end = start + c.pageSize
	}

	output := &s3.ListObjectsV2Output{}
	for _, key := range keys[start:end] {
		output.Contents = append(output.Contents, types.Object{Key: aws.String(key)})
	}
	if end < len(keys) {
		output.IsTruncated = aws.Bool(true)
		output.NextContinuationToken = aws.String(strconv.Itoa(end))
	}
	return output, nil
}

func (c *fakeS3QuestionClient) GetObject(_ context.Context, input *s3.GetObjectInput, _ ...func(*s3.Options)) (*s3.GetObjectOutput, error) {
	key := aws.ToString(input.Key)
	content, ok := c.objects[key]
	if !ok {
		return nil, fmt.Errorf("object %s not found", key)
	}
	c.getCalls = append(c.getCalls, key)
	return &s3.GetObjectOutput{Body: io.NopCloser(strings.NewReader(content))}, nil
}

func (c *fakeS3QuestionClient) wasRead(key string) bool {
	for _, readKey := range c.getCalls {
		if readKey == key {
			return true
		}
	}
	return false
}
