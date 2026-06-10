package store

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"quickquiz/api/internal/domain"
	"quickquiz/api/internal/i18n"
)

type QuestionDataset struct {
	Questions []domain.Question
	Topics    []domain.TopicOption
	Themes    []domain.Theme
}

type themeIndex struct {
	Themes []themeIndexTheme `json:"themes"`
}

type themeIndexTheme struct {
	ID          string `json:"id"`
	Name        string `json:"name"`
	Description string `json:"description"`
	Weight      int    `json:"weight"`
	CreatedAt   string `json:"createdAt"`
	Active      bool   `json:"active"`
}

type questionIndex struct {
	Topics []questionIndexTopic `json:"topics"`
}

type questionIndexTopic struct {
	Key         string `json:"key"`
	Name        string `json:"name"`
	Description string `json:"description"`
	Weight      int    `json:"weight"`
	CreatedAt   string `json:"created_at"`
	Active      bool   `json:"active"`
}

func LoadQuestionDatasetFromRoot(root string, locales []string) (QuestionDataset, error) {
	fallbackLocale := ""
	for _, locale := range locales {
		fallbackLocale = i18n.NormalizeLocale(locale)
		if fallbackLocale != "" {
			break
		}
	}
	return LoadQuestionDatasetFromRootWithFallback(root, fallbackLocale, locales)
}

func LoadQuestionDatasetFromRootWithFallback(root, fallbackLocale string, locales []string) (QuestionDataset, error) {
	var dataset QuestionDataset
	fallbackLocale = i18n.NormalizeLocale(fallbackLocale)
	if fallbackLocale == "" {
		return QuestionDataset{}, errors.New("fallback locale is required")
	}

	themes, err := loadThemeIndex(filepath.Join(root, "themes.json"))
	if err != nil {
		return QuestionDataset{}, err
	}
	if err := validateThemeIndex(themes, filepath.Join(root, "themes.json")); err != nil {
		return QuestionDataset{}, err
	}
	dataset.Themes = themeIndexToDomain(themes)

	for _, theme := range dataset.Themes {
		if !theme.Active {
			continue
		}

		loaded, err := loadQuestionDatasetFromTheme(root, theme.ID, fallbackLocale, locales)
		if err != nil {
			return QuestionDataset{}, err
		}
		dataset.Questions = append(dataset.Questions, loaded.Questions...)
		dataset.Topics = append(dataset.Topics, loaded.Topics...)
	}

	if len(dataset.Questions) == 0 {
		return QuestionDataset{}, errors.New("no questions loaded")
	}

	return dataset, nil
}

func LoadQuestionsFromRoot(root string, locales []string) ([]domain.Question, error) {
	dataset, err := LoadQuestionDatasetFromRoot(root, locales)
	if err != nil {
		return nil, err
	}
	return dataset.Questions, nil
}

func loadQuestionDatasetFromTheme(root, theme, fallbackLocale string, locales []string) (QuestionDataset, error) {
	themeRoot := filepath.Join(root, theme)
	centralIndex, err := loadQuestionIndex(filepath.Join(themeRoot, "index.json"))
	if err != nil {
		return QuestionDataset{}, err
	}
	if err := validateCentralQuestionIndex(centralIndex, filepath.Join(themeRoot, "index.json")); err != nil {
		return QuestionDataset{}, err
	}

	var dataset QuestionDataset
	loadedByLocale := make(map[string]QuestionDataset)
	for _, locale := range normalizeQuestionLocales(fallbackLocale, locales) {
		if locale == "" {
			continue
		}

		loaded, err := loadQuestionDatasetFromLocale(themeRoot, theme, locale, centralIndex)
		if err != nil {
			return QuestionDataset{}, err
		}
		loadedByLocale[locale] = loaded
		dataset.Questions = append(dataset.Questions, loaded.Questions...)
		dataset.Topics = append(dataset.Topics, loaded.Topics...)
	}

	if err := validateLocalizedQuestionPackages(loadedByLocale, fallbackLocale); err != nil {
		return QuestionDataset{}, err
	}

	return dataset, nil
}

