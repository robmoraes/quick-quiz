package store

import (
	"context"
	"fmt"
	"io"
	"io/fs"
	"path"
	"sort"
	"strings"

	"github.com/aws/aws-sdk-go-v2/aws"
	awsconfig "github.com/aws/aws-sdk-go-v2/config"
	"github.com/aws/aws-sdk-go-v2/service/s3"
)

type S3ContentSourceConfig struct {
	Region         string
	Bucket         string
	Prefix         string
	EndpointURL    string
	ForcePathStyle bool
}

type s3QuestionClient interface {
	ListObjectsV2(context.Context, *s3.ListObjectsV2Input, ...func(*s3.Options)) (*s3.ListObjectsV2Output, error)
	GetObject(context.Context, *s3.GetObjectInput, ...func(*s3.Options)) (*s3.GetObjectOutput, error)
}

func LoadQuestionDatasetFromS3WithFallback(ctx context.Context, config S3ContentSourceConfig, fallbackLocale string, locales []string) (QuestionDataset, error) {
	config.Bucket = strings.TrimSpace(config.Bucket)
	if config.Bucket == "" {
		return QuestionDataset{}, fmt.Errorf("S3_BUCKET is required when QUESTION_STORAGE_PROVIDER=s3")
	}

	client, err := newS3ContentClient(ctx, config)
	if err != nil {
		return QuestionDataset{}, err
	}

	return loadQuestionDatasetFromS3WithClient(ctx, client, config, fallbackLocale, locales)
}

func newS3ContentClient(ctx context.Context, config S3ContentSourceConfig) (*s3.Client, error) {
	loadOptions := make([]func(*awsconfig.LoadOptions) error, 0, 1)
	if region := strings.TrimSpace(config.Region); region != "" {
		loadOptions = append(loadOptions, awsconfig.WithRegion(region))
	}

	awsConfig, err := awsconfig.LoadDefaultConfig(ctx, loadOptions...)
	if err != nil {
		return nil, fmt.Errorf("load AWS configuration: %w", err)
	}

	return s3.NewFromConfig(awsConfig, func(options *s3.Options) {
		options.UsePathStyle = config.ForcePathStyle
		if endpoint := strings.TrimSpace(config.EndpointURL); endpoint != "" {
			options.BaseEndpoint = aws.String(endpoint)
		}
	}), nil
}

func loadQuestionDatasetFromS3WithClient(ctx context.Context, client s3QuestionClient, config S3ContentSourceConfig, fallbackLocale string, locales []string) (QuestionDataset, error) {
	bucket := strings.TrimSpace(config.Bucket)
	if bucket == "" {
		return QuestionDataset{}, fmt.Errorf("S3_BUCKET is required when QUESTION_STORAGE_PROVIDER=s3")
	}

	prefix := strings.Trim(strings.TrimSpace(config.Prefix), "/")
	objectPrefix := prefix
	if objectPrefix != "" {
		objectPrefix += "/"
	}

	files := make(map[string][]byte)
	input := &s3.ListObjectsV2Input{
		Bucket: aws.String(bucket),
		Prefix: aws.String(objectPrefix),
	}

	for {
		output, err := client.ListObjectsV2(ctx, input)
		if err != nil {
			return QuestionDataset{}, fmt.Errorf("list question objects in s3://%s/%s: %w", bucket, objectPrefix, err)
		}

		for _, object := range output.Contents {
			key := aws.ToString(object.Key)
			relativeName := strings.TrimPrefix(key, objectPrefix)
			if key == relativeName && objectPrefix != "" {
				continue
			}
			if !isQuestionContentObject(relativeName) {
				continue
			}

			result, err := client.GetObject(ctx, &s3.GetObjectInput{
				Bucket: aws.String(bucket),
				Key:    aws.String(key),
			})
			if err != nil {
				return QuestionDataset{}, fmt.Errorf("read question object s3://%s/%s: %w", bucket, key, err)
			}

			content, readErr := io.ReadAll(result.Body)
			closeErr := result.Body.Close()
			if readErr != nil {
				return QuestionDataset{}, fmt.Errorf("read question object body s3://%s/%s: %w", bucket, key, readErr)
			}
			if closeErr != nil {
				return QuestionDataset{}, fmt.Errorf("close question object body s3://%s/%s: %w", bucket, key, closeErr)
			}
			files[relativeName] = content
		}

		if !aws.ToBool(output.IsTruncated) {
			break
		}

		nextToken := strings.TrimSpace(aws.ToString(output.NextContinuationToken))
		if nextToken == "" {
			return QuestionDataset{}, fmt.Errorf("list question objects in s3://%s/%s: truncated response without continuation token", bucket, objectPrefix)
		}
		input.ContinuationToken = aws.String(nextToken)
	}

	return loadQuestionDatasetWithFallback(memoryQuestionFileSource{files: files}, fallbackLocale, locales)
}

func isQuestionContentObject(name string) bool {
	if !fs.ValidPath(name) || strings.Contains(name, `\`) {
		return false
	}

	parts := strings.Split(name, "/")
	switch len(parts) {
	case 1:
		return parts[0] == "themes.json"
	case 2:
		return parts[1] == "index.json"
	case 3:
		return parts[2] == "index.json" && parts[1] != ".solutions"
	case 5:
		return parts[1] != ".solutions" && path.Ext(parts[4]) == ".json"
	default:
		return false
	}
}

type memoryQuestionFileSource struct {
	files map[string][]byte
}

func (s memoryQuestionFileSource) ReadFile(name string) ([]byte, error) {
	content, ok := s.files[name]
	if !ok {
		return nil, &fs.PathError{Op: "read", Path: name, Err: fs.ErrNotExist}
	}
	return append([]byte(nil), content...), nil
}

func (s memoryQuestionFileSource) ReadDir(name string) ([]questionFileEntry, error) {
	directory := strings.Trim(path.Clean(name), "/")
	prefix := directory + "/"
	entries := make(map[string]questionFileEntry)

	for fileName := range s.files {
		if !strings.HasPrefix(fileName, prefix) {
			continue
		}

		remainder := strings.TrimPrefix(fileName, prefix)
		entryName, _, nested := strings.Cut(remainder, "/")
		if entryName == "" {
			continue
		}

		entry := questionFileEntry{name: entryName, isDir: nested}
		if existing, ok := entries[entryName]; !ok || entry.isDir && !existing.isDir {
			entries[entryName] = entry
		}
	}

	if len(entries) == 0 {
		return nil, &fs.PathError{Op: "readdir", Path: name, Err: fs.ErrNotExist}
	}

	result := make([]questionFileEntry, 0, len(entries))
	for _, entry := range entries {
		result = append(result, entry)
	}
	sort.Slice(result, func(i, j int) bool {
		return result[i].name < result[j].name
	})
	return result, nil
}
