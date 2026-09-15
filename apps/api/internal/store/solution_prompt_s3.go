package store

import (
	"context"
	"fmt"
	"io"
	"path"
	"strings"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"
)

type s3SolutionPromptClient interface {
	GetObject(context.Context, *s3.GetObjectInput, ...func(*s3.Options)) (*s3.GetObjectOutput, error)
}

type S3SolutionPromptSource struct {
	client s3SolutionPromptClient
	bucket string
	prefix string
}

func NewS3SolutionPromptSource(ctx context.Context, config S3ContentSourceConfig) (*S3SolutionPromptSource, error) {
	bucket := strings.TrimSpace(config.Bucket)
	if bucket == "" {
		return nil, fmt.Errorf("S3_BUCKET is required when QUESTION_STORAGE_PROVIDER=s3")
	}

	client, err := newS3ContentClient(ctx, config)
	if err != nil {
		return nil, err
	}
	return newS3SolutionPromptSource(client, bucket, config.Prefix), nil
}

func newS3SolutionPromptSource(client s3SolutionPromptClient, bucket, prefix string) *S3SolutionPromptSource {
	return &S3SolutionPromptSource{
		client: client,
		bucket: strings.TrimSpace(bucket),
		prefix: strings.Trim(strings.TrimSpace(prefix), "/"),
	}
}

func (s *S3SolutionPromptSource) Load(ctx context.Context, theme string) (string, error) {
	theme, err := cleanPathComponent(theme)
	if err != nil {
		return "", fmt.Errorf("invalid theme for solution prompt: %w", err)
	}

	key := path.Join(s.prefix, theme, "ai-prompts", "question-solution-prompt.txt")
	result, err := s.client.GetObject(ctx, &s3.GetObjectInput{
		Bucket: aws.String(s.bucket),
		Key:    aws.String(key),
	})
	if err != nil {
		return "", fmt.Errorf("read solution prompt s3://%s/%s: %w", s.bucket, key, err)
	}

	contents, readErr := io.ReadAll(result.Body)
	closeErr := result.Body.Close()
	if readErr != nil {
		return "", fmt.Errorf("read solution prompt body s3://%s/%s: %w", s.bucket, key, readErr)
	}
	if closeErr != nil {
		return "", fmt.Errorf("close solution prompt body s3://%s/%s: %w", s.bucket, key, closeErr)
	}
	return string(contents), nil
}
