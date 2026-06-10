# QuickQuiz Dev MVP: frontend and backend flow

## Objective

Define the minimum flow between the Quasar frontend and Go backend to publish the QuickQuiz Dev MVP.

The backend is responsible for loading questions, creating runs, randomly selecting options, validating answers, and generating the final result.

The frontend is responsible for initial selection, gamified display, visual feedback, and result presentation.

## Stack

- Backend: Go.
- Frontend: Quasar.
- Database: none in the MVP.
- Question storage: JSON in an S3 bucket.
- Session and run state: backend memory.

## Main Flow

1. The user opens the site.
2. The frontend displays the start screen.
3. The user chooses a programming language.
4. The user chooses a difficulty level.
5. The user starts a run.
6. The backend creates the run in memory.
7. The backend returns the first question with randomly selected options.
8. The user answers.
9. The backend validates the answer.
10. The frontend displays a correct or wrong visual effect.
11. The backend returns the next question or indicates the end of the run.
12. The frontend displays the final result.

## Frontend Screens

### Start Screen

Must contain:

- language selection;
- difficulty selection;
- button to start run.

Must not contain login, signup, or global ranking.

### Question Screen

Must contain:

- question prompt;
- randomly selected options;
- run progress;
- action to finish run;
- visual effects for correct and wrong answers.

The frontend must not reveal the correct answer.

### Final Result Screen

Must contain:

- run statistics;
- declarative list of correctly answered questions;
- declarative list of wrongly answered questions;
- action to start a new run.

Must not contain an answer key, explanations, or educational material.

## Layout and Advertising

The base layout must reserve areas for future monetization.

On desktop, ad placeholders must exist for:

- top;
- footer;
- left side;
- right side.

The quiz must occupy the central region of the page.

On mobile:

- advertising areas must not be displayed initially;
- the player experience should occupy practically the full available width.

The placeholders must not integrate real ad providers in the MVP.

## Suggested Minimum Endpoints

### Create Run

`POST /api/runs`

Request:

```json
{
  "language": "go",
  "difficulty": "media"
}
```

Response:

```json
{
  "runId": "run_123",
  "question": {
    "id": "q_1",
    "prompt": "Question displayed to the player",
    "options": [
      { "id": "a", "text": "Option A" },
      { "id": "b", "text": "Option B" },
      { "id": "c", "text": "Option C" },
      { "id": "d", "text": "Option D" },
      { "id": "e", "text": "Option E" }
    ],
    "current": 1,
    "total": 10
  }
}
```

### Answer Question

`POST /api/runs/{runId}/answers`

Request:

```json
{
  "questionId": "q_1",
  "optionId": "b"
}
```

Response with next question:

```json
{
  "correct": true,
  "finished": false,
  "question": {
    "id": "q_2",
    "prompt": "Next question",
    "options": [
      { "id": "a", "text": "Option A" },
      { "id": "b", "text": "Option B" },
      { "id": "c", "text": "Option C" },
      { "id": "d", "text": "Option D" },
      { "id": "e", "text": "Option E" }
    ],
    "current": 2,
    "total": 10
  }
}
```

Response with run end:

```json
{
  "correct": false,
  "finished": true,
  "finishReason": "hardcore_wrong_answer"
}
```

### Finish Run

`POST /api/runs/{runId}/finish`

Response:

```json
{
  "finished": true,
  "finishReason": "player_quit"
}
```

### Final Result

`GET /api/runs/{runId}/result`

Response:

```json
{
  "runId": "run_123",
  "language": "go",
  "difficulty": "media",
  "finishReason": "max_questions_reached",
  "stats": {
    "answered": 10,
    "correct": 7,
    "wrong": 3,
    "accuracyPercent": 70
  },
  "answers": [
    {
      "questionId": "q_1",
      "prompt": "Question displayed to the player",
      "correct": true
    },
    {
      "questionId": "q_2",
      "prompt": "Another question displayed to the player",
      "correct": false
    }
  ]
}
```

## Backend Rules

The backend must:

- load questions from S3;
- keep loaded questions in memory cache;
- create session and run in memory;
- prevent question repetition within the same run;
- randomly select options on each question display;
- validate answers without depending on the frontend;
- record correct and wrong answers;
- finish the run when an ending rule is reached;
- generate the final report without an answer key.

## Frontend Rules

The frontend must:

- use only data returned by the backend;
- send answers for backend validation;
- handle correct and wrong answers with visual feedback;
- redirect to result when the run ends;
- not store the question bank;
- not contain correct-answer logic.

## Out of Scope for the MVP

Do not implement at this time:

- real AdSense integration;
- database;
- admin panel;
- authentication;
- global ranking;
- AI;
- answer explanations;
- result export.
