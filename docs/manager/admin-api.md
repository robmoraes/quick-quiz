# Quiz Administration API

The Manager exposes a JSON API for trusted automation clients, including a
Codex session running on the operator's computer. It discovers themes and
topics and persists complete localized question sets through the same local or
S3-compatible storage used by the Manager.

The contract is [docs/openapi-manager-admin.yaml](../openapi-manager-admin.yaml).

## Configure the token

Generate one opaque token with at least 32 characters and store it outside the
repository:

```sh
install -d -m 700 /absolute/path/to/quickquiz-secrets/manager
openssl rand -hex 32 > /absolute/path/to/quickquiz-secrets/manager/admin_api_token
chmod 600 /absolute/path/to/quickquiz-secrets/manager/admin_api_token
```

Configure the Compose environment:

```text
QUICKQUIZ_SECRETS_ROOT=/absolute/path/to/quickquiz-secrets
MANAGER_ADMIN_API_TOKEN__FILE=/run/secrets/admin_api_token
```

Start Compose with the secret mount overlay:

```sh
docker compose \
  --env-file deploy/compose.local/.env \
  -f deploy/compose.local/docker-compose.yml \
  -f deploy/compose.secrets.yml \
  up -d
```

`MANAGER_ADMIN_API_TOKEN__FILE` takes priority over
`MANAGER_ADMIN_API_TOKEN`. If the configured token is absent or shorter than
32 characters, all API operations fail closed with HTTP 503.

## Use from a local Codex session

Set the remote Manager URL and make the token available only in the shell where
Codex is running:

```sh
export QUICKQUIZ_MANAGER_URL=https://manager.example.com
read -rsp 'Manager API token: ' QUICKQUIZ_ADMIN_TOKEN
export QUICKQUIZ_ADMIN_TOKEN
```

Discover the existing catalog before choosing where new material belongs:

```sh
curl --fail-with-body --silent --show-error \
  -H "Authorization: Bearer $QUICKQUIZ_ADMIN_TOKEN" \
  "$QUICKQUIZ_MANAGER_URL/api/admin/quiz/catalog?locale=pt-BR"
```

A useful instruction for Codex is:

```text
Use the QuickQuiz Manager API. First inspect the catalog in pt-BR and choose
the existing theme and topic whose description best matches the material.
If no topic fits, create one in the most suitable theme. Then create a complete
difficulty-2 package in every supported locale. Avoid prompts already returned
by the question-list endpoint and show me the API result.
```

Create a topic when discovery shows that no existing topic is appropriate:

```sh
curl --fail-with-body --silent --show-error \
  -X POST \
  -H "Authorization: Bearer $QUICKQUIZ_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  --data '{
    "key": "git",
    "name": "Git",
    "description": "Git concepts and workflows.",
    "active": true,
    "weight": 100,
    "localizations": {
      "pt-BR": {
        "name": "Git",
        "description": "Conceitos e fluxos de trabalho do Git."
      }
    }
  }' \
  "$QUICKQUIZ_MANAGER_URL/api/admin/quiz/themes/dev/topics"
```

Create one or more localized question sets:

```sh
cat > /tmp/quickquiz-questions.json <<'JSON'
{
  "difficulty": 2,
  "questions": [
    {
      "translations": {
        "en-US": {
          "prompt": "Which command creates a Git commit?",
          "correctOptions": ["git commit"],
          "wrongOptions": ["git add", "git push", "git fetch", "git status"]
        },
        "pt-BR": {
          "prompt": "Qual comando cria um commit no Git?",
          "correctOptions": ["git commit"],
          "wrongOptions": ["git add", "git push", "git fetch", "git status"]
        }
      }
    }
  ]
}
JSON

curl --fail-with-body --silent --show-error \
  -X POST \
  -H "Authorization: Bearer $QUICKQUIZ_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  --data-binary @/tmp/quickquiz-questions.json \
  "$QUICKQUIZ_MANAGER_URL/api/admin/quiz/themes/dev/topics/git/questions"
```

Question creation accepts up to 50 sets. Omitting `id` allocates the next
`<topic>-<difficulty>-<sequence>` value. Every question must include exactly
the locales reported by the catalog.

Every successful mutation returns `publication.apiReloadRequired=true`. The
Manager writes the content immediately, but the Quiz API reads it at startup;
restart only the Quiz API after finishing a publication batch.
