# QuickQuiz Manager

Symfony webapp for editing and validating QuickQuiz Dev quiz pack JSON files.

The quiz pack contract is documented at `../../docs/quiz-pack-contract.md`.

Monorepo documentation:

- [Documentation index](../../docs/README.md)
- [Manager documentation](../../docs/manager/README.md)
- [Data documentation](../../docs/data/README.md)

## Development

The manager development environment uses Docker and Docker Compose. PHP,
Composer, Symfony commands, and tests run inside containers.

```sh
cd apps/manager
cp .env-example .env
docker compose run --rm manager composer install
docker compose run --rm manager php bin/console manager:admin:create admin@example.com change-me-123
docker compose up manager
```

Open:

```text
http://localhost:8081
```

## Commands

```sh
docker compose run --rm manager php bin/console manager:admin:create admin@example.com change-me-123
docker compose run --rm manager composer test
```

## Content

By default the Compose file mounts `../api/.local` to `/content` and sets:

```text
MANAGER_CONTENT_ROOT=/content
```

Question files are saved as JSON with only:

- `prompt`
- `correctOptions`
- `wrongOptions`

Themes are managed through:

```text
/content/themes.json
/content/<theme>/index.json
/content/<theme>/<locale>/index.json
/content/<theme>/<locale>/<topic>/<difficulty>/<question-id>.json
/content/<theme>/ai-prompts/question-solution-prompt.txt
```

After login, select a theme before managing catalog topics or questions. Inactive
themes remain editable in the manager but are not served by the player API.

The `question_solution` AI prompt is stored in the manager SQLite database and
exported to `/content/<theme>/ai-prompts/question-solution-prompt.txt` when it is
saved, restored, or imported from JSON. Configure the API with
`OPENAI_SOLUTION_PROMPT_FILE=<QUESTION_SOURCE>/{{theme}}/ai-prompts/question-solution-prompt.txt`
so it reads the exported prompt.
