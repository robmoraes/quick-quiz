package store

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"math/big"
	"sort"

	"quickquiz/api/internal/domain"
)

func (s *MemoryQuestionStore) SetProvenanceProvider(provider string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.provider = provider
}

func (s *MemoryQuestionStore) ListThemesByTag(ctx context.Context, tag *string) ([]domain.Theme, error) {
	themes, err := s.ListThemes(ctx)
	if err != nil {
		return nil, err
	}
	result := make([]domain.Theme, 0, len(themes))
	for _, theme := range themes {
		if !theme.Active {
			continue
		}
		if tag != nil {
			found := false
			for _, candidate := range theme.Tags {
				found = found || candidate == *tag
			}
			if !found {
				continue
			}
		}
		result = append(result, theme)
	}
	sort.Slice(result, func(i, j int) bool {
		if result[i].Weight != result[j].Weight {
			return result[i].Weight < result[j].Weight
		}
		return result[i].ID < result[j].ID
	})
	return result, nil
}

func (s *MemoryQuestionStore) ReadCatalog(ctx context.Context, input domain.CatalogReadInput) (domain.CatalogReadResult, error) {
	if err := s.checkActiveTheme(ctx, input.Theme); err != nil {
		return domain.CatalogReadResult{}, err
	}
	metadata, err := s.ListMetadata(ctx, input.Theme, input.Locale, input.FallbackLocale)
	if err != nil {
		return domain.CatalogReadResult{}, err
	}
	if len(metadata.Topics) == 0 && input.Locale != input.FallbackLocale {
		metadata, err = s.ListMetadata(ctx, input.Theme, input.FallbackLocale, input.FallbackLocale)
		if err != nil {
			return domain.CatalogReadResult{}, err
		}
	}
	counts := make(map[string]map[domain.Difficulty]int)
	s.mu.RLock()
	defer s.mu.RUnlock()
	consumed := make(map[string]map[domain.Difficulty]map[string]bool)
	for topic, levels := range input.Consumption {
		consumed[topic] = make(map[domain.Difficulty]map[string]bool)
		for level, ids := range levels {
			consumed[topic][level] = make(map[string]bool, len(ids))
			for _, id := range ids {
				consumed[topic][level][id] = true
			}
		}
	}
	for _, question := range s.questions {
		if question.Theme != input.Theme || question.Locale != metadata.Locale {
			continue
		}
		if counts[question.Topic] == nil {
			counts[question.Topic] = make(map[domain.Difficulty]int)
		}
		if !consumed[question.Topic][question.Difficulty][question.ID] {
			counts[question.Topic][question.Difficulty]++
		}
	}
	return domain.CatalogReadResult{Metadata: metadata, AvailableCounts: counts}, nil
}

func (s *MemoryQuestionStore) SelectRunPackages(ctx context.Context, input domain.RunPackageSelection) (domain.SelectedRunPackages, error) {
	if err := s.checkActiveTheme(ctx, input.Theme); err != nil {
		return domain.SelectedRunPackages{}, err
	}
	s.mu.RLock()
	defer s.mu.RUnlock()
	locale := input.Locale
	available := s.availableLocked(input, locale)
	// Locale fallback is based on the existence of packages, not consumption.
	if !s.hasLevelLocked(input, locale) && locale != input.FallbackLocale {
		locale = input.FallbackLocale
		available = s.availableLocked(input, locale)
	}
	limit := input.Limit
	if limit > len(available) {
		limit = len(available)
	}
	if limit < 0 {
		limit = 0
	}
	result := domain.SelectedRunPackages{Locale: locale, Provider: s.provider, SourceDigest: s.sourceDigest, Packages: make([]domain.QuestionSnapshot, 0, limit)}
	// Legacy loaders already validate configured locale parity. Direct in-memory
	// fixtures may intentionally model a missing locale to exercise fallback.
	for i := 0; i < limit; i++ {
		n, err := rand.Int(rand.Reader, big.NewInt(int64(len(available)-i)))
		if err != nil {
			return domain.SelectedRunPackages{}, err
		}
		j := i + int(n.Int64())
		available[i], available[j] = available[j], available[i]
		selected := available[i]
		snapshot := domain.QuestionSnapshot{Key: domain.QuestionKey{Theme: input.Theme, Topic: input.Topic, Difficulty: input.Difficulty, QuestionID: selected.ID}, Translations: make(map[string]domain.Question)}
		for _, candidate := range s.questions {
			if candidate.Theme != input.Theme || candidate.Topic != input.Topic || candidate.Difficulty != input.Difficulty || candidate.ID != selected.ID {
				continue
			}
			for _, supported := range input.SupportedLocales {
				if candidate.Locale == supported {
					snapshot.Translations[supported] = cloneQuestion(candidate)
				}
			}
		}
		snapshot.Digest = domain.SnapshotDigest(snapshot)
		result.Packages = append(result.Packages, snapshot)
	}
	return result, nil
}

func (s *MemoryQuestionStore) datasetDigest() string {
	payload, _ := json.Marshal(struct {
		Questions []domain.Question
		Topics    []domain.TopicOption
		Themes    []domain.Theme
	}{s.questions, s.topics, s.themes})
	sum := sha256.Sum256(payload)
	return hex.EncodeToString(sum[:])
}

func (s *MemoryQuestionStore) LookupQuestion(ctx context.Context, input domain.QuestionLookup) (domain.Question, error) {
	if err := s.checkActiveTheme(ctx, input.Theme); err != nil {
		return domain.Question{}, err
	}
	s.mu.RLock()
	defer s.mu.RUnlock()
	for _, question := range s.questions {
		if question.Theme == input.Theme && question.Topic == input.Topic && question.Difficulty == input.Difficulty && question.ID == input.QuestionID && question.Locale == input.Locale {
			return cloneQuestion(question), nil
		}
	}
	return domain.Question{}, domain.ErrQuestionNotFound
}

func (s *MemoryQuestionStore) checkActiveTheme(ctx context.Context, theme string) error {
	metadata, err := s.Theme(ctx, theme)
	if err != nil {
		return err
	}
	if !metadata.Active {
		return domain.ErrThemeInactive
	}
	return nil
}

func (s *MemoryQuestionStore) availableLocked(input domain.RunPackageSelection, locale string) []domain.Question {
	result := make([]domain.Question, 0)
	for _, question := range s.questions {
		if question.Theme == input.Theme && question.Topic == input.Topic && question.Difficulty == input.Difficulty && question.Locale == locale && !input.UsedQuestionIDs[question.ID] {
			result = append(result, question)
		}
	}
	return result
}

func (s *MemoryQuestionStore) hasLevelLocked(input domain.RunPackageSelection, locale string) bool {
	for _, question := range s.questions {
		if question.Theme == input.Theme && question.Topic == input.Topic && question.Difficulty == input.Difficulty && question.Locale == locale {
			return true
		}
	}
	return false
}

func cloneQuestion(question domain.Question) domain.Question {
	question.CorrectOptions = append([]string(nil), question.CorrectOptions...)
	question.WrongOptions = append([]string(nil), question.WrongOptions...)
	return question
}
