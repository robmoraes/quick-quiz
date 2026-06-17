package app

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"strings"
	"time"
)

const (
	defaultOpenAIBaseURL       = "https://api.openai.com/v1"
	defaultOpenAISolutionModel = "gpt-5.4-mini"
	defaultSolutionPrompt      = "Explain the correct answer for a programming quiz question. Be concise, accurate, and answer in the requested locale."
)

type OpenAISolutionGeneratorConfig struct {
	APIKey       string
	BaseURL      string
	Model        string
	Organization string
	Project      string
	PromptFile   string
	Timeout      time.Duration
}

type OpenAISolutionGenerator struct {
	config OpenAISolutionGeneratorConfig
	client *http.Client
}

func NewOpenAISolutionGenerator(config OpenAISolutionGeneratorConfig, client *http.Client) *OpenAISolutionGenerator {
	if strings.TrimSpace(config.BaseURL) == "" {
		config.BaseURL = defaultOpenAIBaseURL
	}
	if strings.TrimSpace(config.Model) == "" {
		config.Model = defaultOpenAISolutionModel
	}
	if config.Timeout <= 0 {
		config.Timeout = 30 * time.Second
	}
	if client == nil {
		client = &http.Client{Timeout: config.Timeout}
	}

	return &OpenAISolutionGenerator{
		config: config,
		client: client,
	}
}

func (g *OpenAISolutionGenerator) Model() string {
	return strings.TrimSpace(g.config.Model)
}

func (g *OpenAISolutionGenerator) GenerateSolution(ctx context.Context, input GenerateSolutionInput) (string, error) {
	if strings.TrimSpace(g.config.APIKey) == "" {
		return "", errors.New("openai api key is not configured")
	}

	payload := openAIResponseRequest{
		Model:           g.Model(),
		Instructions:    g.instructions(input),
		Input:           solutionPromptInput(input),
		MaxOutputTokens: 700,
	}
	body, err := json.Marshal(payload)
	if err != nil {
		return "", err
	}

	request, err := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(g.config.BaseURL, "/")+"/responses", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	request.Header.Set("Authorization", "Bearer "+strings.TrimSpace(g.config.APIKey))
	request.Header.Set("Content-Type", "application/json")
	if organization := strings.TrimSpace(g.config.Organization); organization != "" {
		request.Header.Set("OpenAI-Organization", organization)
	}
	if project := strings.TrimSpace(g.config.Project); project != "" {
		request.Header.Set("OpenAI-Project", project)
	}

	response, err := g.client.Do(request)
	if err != nil {
		return "", err
	}
	defer response.Body.Close()

	responseBody, err := io.ReadAll(response.Body)
	if err != nil {
		return "", err
	}
	if response.StatusCode < http.StatusOK || response.StatusCode >= http.StatusMultipleChoices {
		return "", openAIError(response.StatusCode, responseBody)
	}

	explanation, err := parseOpenAIOutputText(responseBody)
	if err != nil {
		return "", err
	}
	return explanation, nil
}

func (g *OpenAISolutionGenerator) instructions(input GenerateSolutionInput) string {
	if promptFile := strings.TrimSpace(g.config.PromptFile); promptFile != "" {
		promptFile = strings.ReplaceAll(promptFile, "{{ theme }}", input.Theme)
		promptFile = strings.ReplaceAll(promptFile, "{{theme}}", input.Theme)
		bytes, err := os.ReadFile(promptFile)
		if err == nil {
			if prompt := strings.TrimSpace(string(bytes)); prompt != "" {
				return prompt
			}
		}
	}
	return defaultSolutionPrompt
}

type openAIResponseRequest struct {
	Model           string `json:"model"`
	Instructions    string `json:"instructions"`
	Input           string `json:"input"`
	MaxOutputTokens int    `json:"max_output_tokens,omitempty"`
}

func solutionPromptInput(input GenerateSolutionInput) string {
	var builder strings.Builder
	builder.WriteString("Requested locale: ")
	builder.WriteString(input.Locale)
	builder.WriteString("\nTheme: ")
	builder.WriteString(input.Theme)
	builder.WriteString("\nTopic: ")
	builder.WriteString(input.Topic)
	builder.WriteString("\nDifficulty: ")
	builder.WriteString(fmt.Sprintf("%d", input.Difficulty))
	builder.WriteString("\nQuestion ID: ")
	builder.WriteString(input.QuestionID)
	builder.WriteString("\n\nQuestion prompt:\n")
	builder.WriteString(input.Prompt)
	builder.WriteString("\n\nCorrect option(s):")
	for _, option := range input.CorrectOptions {
		builder.WriteString("\n- ")
		builder.WriteString(option)
	}
	builder.WriteString("\n\nWrong option(s):")
	for _, option := range input.WrongOptions {
		builder.WriteString("\n- ")
		builder.WriteString(option)
	}
	builder.WriteString("\n\nReturn only the final explanation text in the requested locale.")
	return builder.String()
}

type openAIResponse struct {
	OutputText string                 `json:"output_text"`
	Output     []openAIResponseOutput `json:"output"`
}

type openAIResponseOutput struct {
	Type    string                  `json:"type"`
	Content []openAIResponseContent `json:"content"`
}

type openAIResponseContent struct {
	Type string `json:"type"`
	Text string `json:"text"`
}

func parseOpenAIOutputText(body []byte) (string, error) {
	var response openAIResponse
	if err := json.Unmarshal(body, &response); err != nil {
		return "", err
	}
	if text := strings.TrimSpace(response.OutputText); text != "" {
		return text, nil
	}
	for _, output := range response.Output {
		for _, content := range output.Content {
			if content.Type == "output_text" || content.Type == "text" {
				if text := strings.TrimSpace(content.Text); text != "" {
					return text, nil
				}
			}
		}
	}
	return "", errors.New("openai response did not include output text")
}

func openAIError(statusCode int, body []byte) error {
	var payload struct {
		Error struct {
			Message string `json:"message"`
			Code    string `json:"code"`
			Type    string `json:"type"`
		} `json:"error"`
	}
	if err := json.Unmarshal(body, &payload); err == nil {
		if message := strings.TrimSpace(payload.Error.Message); message != "" {
			return fmt.Errorf("openai error %d: %s", statusCode, message)
		}
	}
	return fmt.Errorf("openai error %d", statusCode)
}
