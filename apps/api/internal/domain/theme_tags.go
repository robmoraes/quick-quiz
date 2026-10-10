package domain

import (
	"encoding/json"
	"errors"
	"regexp"
	"strings"
)

var ErrInvalidTag = errors.New("invalid tag")

var tagPattern = regexp.MustCompile(`^[a-z0-9]+(-[a-z0-9]+)*$`)

// NormalizeTag accepts the same ASCII input as Manager authoring.
func NormalizeTag(input string) (string, error) {
	if len(input) > 50 {
		return "", ErrInvalidTag
	}
	// Reject non-ASCII before lowercasing, which can otherwise fold Unicode.
	for _, char := range input {
		if char > 127 {
			return "", ErrInvalidTag
		}
	}
	tag := strings.ToLower(strings.Trim(input, " \t\r\n\f\v"))
	if !tagPattern.MatchString(tag) {
		return "", ErrInvalidTag
	}
	return tag, nil
}

// ThemeTags is a canonical published set. A missing field is handled by the
// loader; an explicitly supplied null, non-array, or noncanonical set is invalid.
type ThemeTags []string

func (tags *ThemeTags) UnmarshalJSON(data []byte) error {
	var values []string
	if err := json.Unmarshal(data, &values); err != nil || values == nil || len(values) > 20 {
		return ErrInvalidTag
	}
	for i, value := range values {
		normalized, err := NormalizeTag(value)
		if err != nil || normalized != value || (i > 0 && values[i-1] >= value) {
			return ErrInvalidTag
		}
	}
	*tags = values
	return nil
}
