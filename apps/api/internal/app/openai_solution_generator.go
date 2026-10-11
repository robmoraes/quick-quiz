package app

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

const (
	defaultOpenAIBaseURL       = "https://api.openai.com/v1"
	defaultOpenAISolutionModel = "gpt-5.4-mini"
	DefaultSolutionPrompt      = "Explain the correct answer for a quiz question. Be concise, accurate, and answer in the requested locale."
)

type SolutionPromptSource interface {
	Load(ctx context.Context, theme string) (string, error)
}

type OpenAISolutionGeneratorConfig struct {
	APIKey       string
	BaseURL      string
	Model        string
	Organization string
	Project      string
	Timeout      time.Duration
}

type OpenAISolutionGenerator struct {
	config       OpenAISolutionGeneratorConfig
	client       *http.Client
	promptSource SolutionPromptSource
}

func NewOpenAISolutionGenerator(config OpenAISolutionGeneratorConfig, client *http.Client, promptSource SolutionPromptSource) *OpenAISolutionGenerator {
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
		config:       config,
		client:       client,
		promptSource: promptSource,
	}
}

func (g *OpenAISolutionGenerator) Model() string {
	return strings.TrimSpace(g.config.Model)
}

// PrepareSolution captures the prompt/configuration used by both cache identity
// and generation, even when a theme prompt changes during an in-flight request.
func (g *OpenAISolutionGenerator) PrepareSolution(ctx context.Context, theme string) (SolutionGenerationContext, error) {
	prompt := DefaultSolutionPrompt
	if g.promptSource != nil {
		configured, err := g.promptSource.Load(ctx, theme)
		if err != nil {
			return SolutionGenerationContext{}, err
		}
		if configured = strings.TrimSpace(configured); configured != "" {
			prompt = configured
		}
	}
	configuration, _ := json.Marshal(struct {
		BaseURL, Model, Organization, Project string
		MaxOutputTokens, ContractVersion      int
	}{strings.TrimRight(g.config.BaseURL, "/"), g.Model(), strings.TrimSpace(g.config.Organization), strings.TrimSpace(g.config.Project), 700, 2})
	return SolutionGenerationContext{Instructions: prompt, PromptDigest: hashText(prompt), GeneratorConfigDigest: hashText(string(configuration)), Model: g.Model()}, nil
}

func (g *OpenAISolutionGenerator) GenerateSolution(ctx context.Context, input GenerateSolutionInput) (string, error) {
	if strings.TrimSpace(g.config.APIKey) == "" {
		return "", errors.New("openai api key is not configured")
	}
	instructions := input.EffectiveInstructions
	if instructions == "" {
		prepared, err := g.PrepareSolution(ctx, input.Theme)
		if err != nil {
			return "", err
		}
		instructions = prepared.Instructions
	}

	payload := openAIResponseRequest{
		Model:           g.Model(),
		Instructions:    instructions,
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

func hashText(value string) string {
	sum := sha256.Sum256([]byte(value))
	return hex.EncodeToString(sum[:])
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
