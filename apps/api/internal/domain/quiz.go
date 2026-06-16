package domain

import (
	"errors"
	"time"
)

type Difficulty int

const (
	DifficultyEasy     Difficulty = 1
	DifficultyNormal   Difficulty = 2
	DifficultyHard     Difficulty = 3
	DifficultyHardcore Difficulty = 4
)

type FinishReason string

const (
	FinishReasonPlayerQuit          FinishReason = "player_quit"
	FinishReasonMaxQuestionsReached FinishReason = "max_questions_reached"
	FinishReasonNoQuestionsLeft     FinishReason = "no_questions_left"
	FinishReasonHardcoreWrongAnswer FinishReason = "hardcore_wrong_answer"
)

var (
	ErrInvalidDifficulty = errors.New("invalid difficulty")
	ErrInvalidQuestion   = errors.New("invalid question")
	ErrThemeNotFound     = errors.New("theme not found")
)

type Theme struct {
	ID          string `json:"id"`
	Name        string `json:"name"`
	Description string `json:"description,omitempty"`
	Weight      int    `json:"weight"`
	CreatedAt   string `json:"createdAt,omitempty"`
	Active      bool   `json:"active"`
}

type Ad struct {
	ID          string     `json:"id"`
	ProviderID  string     `json:"providerId,omitempty"`
	URI         string     `json:"uri"`
	Description string     `json:"description"`
	Image       string     `json:"image"`
	CreatedAt   string     `json:"createdAt,omitempty"`
	ExpiresIn   *time.Time `json:"-"`
	Active      bool       `json:"-"`
	Emphasis    bool       `json:"-"`
	Themes      []string   `json:"-"`
	Topics      []string   `json:"-"`
}

type Ads struct {
	Theme    string `json:"theme"`
	Ads      []Ad   `json:"ads"`
	Emphasis []Ad   `json:"emphasis,omitempty"`
}

type Question struct {
	ID             string     `json:"id"`
	Theme          string     `json:"theme,omitempty"`
	Locale         string     `json:"locale,omitempty"`
	Topic          string     `json:"topic"`
	Difficulty     Difficulty `json:"difficulty"`
	Prompt         string     `json:"prompt"`
	CorrectOptions []string   `json:"correctOptions"`
	WrongOptions   []string   `json:"wrongOptions"`
}

func (q Question) Validate() error {
	if q.ID == "" || q.Theme == "" || q.Locale == "" || q.Topic == "" || q.Prompt == "" {
		return ErrInvalidQuestion
	}
	if !q.Difficulty.Valid() {
		return ErrInvalidDifficulty
	}
	if len(q.CorrectOptions) == 0 || len(q.WrongOptions) < q.Difficulty.OptionCount()-1 {
		return ErrInvalidQuestion
	}
	return nil
}

func (d Difficulty) Valid() bool {
	switch d {
	case DifficultyEasy, DifficultyNormal, DifficultyHard, DifficultyHardcore:
		return true
	default:
		return false
	}
}

func (d Difficulty) OptionCount() int {
	switch d {
	case DifficultyEasy:
		return 3
	case DifficultyNormal:
		return 5
	case DifficultyHard, DifficultyHardcore:
		return 7
	default:
		return 0
	}
}

type Option struct {
	ID   string `json:"id"`
	Text string `json:"text"`
}

type PublicQuestion struct {
	ID      string   `json:"id"`
	Prompt  string   `json:"prompt"`
	Options []Option `json:"options"`
	Current int      `json:"current"`
	Total   int      `json:"total"`
}

type AnswerRecord struct {
	QuestionID string `json:"questionId"`
	Prompt     string `json:"prompt"`
	Correct    bool   `json:"correct"`
}

type Run struct {
	ID              string
	SessionID       string
	Theme           string
	Locale          string
	Topic           string
	Difficulty      Difficulty
	QuestionLimit   int
	UsedQuestionIDs map[string]bool
	CurrentQuestion *ServedQuestion
	Answers         []AnswerRecord
	Finished        bool
	FinishReason    FinishReason
	CreatedAt       time.Time
	UpdatedAt       time.Time
}

type ServedQuestion struct {
	QuestionID      string
	Prompt          string
	Options         []Option
	CorrectOptionID string
	Position        int
	Total           int
}

type Result struct {
	RunID        string         `json:"runId"`
	Theme        string         `json:"theme"`
	Locale       string         `json:"locale"`
	Topic        string         `json:"topic"`
	Difficulty   Difficulty     `json:"difficulty"`
	FinishReason FinishReason   `json:"finishReason"`
	Stats        ResultStats    `json:"stats"`
	Answers      []AnswerRecord `json:"answers"`
}

type ResultStats struct {
	Answered        int `json:"answered"`
	Correct         int `json:"correct"`
	Wrong           int `json:"wrong"`
	AccuracyPercent int `json:"accuracyPercent"`
}

type CatalogMetadata struct {
	Theme          string           `json:"theme"`
	Locale         string           `json:"locale"`
	FallbackLocale string           `json:"fallbackLocale"`
	Topics         []TopicOption    `json:"topics"`
	Difficulties   []DifficultyInfo `json:"difficulties"`
}

type TopicOption struct {
	Theme        string           `json:"-"`
	Locale       string           `json:"-"`
	ID           string           `json:"id"`
	Label        string           `json:"label"`
	Description  string           `json:"description,omitempty"`
	Weight       int              `json:"weight"`
	CreatedAt    string           `json:"createdAt,omitempty"`
	Difficulties []DifficultyInfo `json:"difficulties,omitempty"`
}

type DifficultyInfo struct {
	ID            Difficulty `json:"id"`
	OptionCount   int        `json:"optionCount"`
	QuestionCount int        `json:"questionCount"`
	Hardcore      bool       `json:"hardcore"`
}

type SessionDifficultyAvailability struct {
	ID                     Difficulty `json:"id"`
	OptionCount            int        `json:"optionCount"`
	QuestionCount          int        `json:"questionCount"`
	AvailableQuestionCount int        `json:"availableQuestionCount"`
	Available              bool       `json:"available"`
	Hardcore               bool       `json:"hardcore"`
}

type SessionDifficulties struct {
	Theme        string                          `json:"theme"`
	Locale       string                          `json:"locale"`
	Topic        string                          `json:"topic"`
	Difficulties []SessionDifficultyAvailability `json:"difficulties"`
}

type SessionTopicAvailability struct {
	ID                     string                          `json:"id"`
	Label                  string                          `json:"label"`
	Description            string                          `json:"description,omitempty"`
	Weight                 int                             `json:"weight"`
	CreatedAt              string                          `json:"createdAt,omitempty"`
	QuestionCount          int                             `json:"questionCount"`
	AvailableQuestionCount int                             `json:"availableQuestionCount"`
	Available              bool                            `json:"available"`
	Difficulties           []SessionDifficultyAvailability `json:"difficulties,omitempty"`
}

type SessionTopics struct {
	Theme          string                     `json:"theme"`
	Locale         string                     `json:"locale"`
	FallbackLocale string                     `json:"fallbackLocale"`
	Topics         []SessionTopicAvailability `json:"topics"`
}
