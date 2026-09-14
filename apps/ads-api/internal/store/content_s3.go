package store

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"path"
	"strings"

	"github.com/aws/aws-sdk-go-v2/aws"
	awsconfig "github.com/aws/aws-sdk-go-v2/config"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/aws/smithy-go"

	"quickquiz/ads-api/internal/domain"
)

type S3ContentStoreConfig struct {
	Region         string
	Bucket         string
	Prefix         string
	EndpointURL    string
	ForcePathStyle bool
}

type s3ContentClient interface {
	HeadObject(context.Context, *s3.HeadObjectInput, ...func(*s3.Options)) (*s3.HeadObjectOutput, error)
	GetObject(context.Context, *s3.GetObjectInput, ...func(*s3.Options)) (*s3.GetObjectOutput, error)
	PutObject(context.Context, *s3.PutObjectInput, ...func(*s3.Options)) (*s3.PutObjectOutput, error)
}

type S3AdStore struct {
	*AdStore
}

type S3CatalogStore struct {
	client s3ContentClient
	bucket string
	prefix string
}

type s3AdIndexBackend struct {
	client s3ContentClient
	bucket string
	key    string
}

func NewS3Stores(ctx context.Context, config S3ContentStoreConfig) (*S3AdStore, *S3CatalogStore, error) {
	bucket := strings.TrimSpace(config.Bucket)
	if bucket == "" {
		return nil, nil, errors.New("S3_BUCKET is required when ADS_STORAGE_PROVIDER=s3")
	}

	loadOptions := make([]func(*awsconfig.LoadOptions) error, 0, 1)
	if region := strings.TrimSpace(config.Region); region != "" {
		loadOptions = append(loadOptions, awsconfig.WithRegion(region))
	}
	awsConfig, err := awsconfig.LoadDefaultConfig(ctx, loadOptions...)
	if err != nil {
		return nil, nil, fmt.Errorf("load AWS configuration: %w", err)
	}

	client := s3.NewFromConfig(awsConfig, func(options *s3.Options) {
		options.UsePathStyle = config.ForcePathStyle
		if endpoint := strings.TrimSpace(config.EndpointURL); endpoint != "" {
			options.BaseEndpoint = aws.String(endpoint)
		}
	})
	adStore, catalogStore := newS3Stores(client, bucket, config.Prefix)
	return adStore, catalogStore, nil
}

func newS3Stores(client s3ContentClient, bucket, prefix string) (*S3AdStore, *S3CatalogStore) {
	bucket = strings.TrimSpace(bucket)
	prefix = strings.Trim(strings.TrimSpace(prefix), "/")
	backend := &s3AdIndexBackend{
		client: client,
		bucket: bucket,
		key:    path.Join(prefix, "ads", "ads.json"),
	}
	return &S3AdStore{AdStore: newAdStore(backend)}, &S3CatalogStore{
		client: client,
		bucket: bucket,
		prefix: prefix,
	}
}

func (s *s3AdIndexBackend) exists(ctx context.Context) (bool, error) {
	_, err := s.client.HeadObject(ctx, &s3.HeadObjectInput{
		Bucket: aws.String(s.bucket),
		Key:    aws.String(s.key),
	})
	if err == nil {
		return true, nil
	}
	if isS3NotFound(err) {
		return false, nil
	}
	return false, fmt.Errorf("inspect ads object %s: %w", s.location(), err)
}

func (s *s3AdIndexBackend) readIndex(ctx context.Context) (adIndex, error) {
	payload, err := readS3Object(ctx, s.client, s.bucket, s.key)
	if err != nil {
		if errors.Is(err, errS3ObjectNotFound) {
			return adIndex{Ads: []adRecord{}}, nil
		}
		return adIndex{}, fmt.Errorf("read ads object %s: %w", s.location(), err)
	}

	var index adIndex
	if err := json.Unmarshal(payload, &index); err != nil {
		return adIndex{}, fmt.Errorf("decode ads object %s: %w", s.location(), err)
	}
	if index.Ads == nil {
		index.Ads = []adRecord{}
	}
	return index, nil
}

