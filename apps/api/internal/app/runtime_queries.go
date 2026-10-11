package app

import (
	"context"
	"errors"
	"fmt"

	"quickquiz/api/internal/domain"
)

func (s *RunService) selectPackages(ctx context.Context, input domain.RunPackageSelection) (domain.SelectedRunPackages, error) {
	if input.Limit <= 0 {
		return domain.SelectedRunPackages{}, ErrInvalidInput
	}
	if reader, ok := s.questions.(RuntimeQuestionReader); ok {
		selected, err := reader.SelectRunPackages(ctx, input)
		if err != nil {
			return domain.SelectedRunPackages{}, err
		}
		if err := validateSelection(selected, input); err != nil {
			return domain.SelectedRunPackages{}, err
		}
		return selected, nil
	}
	// Compatibility for small legacy adapters/test doubles. Real runtime stores
	// implement the intent reader; PostgreSQL must never enter this whole-list path.
	if err := validateTheme(ctx, s.questions, input.Theme); err != nil {
		return domain.SelectedRunPackages{}, err
	}
	locale, questions, err := s.questionsForDifficulty(ctx, input.Theme, input.Locale, input.Topic, input.Difficulty)
	if err != nil {
		return domain.SelectedRunPackages{}, err
	}
	available := make([]domain.Question, 0)
	for _, question := range questions {
		if !input.UsedQuestionIDs[question.ID] {
			available = append(available, question)
		}
	}
	selected := domain.SelectedRunPackages{Locale: locale, Provider: "legacy", Packages: make([]domain.QuestionSnapshot, 0)}
	for len(selected.Packages) < input.Limit && len(available) > 0 {
		index := randomInt(len(available))
		question := available[index]
		available = append(available[:index], available[index+1:]...)
		snapshot := domain.QuestionSnapshot{Key: domain.QuestionKey{Theme: input.Theme, Topic: input.Topic, Difficulty: input.Difficulty, QuestionID: question.ID}, Translations: map[string]domain.Question{locale: question}}
		for _, supported := range input.SupportedLocales {
			localized, err := s.questions.ListByThemeLocaleTopicAndDifficulty(ctx, input.Theme, supported, input.Topic, input.Difficulty)
			if err != nil {
				return domain.SelectedRunPackages{}, err
			}
			for _, candidate := range localized {
				if candidate.ID == question.ID {
					snapshot.Translations[supported] = candidate
					break
				}
			}
		}
		snapshot.Digest = domain.SnapshotDigest(snapshot)
		selected.Packages = append(selected.Packages, snapshot)
	}
	return selected, nil
}

func validateSelection(selected domain.SelectedRunPackages, input domain.RunPackageSelection) error {
	invalid := func() error { return fmt.Errorf("%w: invalid question selection", domain.ErrStorageUnavailable) }
	if len(selected.Packages) > input.Limit || (selected.Locale != input.Locale && selected.Locale != input.FallbackLocale) {
		return invalid()
	}
	if selected.Provider == "postgres" && selected.Revision == nil {
		return invalid()
	}
	seen := make(map[string]bool)
	for _, snapshot := range selected.Packages {
		key := snapshot.Key
		if key.Theme != input.Theme || key.Topic != input.Topic || key.Difficulty != input.Difficulty || key.QuestionID == "" || seen[key.QuestionID] || input.UsedQuestionIDs[key.QuestionID] {
			return invalid()
		}
		seen[key.QuestionID] = true
		if _, ok := snapshot.Translations[selected.Locale]; !ok {
			return invalid()
		}
		if selected.Provider == "postgres" {
			for _, locale := range input.SupportedLocales {
				if _, ok := snapshot.Translations[locale]; !ok {
					return invalid()
				}
			}
		}
		for locale, question := range snapshot.Translations {
			if question.Validate() != nil || question.Locale != locale || question.Theme != key.Theme || question.Topic != key.Topic || question.Difficulty != key.Difficulty || question.ID != key.QuestionID {
				return invalid()
			}
		}
		if snapshot.Digest != domain.SnapshotDigest(snapshot) {
			return invalid()
		}
	}
	return nil
}

