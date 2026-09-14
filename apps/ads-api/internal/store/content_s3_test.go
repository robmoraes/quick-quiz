package store

import (
	"context"
	"fmt"
	"io"
	"strings"
	"testing"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/aws/smithy-go"

	"quickquiz/ads-api/internal/domain"
)

func TestS3AdStorePreservesAdsJSONContract(t *testing.T) {
	client := &fakeS3ContentClient{objects: make(map[string]string)}
	adStore, _ := newS3Stores(client, "quickquiz-content", "/content/beta/")
	ctx := context.Background()

	exists, err := adStore.Exists(ctx)
	if err != nil {
		t.Fatalf("Exists() error = %v", err)
	}
	if exists {
		t.Fatal("expected ads object to be absent")
	}
	if err := adStore.CreateBaseFile(ctx); err != nil {
		t.Fatalf("CreateBaseFile() error = %v", err)
	}

	expiresIn := time.Date(2026, 7, 1, 12, 0, 0, 0, time.UTC)
	ad := domain.Ad{
		ID:          "bee135e0-dda7-4e12-87b8-1632126d546b",
		ProviderID:  "AD-1",
		URI:         "https://example.com/ad",
		Description: "Example ad",
		Image:       "https://example.com/ad.webp",
		CreatedAt:   "2026-06-20T12:00:00+00:00",
		ExpiresIn:   &expiresIn,
		Active:      true,
		Targets:     []domain.AdTarget{{Theme: "dev", Topics: []string{"go"}}},
	}
	if err := adStore.Create(ctx, ad); err != nil {
		t.Fatalf("Create() error = %v", err)
	}

	ads, err := adStore.ListByTheme(ctx, "dev")
	if err != nil {
		t.Fatalf("ListByTheme() error = %v", err)
	}
	if len(ads) != 1 || ads[0].ProviderID != "AD-1" {
		t.Fatalf("unexpected ads: %#v", ads)
	}

	ad.ProviderID = "AD-2"
	if err := adStore.Update(ctx, ad.ID, ad); err != nil {
		t.Fatalf("Update() error = %v", err)
	}
	stored, err := adStore.Ad(ctx, ad.ID)
	if err != nil {
		t.Fatalf("Ad() error = %v", err)
	}
	if stored.ProviderID != "AD-2" {
		t.Fatalf("expected updated provider id, got %#v", stored)
	}

	if err := adStore.Delete(ctx, ad.ID); err != nil {
		t.Fatalf("Delete() error = %v", err)
	}
	if _, err := adStore.Ad(ctx, ad.ID); err == nil {
		t.Fatal("expected deleted ad to be missing")
	}

	const objectKey = "content/beta/ads/ads.json"
	payload, ok := client.objects[objectKey]
	if !ok {
		t.Fatalf("expected object %q, got %#v", objectKey, client.objects)
	}
	if !strings.Contains(payload, `"ads": []`) {
		t.Fatalf("expected canonical empty ads document, got %s", payload)
	}
}

func TestS3CatalogStoreReadsThemeAndTopics(t *testing.T) {
	client := &fakeS3ContentClient{objects: map[string]string{
		"content/themes.json":    `{"themes":[{"id":"dev","name":"Development","description":"Programming","active":true}]}`,
		"content/dev/index.json": `{"topics":[{"key":"go","name":"Go","active":true}]}`,
	}}
	_, catalogStore := newS3Stores(client, "quickquiz-content", "content")
	ctx := context.Background()

	theme, err := catalogStore.Theme(ctx, "dev")
	if err != nil {
		t.Fatalf("Theme() error = %v", err)
	}
	if theme.ID != "dev" || !theme.Active {
		t.Fatalf("unexpected theme: %#v", theme)
	}

	topics, err := catalogStore.Topics(ctx, "dev")
	if err != nil {
		t.Fatalf("Topics() error = %v", err)
	}
	if len(topics) != 1 || topics[0].Key != "go" {
		t.Fatalf("unexpected topics: %#v", topics)
	}
}

type fakeS3ContentClient struct {
	objects map[string]string
}

func (c *fakeS3ContentClient) HeadObject(_ context.Context, input *s3.HeadObjectInput, _ ...func(*s3.Options)) (*s3.HeadObjectOutput, error) {
	key := aws.ToString(input.Key)
	if _, ok := c.objects[key]; !ok {
		return nil, &smithy.GenericAPIError{Code: "NotFound", Message: "not found"}
	}
	return &s3.HeadObjectOutput{}, nil
}

func (c *fakeS3ContentClient) GetObject(_ context.Context, input *s3.GetObjectInput, _ ...func(*s3.Options)) (*s3.GetObjectOutput, error) {
	key := aws.ToString(input.Key)
	payload, ok := c.objects[key]
	if !ok {
		return nil, &smithy.GenericAPIError{Code: "NoSuchKey", Message: "not found"}
	}
	return &s3.GetObjectOutput{Body: io.NopCloser(strings.NewReader(payload))}, nil
}

func (c *fakeS3ContentClient) PutObject(_ context.Context, input *s3.PutObjectInput, _ ...func(*s3.Options)) (*s3.PutObjectOutput, error) {
	payload, err := io.ReadAll(input.Body)
	if err != nil {
		return nil, fmt.Errorf("read body: %w", err)
	}
	c.objects[aws.ToString(input.Key)] = string(payload)
	return &s3.PutObjectOutput{}, nil
}