func (s *s3AdIndexBackend) writeIndex(ctx context.Context, index adIndex) error {
	payload, err := json.MarshalIndent(index, "", "  ")
	if err != nil {
		return fmt.Errorf("encode ads object %s: %w", s.location(), err)
	}
	payload = append(payload, '\n')

	_, err = s.client.PutObject(ctx, &s3.PutObjectInput{
		Bucket:      aws.String(s.bucket),
		Key:         aws.String(s.key),
		Body:        bytes.NewReader(payload),
		ContentType: aws.String("application/json"),
	})
	if err != nil {
		return fmt.Errorf("write ads object %s: %w", s.location(), err)
	}
	return nil
}

func (s *s3AdIndexBackend) location() string {
	return "s3://" + s.bucket + "/" + s.key
}

func (s *S3CatalogStore) Theme(ctx context.Context, theme string) (domain.Theme, error) {
	theme = strings.TrimSpace(theme)
	if theme == "" {
		return domain.Theme{}, domain.ErrThemeNotFound
	}

	key := path.Join(s.prefix, "themes.json")
	payload, err := readS3Object(ctx, s.client, s.bucket, key)
	if err != nil {
		if errors.Is(err, errS3ObjectNotFound) {
			return domain.Theme{}, domain.ErrThemeNotFound
		}
		return domain.Theme{}, fmt.Errorf("read theme index s3://%s/%s: %w", s.bucket, key, err)
	}

	var index themeIndex
	if err := json.Unmarshal(payload, &index); err != nil {
		return domain.Theme{}, fmt.Errorf("decode theme index s3://%s/%s: %w", s.bucket, key, err)
	}
	for _, candidate := range index.Themes {
		if strings.TrimSpace(candidate.ID) == theme {
			return domain.Theme{
				ID:          strings.TrimSpace(candidate.ID),
				Name:        strings.TrimSpace(candidate.Name),
				Description: strings.TrimSpace(candidate.Description),
				Active:      candidate.Active,
			}, nil
		}
	}
	return domain.Theme{}, domain.ErrThemeNotFound
}

func (s *S3CatalogStore) Topics(ctx context.Context, theme string) ([]domain.Topic, error) {
	key := path.Join(s.prefix, strings.TrimSpace(theme), "index.json")
	payload, err := readS3Object(ctx, s.client, s.bucket, key)
	if err != nil {
		if errors.Is(err, errS3ObjectNotFound) {
			return []domain.Topic{}, nil
		}
		return nil, fmt.Errorf("read topic index s3://%s/%s: %w", s.bucket, key, err)
	}

	var index topicIndex
	if err := json.Unmarshal(payload, &index); err != nil {
		return nil, fmt.Errorf("decode topic index s3://%s/%s: %w", s.bucket, key, err)
	}

	topics := make([]domain.Topic, 0, len(index.Topics))
	for _, topic := range index.Topics {
		key := strings.TrimSpace(topic.Key)
		if key == "" {
			continue
		}
		topics = append(topics, domain.Topic{
			Key:    key,
			Name:   strings.TrimSpace(topic.Name),
			Active: topic.Active,
		})
	}
	return topics, nil
}

var errS3ObjectNotFound = errors.New("s3 object not found")

func readS3Object(ctx context.Context, client s3ContentClient, bucket, key string) ([]byte, error) {
	output, err := client.GetObject(ctx, &s3.GetObjectInput{
		Bucket: aws.String(bucket),
		Key:    aws.String(key),
	})
	if err != nil {
		if isS3NotFound(err) {
			return nil, errS3ObjectNotFound
		}
		return nil, err
	}

	payload, readErr := io.ReadAll(output.Body)
	closeErr := output.Body.Close()
	if readErr != nil {
		return nil, readErr
	}
	if closeErr != nil {
		return nil, closeErr
	}
	return payload, nil
}

func isS3NotFound(err error) bool {
	var apiError smithy.APIError
	if !errors.As(err, &apiError) {
		return false
	}
	switch apiError.ErrorCode() {
	case "NoSuchKey", "NotFound", "404":
		return true
	default:
		return false
	}
}
