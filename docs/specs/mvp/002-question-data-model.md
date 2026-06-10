# QuickQuiz Dev MVP: question data model

## Objective

Define the minimum question data format to allow option variety between sessions without exposing answers in the frontend.

Questions will be stored as JSON files outside the main repository, in an S3 bucket.

## Storage

Question JSON files:

- must not be versioned directly in the main repository;
- must not be embedded in the backend binary;
- must be loaded by the backend from S3;
- may be kept in the backend memory cache.

Goals for using S3:

- avoid exposing question content in the frontend;
- avoid exposing the question bank in Git;
- allow question maintenance without recompiling the backend;
- allow gradual content expansion.

## Minimum Question Structure

Each question must contain:

- unique identifier;
- programming language;
- difficulty level;
- prompt;
- list of correct options;
- list of wrong options.

Each question must have at least:

- 10 correct options written in different ways;
- 20 wrong options.

Even with several registered correct options, only one correct option must be displayed at a time.

## Suggested JSON Format

```json
{
  "id": "go-basic-001",
  "language": "go",
  "difficulty": "baixa",
  "prompt": "Which keyword declares a variable in Go with type inference inside a function?",
  "correctOptions": [
    ":=",
    ":= operator",
    "short declaration :=",
    "short variable declaration",
    "short declaration operator :=",
    "the short declaration operator :=",
    "the := syntax",
    "short variable declaration",
    "short declaration operator",
    "short assignment :="
  ],
  "wrongOptions": [
    "var=",
    "let",
    "const",
    "auto",
    "def",
    "set",
    "new",
    "make",
    "declare",
    "infer",
    "val",
    "type",
    "using",
    "mut",
    "static",
    "final",
    "dim",
    "assign",
    "local",
    "var:"
  ]
}
```

## Option Selection Rules

For each question display, the backend must:

1. randomly select 1 option from the `correctOptions` list;
2. randomly select N options from the `wrongOptions` list;
3. shuffle the final order;
4. send only the selected options to the frontend.

N must be calculated as:

- baixa: 2 wrong options;
- media: 4 wrong options;
- alta: 6 wrong options;
- hardcore: 6 wrong options.

The backend must internally keep track of which sent option is correct to validate the answer.

## File Validations

When loading JSON files, the backend must validate:

- non-empty `id`;
- non-empty `language`;
- `difficulty` as `baixa`, `media`, `alta`, or `hardcore`;
- non-empty `prompt`;
- at least 10 correct options;
- at least 20 wrong options;
- no duplicate ids within the loaded set.

Invalid questions must not be served to the player.

## Exposure Contract

The frontend must never receive:

- the complete list of correct options;
- the complete list of wrong options;
- an explicit indication of the correct answer;
- raw JSON from the question bank.

The frontend may receive:

- public question id within the run;
- prompt;
- randomly selected options;
- current run position;
- maximum total questions in the run.