func loadQuestionDatasetFromLocale(themeRoot, theme, locale string, centralIndex questionIndex) (QuestionDataset, error) {
	localeDir := filepath.Join(themeRoot, locale)
	localizedIndex, err := loadLocalizedQuestionIndex(filepath.Join(localeDir, "index.json"))
	if err != nil {
		return QuestionDataset{}, err
	}
	if err := validateLocalizedQuestionIndex(localizedIndex, centralIndex, filepath.Join(localeDir, "index.json")); err != nil {
		return QuestionDataset{}, err
	}

	localizedByKey := questionIndexTopicsByKey(localizedIndex.Topics)

	var dataset QuestionDataset
	for _, indexedTopic := range centralIndex.Topics {
		if !indexedTopic.Active {
			continue
		}

		topic := indexedTopic.mergeLocalized(localizedByKey[indexedTopic.normalizedKey()]).toTopicOption(theme, locale)
		if topic.ID == "" {
			return QuestionDataset{}, fmt.Errorf("invalid topic key in %s", filepath.Join(themeRoot, "index.json"))
		}

		dataset.Topics = append(dataset.Topics, topic)

		questions, err := loadQuestionsForTopic(localeDir, theme, locale, topic.ID)
		if err != nil {
			return QuestionDataset{}, err
		}
		dataset.Questions = append(dataset.Questions, questions...)
	}

	return dataset, nil
}

func normalizeQuestionLocales(fallbackLocale string, locales []string) []string {
	normalized := make([]string, 0, len(locales)+1)
	seen := make(map[string]bool, len(locales)+1)

	if fallbackLocale != "" {
		normalized = append(normalized, fallbackLocale)
		seen[fallbackLocale] = true
	}

	for _, locale := range locales {
		locale = i18n.NormalizeLocale(locale)
		if locale == "" || seen[locale] {
			continue
		}
		seen[locale] = true
		normalized = append(normalized, locale)
	}

	return normalized
}

func validateLocalizedQuestionPackages(datasets map[string]QuestionDataset, fallbackLocale string) error {
	fallback, ok := datasets[fallbackLocale]
	if !ok {
		return fmt.Errorf("fallback locale %s questions not loaded", fallbackLocale)
	}

	canonicalTopics := topicPackageKeys(fallback.Topics)
	canonicalQuestions := questionPackageKeys(fallback.Questions)
	for locale, dataset := range datasets {
		if locale == fallbackLocale {
			continue
		}

		localizedTopics := topicPackageKeys(dataset.Topics)
		for key := range canonicalTopics {
			if !localizedTopics[key] {
				return fmt.Errorf("locale %s is missing fallback topic package %s", locale, key)
			}
		}
		for key := range localizedTopics {
			if !canonicalTopics[key] {
				return fmt.Errorf("locale %s has topic package not defined by fallback: %s", locale, key)
			}
		}

		localizedQuestions := questionPackageKeys(dataset.Questions)
		for key := range canonicalQuestions {
			if !localizedQuestions[key] {
				return fmt.Errorf("locale %s is missing fallback question package %s", locale, key)
			}
		}
		for key := range localizedQuestions {
			if !canonicalQuestions[key] {
				return fmt.Errorf("locale %s has question package not defined by fallback: %s", locale, key)
			}
		}
	}

	return nil
}

func loadThemeIndex(path string) (themeIndex, error) {
	bytes, err := os.ReadFile(path)
	if err != nil {
		return themeIndex{}, fmt.Errorf("read theme index %s: %w", path, err)
	}

	var index themeIndex
	if err := json.Unmarshal(bytes, &index); err != nil {
		return themeIndex{}, fmt.Errorf("decode theme index %s: %w", path, err)
	}

	return index, nil
}

func validateThemeIndex(index themeIndex, path string) error {
	seen := make(map[string]bool, len(index.Themes))
	for _, theme := range index.Themes {
		id := strings.TrimSpace(theme.ID)
		if id == "" {
			return fmt.Errorf("invalid theme id in %s", path)
		}
		if seen[id] {
			return fmt.Errorf("duplicate theme id %s in %s", id, path)
		}
		seen[id] = true
	}
	if len(seen) == 0 {
		return fmt.Errorf("no themes defined in %s", path)
	}
	return nil
}

