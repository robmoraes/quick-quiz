package domain

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
)

// QuestionConsumption is canonical consumption scoped to one session and theme.
// Locale deliberately does not participate in the identity.
type QuestionConsumption map[string]map[Difficulty][]string

type QuestionKey struct {
	Theme      string
	Topic      string
	Difficulty Difficulty
	QuestionID string
}

type QuestionLookup struct {
	QuestionKey
	Locale string
}

type CatalogReadInput struct {
	Theme          string
	Locale         string
	FallbackLocale string
	Consumption    QuestionConsumption
}

type CatalogReadResult struct {
	Metadata        CatalogMetadata
	AvailableCounts map[string]map[Difficulty]int
}

type RunPackageSelection struct {
	Theme            string
	Topic            string
	Locale           string
	FallbackLocale   string
	Difficulty       Difficulty
	Limit            int
	SupportedLocales []string
	UsedQuestionIDs  map[string]bool
}

// QuestionSnapshot is private run data, never an HTTP response type.
type QuestionSnapshot struct {
	Key          QuestionKey
	Translations map[string]Question
	Digest       string
}

type SelectedRunPackages struct {
	Locale       string
	Provider     string
	Revision     *int64
	SourceDigest string
	Packages     []QuestionSnapshot
}

func QuestionDigest(question Question) string {
	payload, _ := json.Marshal(question)
	sum := sha256.Sum256(payload)
	return hex.EncodeToString(sum[:])
}

func SnapshotDigest(snapshot QuestionSnapshot) string {
	payload, _ := json.Marshal(struct {
		Key          QuestionKey
		Translations map[string]Question
	}{snapshot.Key, snapshot.Translations})
	sum := sha256.Sum256(payload)
	return hex.EncodeToString(sum[:])
}
