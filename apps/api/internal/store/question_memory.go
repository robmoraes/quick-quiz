package store

import (
	"context"
	"fmt"
	"sort"
	"sync"

	"quickquiz/api/internal/domain"
)

type MemoryQuestionStore struct {
	mu        sync.RWMutex
	questions []domain.Question
	topics    []domain.TopicOption
	themes    []domain.Theme
}

func NewMemoryQuestionStore(questions []domain.Question) *MemoryQuestionStore {
	return NewMemoryQuestionStoreWithMetadata(questions, nil)
}

func NewMemoryQuestionStoreWithMetadata(questions []domain.Question, topics []domain.TopicOption) *MemoryQuestionStore {
	return NewMemoryQuestionStoreWithThemeMetadata(questions, topics, nil)
}

func NewMemoryQuestionStoreWithThemeMetadata(questions []domain.Question, topics []domain.TopicOption, themes []domain.Theme) *MemoryQuestionStore {
	valid := make([]domain.Question, 0, len(questions))
	seen := make(map[string]bool, len(questions))

	for _, question := range questions {
		questionKey := fmt.Sprintf("%s:%s:%s:%d:%s", question.Theme, question.Locale, question.Topic, question.Difficulty, question.ID)
		if seen[questionKey] || question.Validate() != nil {
			continue
		}
		seen[questionKey] = true
		valid = append(valid, question)
	}

	return &MemoryQuestionStore{
		questions: valid,
		topics: normalizeTopicOptions(
			topics,
			valid,
		),
		themes: normalizeThemes(themes, valid),
	}
}

func (s *MemoryQuestionStore) Theme(_ context.Context, themeID string) (domain.Theme, error) {
	s.mu.RLock()
	defer s.mu.RUnlock()

	for _, theme := range s.themes {
		if theme.ID == themeID {
			return theme, nil
		}
	}
	return domain.Theme{}, domain.ErrThemeNotFound
}

func (s *MemoryQuestionStore) ListByThemeLocaleTopicAndDifficulty(_ context.Context, theme, locale, topic string, difficulty domain.Difficulty) ([]domain.Question, error) {
	s.mu.RLock()
	defer s.mu.RUnlock()

	questions := make([]domain.Question, 0)
	for _, question := range s.questions {
		if question.Theme == theme && question.Locale == locale && question.Topic == topic && question.Difficulty == difficulty {
			questions = append(questions, question)
		}
	}

	return questions, nil
}

func (s *MemoryQuestionStore) ListMetadata(_ context.Context, theme, locale, fallbackLocale string) (domain.CatalogMetadata, error) {
	s.mu.RLock()
	defer s.mu.RUnlock()

	questionCounts := make(map[string]map[domain.Difficulty]int)
	globalQuestionCounts := make(map[domain.Difficulty]int)
	for _, question := range s.questions {
		if question.Theme != theme || question.Locale != locale {
			continue
		}
		if questionCounts[question.Topic] == nil {
			questionCounts[question.Topic] = make(map[domain.Difficulty]int)
		}
		questionCounts[question.Topic][question.Difficulty]++
		globalQuestionCounts[question.Difficulty]++
	}

	topicsByID := make(map[string]domain.TopicOption)
	for _, topic := range s.topics {
		if topic.Theme == theme && topic.Locale == locale {
			topicsByID[topic.ID] = topic
		}
	}
	if len(topicsByID) == 0 {
		for topicID := range questionCounts {
			topicsByID[topicID] = domain.TopicOption{
				Theme:  theme,
				Locale: locale,
				ID:     topicID,
				Label:  topicLabel(topicID),
			}
		}
	}

	topicIDs := make([]string, 0, len(topicsByID))
	for id := range topicsByID {
		topicIDs = append(topicIDs, id)
	}
	sort.Slice(topicIDs, func(i, j int) bool {
		left := topicsByID[topicIDs[i]]
		right := topicsByID[topicIDs[j]]
		if left.Weight != right.Weight {
			return left.Weight < right.Weight
		}
		if left.Label != right.Label {
			return left.Label < right.Label
		}
		return left.ID < right.ID
	})

	topics := make([]domain.TopicOption, 0, len(topicIDs))
	for _, id := range topicIDs {
		topic := topicsByID[id]
		topic.Difficulties = difficultiesWithQuestions(questionCounts[id])
		topics = append(topics, topic)
	}

	return domain.CatalogMetadata{
		Theme:          theme,
		Locale:         locale,
		FallbackLocale: fallbackLocale,
		Topics:         topics,
		Difficulties:   allDifficulties(globalQuestionCounts),
	}, nil
}

