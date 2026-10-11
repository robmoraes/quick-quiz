package app

import (
	"context"

	"quickquiz/api/internal/domain"
)

// RuntimeQuestionReader is optional so existing local adapters and test doubles
// can keep the legacy QuestionRepository contract during the transition.
// Each method is one consistent read operation; implementations must not hydrate
// full question banks for counts or selection.
type RuntimeQuestionReader interface {
	ReadCatalog(context.Context, domain.CatalogReadInput) (domain.CatalogReadResult, error)
	SelectRunPackages(context.Context, domain.RunPackageSelection) (domain.SelectedRunPackages, error)
	LookupQuestion(context.Context, domain.QuestionLookup) (domain.Question, error)
	ListThemesByTag(context.Context, *string) ([]domain.Theme, error)
}

type IndexedRunRepository interface {
	ConsumedBySession(context.Context, string, string) (domain.QuestionConsumption, error)
	HasSession(context.Context, string, string) (bool, error)
	CountLegacyDependent(context.Context) (int, error)
}