func (s *RunService) sessionCatalog(ctx context.Context, session, theme, locale string) (domain.CatalogReadResult, error) {
	if theme == "" {
		return domain.CatalogReadResult{}, ErrThemeRequired
	}
	consumption, err := s.sessionConsumption(ctx, session, theme)
	if err != nil {
		return domain.CatalogReadResult{}, runStorageError(err)
	}
	resolved := s.locales.Resolve(locale, "")
	var result domain.CatalogReadResult
	if reader, ok := s.questions.(RuntimeQuestionReader); ok {
		result, err = reader.ReadCatalog(ctx, domain.CatalogReadInput{Theme: theme, Locale: resolved, FallbackLocale: s.locales.Fallback(), Consumption: consumption})
	} else {
		result.Metadata, err = s.Metadata(ctx, theme, resolved)
		if err == nil {
			result.AvailableCounts = make(map[string]map[domain.Difficulty]int)
			for _, topic := range result.Metadata.Topics {
				result.AvailableCounts[topic.ID] = make(map[domain.Difficulty]int)
				for _, level := range topic.Difficulties {
					questions, queryErr := s.questions.ListByThemeLocaleTopicAndDifficulty(ctx, theme, result.Metadata.Locale, topic.ID, level.ID)
					if queryErr != nil {
						return domain.CatalogReadResult{}, queryErr
					}
					used := make(map[string]bool)
					for _, id := range consumption[topic.ID][level.ID] {
						used[id] = true
					}
					result.AvailableCounts[topic.ID][level.ID] = countAvailableQuestions(questions, used)
				}
			}
		}
	}
	if errors.Is(err, ErrThemeNotFound) || errors.Is(err, ErrThemeInactive) {
		has, lookupErr := s.hasSession(ctx, session, theme)
		if lookupErr != nil {
			return domain.CatalogReadResult{}, runStorageError(lookupErr)
		}
		if has {
			return domain.CatalogReadResult{Metadata: domain.CatalogMetadata{Theme: theme, Locale: resolved, FallbackLocale: s.locales.Fallback(), Topics: []domain.TopicOption{}, Difficulties: []domain.DifficultyInfo{}}}, nil
		}
	}
	return result, err
}

func (s *RunService) sessionConsumption(ctx context.Context, session, theme string) (domain.QuestionConsumption, error) {
	if indexed, ok := s.runs.(IndexedRunRepository); ok {
		return indexed.ConsumedBySession(ctx, session, theme)
	}
	// Only legacy test doubles use this adapter; production Redis is indexed.
	runs, err := s.runs.ListTracked(ctx)
	if err != nil {
		return nil, err
	}
	result := make(domain.QuestionConsumption)
	for _, run := range runs {
		if run.SessionID != session || run.Theme != theme {
			continue
		}
		if result[run.Topic] == nil {
			result[run.Topic] = make(map[domain.Difficulty][]string)
		}
		for id, used := range run.UsedQuestionIDs {
			if used {
				result[run.Topic][run.Difficulty] = append(result[run.Topic][run.Difficulty], id)
			}
		}
	}
	return result, nil
}

func (s *RunService) hasSession(ctx context.Context, session, theme string) (bool, error) {
	if indexed, ok := s.runs.(IndexedRunRepository); ok {
		return indexed.HasSession(ctx, session, theme)
	}
	runs, err := s.runs.ListTracked(ctx)
	if err != nil {
		return false, err
	}
	for _, run := range runs {
		if run.SessionID == session && run.Theme == theme {
			return true, nil
		}
	}
	return false, nil
}

func catalogDifficulties(topic domain.TopicOption, available map[domain.Difficulty]int) []domain.SessionDifficultyAvailability {
	result := make([]domain.SessionDifficultyAvailability, 0, len(topic.Difficulties))
	for _, level := range topic.Difficulties {
		count := available[level.ID]
		result = append(result, domain.SessionDifficultyAvailability{ID: level.ID, OptionCount: level.OptionCount, QuestionCount: level.QuestionCount, AvailableQuestionCount: count, Available: count > 0, Hardcore: level.Hardcore})
	}
	return result
}