func themeIndexToDomain(index themeIndex) []domain.Theme {
	themes := make([]domain.Theme, 0, len(index.Themes))
	for _, theme := range index.Themes {
		id := strings.TrimSpace(theme.ID)
		if id == "" {
			continue
		}
		themes = append(themes, domain.Theme{
			ID:          id,
			Name:        strings.TrimSpace(theme.Name),
			Description: strings.TrimSpace(theme.Description),
			Weight:      theme.Weight,
			CreatedAt:   strings.TrimSpace(theme.CreatedAt),
			Active:      theme.Active,
		})
	}
	return themes
}

func topicPackageKeys(topics []domain.TopicOption) map[string]bool {
	keys := make(map[string]bool, len(topics))
	for _, topic := range topics {
		keys[topic.ID] = true
	}
	return keys
}

func questionPackageKeys(questions []domain.Question) map[string]bool {
	keys := make(map[string]bool, len(questions))
	for _, question := range questions {
		keys[questionPackageKey(question)] = true
	}
	return keys
}

func questionPackageKey(question domain.Question) string {
	return fmt.Sprintf("%s/%d/%s", question.Topic, question.Difficulty, question.ID)
}

func loadQuestionIndex(path string) (questionIndex, error) {
	bytes, err := os.ReadFile(path)
	if err != nil {
		return questionIndex{}, fmt.Errorf("read question index %s: %w", path, err)
	}

	var index questionIndex
	if err := json.Unmarshal(bytes, &index); err != nil {
		return questionIndex{}, fmt.Errorf("decode question index %s: %w", path, err)
	}

	return index, nil
}

func loadLocalizedQuestionIndex(path string) (questionIndex, error) {
	index, err := loadQuestionIndex(path)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return questionIndex{}, nil
		}
		return questionIndex{}, err
	}
	return index, nil
}

func validateCentralQuestionIndex(index questionIndex, path string) error {
	seen := make(map[string]bool, len(index.Topics))
	for _, topic := range index.Topics {
		key := topic.normalizedKey()
		if key == "" {
			return fmt.Errorf("invalid topic key in %s", path)
		}
		if seen[key] {
			return fmt.Errorf("duplicate topic key %s in %s", key, path)
		}
		seen[key] = true
	}
	return nil
}

func validateLocalizedQuestionIndex(localized, central questionIndex, path string) error {
	centralByKey := questionIndexTopicsByKey(central.Topics)
	seen := make(map[string]bool, len(localized.Topics))
	for _, topic := range localized.Topics {
		key := topic.normalizedKey()
		if key == "" {
			return fmt.Errorf("invalid topic key in %s", path)
		}
		if seen[key] {
			return fmt.Errorf("duplicate topic key %s in %s", key, path)
		}
		seen[key] = true
		if _, ok := centralByKey[key]; !ok {
			return fmt.Errorf("localized topic key %s in %s is not defined in central index", key, path)
		}
	}
	return nil
}

func questionIndexTopicsByKey(topics []questionIndexTopic) map[string]questionIndexTopic {
	byKey := make(map[string]questionIndexTopic, len(topics))
	for _, topic := range topics {
		key := topic.normalizedKey()
		if key != "" {
			byKey[key] = topic
		}
	}
	return byKey
}

func (t questionIndexTopic) normalizedKey() string {
	return strings.TrimSpace(t.Key)
}

func (t questionIndexTopic) mergeLocalized(localized questionIndexTopic) questionIndexTopic {
	if strings.TrimSpace(localized.Name) != "" {
		t.Name = localized.Name
	}
	if strings.TrimSpace(localized.Description) != "" {
		t.Description = localized.Description
	}
	return t
}

func (t questionIndexTopic) toTopicOption(theme, locale string) domain.TopicOption {
	id := strings.TrimSpace(t.Key)
	label := strings.TrimSpace(t.Name)
	if label == "" {
		label = topicLabel(id)
	}

	return domain.TopicOption{
		Theme:       theme,
		Locale:      locale,
		ID:          id,
		Label:       label,
		Description: strings.TrimSpace(t.Description),
		Weight:      t.Weight,
		CreatedAt:   strings.TrimSpace(t.CreatedAt),
	}
}

