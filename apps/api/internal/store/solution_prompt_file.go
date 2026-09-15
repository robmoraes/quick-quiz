package store

import (
	"context"
	"errors"
	"os"
	"strings"
)

type FileSolutionPromptSource struct {
	pathTemplate string
}

func NewFileSolutionPromptSource(pathTemplate string) *FileSolutionPromptSource {
	return &FileSolutionPromptSource{pathTemplate: pathTemplate}
}

func (s *FileSolutionPromptSource) Load(_ context.Context, theme string) (string, error) {
	theme, err := cleanPathComponent(theme)
	if err != nil {
		return "", err
	}

	promptFile := strings.TrimSpace(s.pathTemplate)
	if promptFile == "" {
		return "", errors.New("solution prompt file is not configured")
	}
	promptFile = strings.ReplaceAll(promptFile, "{{ theme }}", theme)
	promptFile = strings.ReplaceAll(promptFile, "{{theme}}", theme)

	contents, err := os.ReadFile(promptFile)
	if err != nil {
		return "", err
	}
	return string(contents), nil
}
