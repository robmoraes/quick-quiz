package app

import "errors"

var (
	ErrInvalidInput  = errors.New("invalid input")
	ErrThemeRequired = errors.New("theme is required")
	ErrThemeNotFound = errors.New("theme not found")
	ErrThemeInactive = errors.New("theme inactive")
	ErrAdNotFound    = errors.New("ad not found")
)