func loadQuestionsForTopic(localeDir, theme, locale, topic string) ([]domain.Question, error) {
	topicDir := filepath.Join(localeDir, topic)
	entries, err := os.ReadDir(topicDir)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return nil, nil
		}
		return nil, fmt.Errorf("read topic directory %s: %w", topicDir, err)
	}

	var questions []domain.Question
	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}

		difficulty, err := difficultyFromDirectory(entry.Name())
		if err != nil {
			return nil, fmt.Errorf("invalid difficulty directory %s: %w", filepath.Join(topicDir, entry.Name()), err)
		}

		loaded, err := loadQuestionsFromDifficultyDir(filepath.Join(topicDir, entry.Name()), theme, locale, topic, difficulty)
		if err != nil {
			return nil, err
		}
		questions = append(questions, loaded...)
	}

	return questions, nil
}

func difficultyFromDirectory(name string) (domain.Difficulty, error) {
	value, err := strconv.Atoi(name)
	if err != nil {
		return 0, err
	}

	difficulty := domain.Difficulty(value)
	if !difficulty.Valid() {
		return 0, domain.ErrInvalidDifficulty
	}

	return difficulty, nil
}

func loadQuestionsFromDifficultyDir(dir, theme, locale, topic string, difficulty domain.Difficulty) ([]domain.Question, error) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, fmt.Errorf("read questions directory %s: %w", dir, err)
	}

	var questions []domain.Question
	for _, entry := range entries {
		if entry.IsDir() || filepath.Ext(entry.Name()) != ".json" {
			continue
		}

		path := filepath.Join(dir, entry.Name())
		question, err := loadQuestionFile(path)
		if err != nil {
			return nil, err
		}

		question.ID = strings.TrimSuffix(entry.Name(), filepath.Ext(entry.Name()))
		question.Theme = theme
		question.Locale = locale
		question.Topic = topic
		question.Difficulty = difficulty
		if err := question.Validate(); err != nil {
			return nil, fmt.Errorf("invalid question %q in %s: %w", question.ID, path, err)
		}
		questions = append(questions, question)
	}

	return questions, nil
}

func loadQuestionFile(path string) (domain.Question, error) {
	bytes, err := os.ReadFile(path)
	if err != nil {
		return domain.Question{}, fmt.Errorf("read question file %s: %w", path, err)
	}

	var question domain.Question
	if err := json.Unmarshal(bytes, &question); err != nil {
		return domain.Question{}, fmt.Errorf("decode question file %s: %w", path, err)
	}

	return question, nil
}

func LoadQuestionsFromDir(dir, locale string) ([]domain.Question, error) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, fmt.Errorf("read questions directory: %w", err)
	}

	var questions []domain.Question
	for _, entry := range entries {
		if entry.IsDir() || filepath.Ext(entry.Name()) != ".json" {
			continue
		}

		path := filepath.Join(dir, entry.Name())
		fileQuestions, err := loadLegacyQuestionFile(path)
		if err != nil {
			return nil, err
		}
		for i := range fileQuestions {
			if fileQuestions[i].Theme == "" {
				fileQuestions[i].Theme = "dev"
			}
			if fileQuestions[i].Locale == "" {
				fileQuestions[i].Locale = locale
			}
			fileQuestions[i].Locale = i18n.NormalizeLocale(fileQuestions[i].Locale)
			if err := fileQuestions[i].Validate(); err != nil {
				return nil, fmt.Errorf("invalid question %q in %s: %w", fileQuestions[i].ID, path, err)
			}
		}
		questions = append(questions, fileQuestions...)
	}

	if len(questions) == 0 {
		return nil, errors.New("no questions loaded")
	}

	return questions, nil
}

func loadLegacyQuestionFile(path string) ([]domain.Question, error) {
	bytes, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read question file %s: %w", path, err)
	}

	var questions []domain.Question
	if err := json.Unmarshal(bytes, &questions); err != nil {
		return nil, fmt.Errorf("decode question file %s: %w", path, err)
	}

	return questions, nil
}
