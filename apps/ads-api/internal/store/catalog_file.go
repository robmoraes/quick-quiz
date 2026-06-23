package store

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"quickquiz/ads-api/internal/domain"
)

type FileCatalogStore struct {
	root string
}

func NewFileCatalogStore(root string) *FileCatalogStore {
	return &FileCatalogStore{root: root}
}

func (s *FileCatalogStore) Theme(_ context.Context, theme string) (domain.Theme, error) {
	theme = strings.TrimSpace(theme)
	if theme == "" {
		return domain.Theme{}, domain.ErrThemeNotFound
	}

	index, err := s.readThemes()
	if err != nil {
		return domain.Theme{}, err
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

func (s *FileCatalogStore) Topics(_ context.Context, theme string) ([]domain.Topic, error) {
	path := filepath.Join(s.root, strings.TrimSpace(theme), "index.json")
	bytes, err := os.ReadFile(path)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return []domain.Topic{}, nil
		}
		return nil, fmt.Errorf("read topic index %s: %w", path, err)
	}

	var index topicIndex
	if err := json.Unmarshal(bytes, &index); err != nil {
		return nil, fmt.Errorf("decode topic index %s: %w", path, err)
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

func (s *FileCatalogStore) readThemes() (themeIndex, error) {
	path := filepath.Join(s.root, "themes.json")
	bytes, err := os.ReadFile(path)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return themeIndex{}, nil
		}
		return themeIndex{}, fmt.Errorf("read theme index %s: %w", path, err)
	}

	var index themeIndex
	if err := json.Unmarshal(bytes, &index); err != nil {
		return themeIndex{}, fmt.Errorf("decode theme index %s: %w", path, err)
	}
	return index, nil
}

type themeIndex struct {
	Themes []themeRecord `json:"themes"`
}

type themeRecord struct {
	ID          string `json:"id"`
	Name        string `json:"name"`
	Description string `json:"description"`
	Active      bool   `json:"active"`
}

type topicIndex struct {
	Topics []topicRecord `json:"topics"`
}

type topicRecord struct {
	Key    string `json:"key"`
	Name   string `json:"name"`
	Active bool   `json:"active"`
}