func normalizeTopicOptions(topics []domain.TopicOption, questions []domain.Question) []domain.TopicOption {
	if len(topics) == 0 {
		topics = topicOptionsFromQuestions(questions)
	}

	normalized := make([]domain.TopicOption, 0, len(topics))
	seen := make(map[string]bool, len(topics))
	for _, topic := range topics {
		if topic.Locale == "" || topic.ID == "" {
			continue
		}
		if topic.Theme == "" {
			topic.Theme = "dev"
		}
		if topic.Label == "" {
			topic.Label = topicLabel(topic.ID)
		}
		key := topic.Theme + ":" + topic.Locale + ":" + topic.ID
		if seen[key] {
			continue
		}
		seen[key] = true
		normalized = append(normalized, topic)
	}

	return normalized
}

func topicOptionsFromQuestions(questions []domain.Question) []domain.TopicOption {
	topicsByKey := make(map[string]domain.TopicOption)
	for _, question := range questions {
		key := question.Theme + ":" + question.Locale + ":" + question.Topic
		topicsByKey[key] = domain.TopicOption{
			Theme:  question.Theme,
			Locale: question.Locale,
			ID:     question.Topic,
			Label:  topicLabel(question.Topic),
		}
	}

	topics := make([]domain.TopicOption, 0, len(topicsByKey))
	for _, topic := range topicsByKey {
		topics = append(topics, topic)
	}

	return topics
}

func normalizeThemes(themes []domain.Theme, questions []domain.Question) []domain.Theme {
	if len(themes) == 0 {
		seen := make(map[string]bool)
		for _, question := range questions {
			if question.Theme == "" || seen[question.Theme] {
				continue
			}
			seen[question.Theme] = true
			themes = append(themes, domain.Theme{
				ID:     question.Theme,
				Name:   question.Theme,
				Active: true,
			})
		}
	}

	normalized := make([]domain.Theme, 0, len(themes))
	seen := make(map[string]bool, len(themes))
	for _, theme := range themes {
		if theme.ID == "" || seen[theme.ID] {
			continue
		}
		seen[theme.ID] = true
		if theme.Name == "" {
			theme.Name = theme.ID
		}
		normalized = append(normalized, theme)
	}
	return normalized
}

func allDifficulties(questionCounts map[domain.Difficulty]int) []domain.DifficultyInfo {
	return []domain.DifficultyInfo{
		difficultyInfo(domain.DifficultyEasy, questionCounts[domain.DifficultyEasy]),
		difficultyInfo(domain.DifficultyNormal, questionCounts[domain.DifficultyNormal]),
		difficultyInfo(domain.DifficultyHard, questionCounts[domain.DifficultyHard]),
		difficultyInfo(domain.DifficultyHardcore, questionCounts[domain.DifficultyHardcore]),
	}
}

func difficultiesWithQuestions(questionCounts map[domain.Difficulty]int) []domain.DifficultyInfo {
	difficulties := make([]domain.DifficultyInfo, 0, 4)
	for _, difficulty := range []domain.Difficulty{
		domain.DifficultyEasy,
		domain.DifficultyNormal,
		domain.DifficultyHard,
		domain.DifficultyHardcore,
	} {
		count := questionCounts[difficulty]
		if count == 0 {
			continue
		}
		difficulties = append(difficulties, difficultyInfo(difficulty, count))
	}

	return difficulties
}

func difficultyInfo(difficulty domain.Difficulty, questionCount int) domain.DifficultyInfo {
	return domain.DifficultyInfo{
		ID:            difficulty,
		OptionCount:   difficulty.OptionCount(),
		QuestionCount: questionCount,
		Hardcore:      difficulty == domain.DifficultyHardcore,
	}
}

func topicLabel(topic string) string {
	switch topic {
	case "go":
		return "Go"
	case "javascript":
		return "JavaScript"
	case "typescript":
		return "TypeScript"
	case "python":
		return "Python"
	case "php":
		return "PHP"
	default:
		return topic
	}
}
