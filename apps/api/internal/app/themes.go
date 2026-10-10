package app

import (
	"context"
	"sort"

	"quickquiz/api/internal/domain"
)

// Themes uses the same repository snapshot as catalogs and run creation.
// A nil tag lists all active themes; an explicitly empty tag is invalid.
func (s *RunService) Themes(ctx context.Context, tag *string) ([]domain.Theme, error) {
	filter := ""
	if tag != nil {
		var err error
		filter, err = domain.NormalizeTag(*tag)
		if err != nil {
			return nil, err
		}
	}
	themes, err := s.questions.ListThemes(ctx)
	if err != nil {
		return nil, err
	}
	result := make([]domain.Theme, 0, len(themes))
	for _, theme := range themes {
		if !theme.Active {
			continue
		}
		if filter != "" {
			matched := false
			for _, candidate := range theme.Tags {
				if candidate == filter {
					matched = true
					break
				}
			}
			if !matched {
				continue
			}
		}
		result = append(result, theme)
	}
	sort.Slice(result, func(i, j int) bool {
		if result[i].Weight != result[j].Weight {
			return result[i].Weight < result[j].Weight
		}
		return result[i].ID < result[j].ID
	})
	return result, nil
}
