package store

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"quickquiz/api/internal/domain"
)

type FileSolutionStore struct {
	root string
}

func NewFileSolutionStore(root string) *FileSolutionStore {
	return &FileSolutionStore{root: root}
}

func (s *FileSolutionStore) Get(_ context.Context, key domain.QuestionSolution) (domain.QuestionSolution, error) {
	path, err := s.path(key)
	if err != nil {
		return domain.QuestionSolution{}, err
	}

	bytes, err := os.ReadFile(path)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return domain.QuestionSolution{}, domain.ErrSolutionNotFound
		}
		return domain.QuestionSolution{}, fmt.Errorf("read solution file %s: %w", path, err)
	}

	var record solutionFileRecord
	if err := json.Unmarshal(bytes, &record); err != nil {
		return domain.QuestionSolution{}, fmt.Errorf("decode solution file %s: %w", path, err)
	}

	return record.toDomain(), nil
}

func (s *FileSolutionStore) Save(_ context.Context, solution domain.QuestionSolution) error {
	path, err := s.path(solution)
	if err != nil {
		return err
	}

	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return fmt.Errorf("create solution directory %s: %w", dir, err)
	}

	file, err := os.CreateTemp(dir, solution.QuestionID+".*.tmp")
	if err != nil {
		return fmt.Errorf("create temporary solution file: %w", err)
	}

	tempName := file.Name()
	encoder := json.NewEncoder(file)
	encoder.SetIndent("", "  ")
	encodeErr := encoder.Encode(solutionFileRecordFromDomain(solution))
	closeErr := file.Close()
	if encodeErr != nil {
		_ = os.Remove(tempName)
		return fmt.Errorf("encode solution file %s: %w", tempName, encodeErr)
	}
	if closeErr != nil {
		_ = os.Remove(tempName)
		return fmt.Errorf("close solution file %s: %w", tempName, closeErr)
	}

	if err := os.Rename(tempName, path); err != nil {
		_ = os.Remove(tempName)
		return fmt.Errorf("replace solution file %s: %w", path, err)
	}

	return nil
}

type solutionFileRecord struct {
	Theme        string            `json:"theme"`
	Locale       string            `json:"locale"`
	Topic        string            `json:"topic"`
	Difficulty   domain.Difficulty `json:"difficulty"`
	QuestionID   string            `json:"questionId"`
	Explanation  string            `json:"explanation"`
	Model        string            `json:"model,omitempty"`
	QuestionHash string            `json:"questionHash"`
	GeneratedAt  string            `json:"generatedAt,omitempty"`
}

func solutionFileRecordFromDomain(solution domain.QuestionSolution) solutionFileRecord {
	return solutionFileRecord{
		Theme:        solution.Theme,
		Locale:       solution.Locale,
		Topic:        solution.Topic,
		Difficulty:   solution.Difficulty,
		QuestionID:   solution.QuestionID,
		Explanation:  solution.Explanation,
		Model:        solution.Model,
		QuestionHash: solution.QuestionHash,
		GeneratedAt:  solution.GeneratedAt,
	}
}

func (r solutionFileRecord) toDomain() domain.QuestionSolution {
	return domain.QuestionSolution{
		Theme:        r.Theme,
		Locale:       r.Locale,
		Topic:        r.Topic,
		Difficulty:   r.Difficulty,
		QuestionID:   r.QuestionID,
		Explanation:  r.Explanation,
		Model:        r.Model,
		QuestionHash: r.QuestionHash,
		GeneratedAt:  r.GeneratedAt,
	}
}

func (s *FileSolutionStore) path(solution domain.QuestionSolution) (string, error) {
	theme, err := cleanPathComponent(solution.Theme)
	if err != nil {
		return "", fmt.Errorf("invalid theme path component: %w", err)
	}
	locale, err := cleanPathComponent(solution.Locale)
	if err != nil {
		return "", fmt.Errorf("invalid locale path component: %w", err)
	}
	topic, err := cleanPathComponent(solution.Topic)
	if err != nil {
		return "", fmt.Errorf("invalid topic path component: %w", err)
	}
	questionID, err := cleanPathComponent(solution.QuestionID)
	if err != nil {
		return "", fmt.Errorf("invalid question path component: %w", err)
	}
	if !solution.Difficulty.Valid() {
		return "", domain.ErrInvalidDifficulty
	}

	return filepath.Join(
		s.root,
		theme,
		".solutions",
		locale,
		topic,
		strconv.Itoa(int(solution.Difficulty)),
		questionID+".json",
	), nil
}

func cleanPathComponent(value string) (string, error) {
	value = strings.TrimSpace(value)
	if value == "" || value == "." || value == ".." {
		return "", errors.New("empty or unsafe component")
	}
	if strings.ContainsAny(value, `/\`) {
		return "", errors.New("path separator is not allowed")
	}
	if filepath.Clean(value) != value {
		return "", errors.New("unclean component")
	}
	return value, nil
}
