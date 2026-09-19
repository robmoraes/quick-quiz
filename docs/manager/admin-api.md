# Quiz Administration API

The Manager exposes a JSON API for trusted automation clients, including a
Codex session running on the operator's computer. It discovers themes and
topics and persists complete localized question sets. In the `legacy` mode it
writes the configured local or S3-compatible content store directly. In the
`postgres` mode it writes PostgreSQL transactionally and publishes the same
JSON layout to that content store.

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

For repeated local use, keep both variables in the repository-local
`.env.remote-api`, which is ignored by Git, set its mode to `0600`, and load
it before starting Codex:

```sh
set -a
. ./.env.remote-api
set +a
codex
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

Successful playable-content mutations return `publication.apiReloadRequired=true`. The
Manager writes the content immediately, but the Quiz API reads it at startup;
restart only the Quiz API after finishing a publication batch.

In PostgreSQL mode, successful playable-content mutations include `publication.revision` and
`publication.status=published`. A committed revision whose storage publication
fails returns HTTP 503 with `error.code=publication_failed` and
`publication.apiReloadRequired=false`. Check `GET /api/admin/quiz/publication`
and retry with `POST /api/admin/quiz/publication` after fixing the storage
failure. Both endpoints require the same administrative Bearer token. Restart
only the Quiz API after publication succeeds.

## Topic tags (PostgreSQL)

Topic reads, topic lists, and catalog discovery include `tags`, shared across
locales. Create/replace accepts an optional string array such as
`"tags": ["AWS", "redes"]`; reads return `["aws", "redes"]`. Use up to 20 entries
of at most 50 characters each. Surrounding ASCII whitespace is trimmed; letters
are lowercased, duplicates removed, and results sorted. Slugs permit ASCII
letters, digits, and single internal hyphens. Invalid values, including `null`
and objects, return `422 validation_failed` without persisting changes.

Omission means no tags on creation and preservation on replacement. Send `[]`
to clear a topic's tags. `PUT` still uses the complete required topic metadata;
it is not a partial update. Copy `name`, `description`, `weight`, `created_at`,
and `active` from the current topic, then supply the desired `tags` array.

When only tags change, the response contains the updated `topic` and exactly:

```json
{"apiReloadRequired": false, "reason": "topic_tags_only"}
```

This `publication` object describes only that operation. It does not clear or
retry earlier pending/failed publications. Tags-only saves do not access S3,
create revisions, change published JSON, or require a Quiz API reload. Changing
published topic metadata alongside tags follows the ordinary publication flow;
if it fails, the committed tags remain saved and the existing 503 response applies.

Legacy mode omits tags from reads and rejects any supplied `tags`, including an
empty array, with `409 topic_tags_unavailable`. Existing clients omitting tags
keep working. See [topic tag operations and recovery](topic-tags.md).
