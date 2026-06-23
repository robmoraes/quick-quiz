package app

import (
	"context"
	"errors"

	"quickquiz/api/internal/domain"
)

type ThemeRepository interface {
	Theme(ctx context.Context, theme string) (domain.Theme, error)
}

func validateTheme(ctx context.Context, themes ThemeRepository, theme string) error {
	if theme == "" {
		return ErrThemeRequired
	}
	found, err := themes.Theme(ctx, theme)
	if err != nil {
		if errors.Is(err, domain.ErrThemeNotFound) {
			return ErrThemeNotFound
		}
		return err
	}
	if !found.Active {
		return ErrThemeInactive
	}
	return nil
}
