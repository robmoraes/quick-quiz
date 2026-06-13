# QuickQuiz Dev MVP: concepts and run rules

## Objective

QuickQuiz Dev is a gamified quiz webpage for programmers. The MVP must deliver a simple experience that is quick to publish and has no persistence between sessions.

The product should have a 4fun tone, themed visuals, and a "little-millionaire-game" style. In this version, the product must not teach, explain answers, or display an answer key.

## Concepts

### Session

A session starts when the user opens the site in the browser.

The session ends when:

- the browser is closed;
- the session expires due to inactivity.

There is no signup, login, user account, or persistence between sessions in the MVP.

### Level

The level defines the difficulty of the experience and the number of options displayed per question.

Available levels:

- easy;
- moderate;
- hard;
- hardcore.

### Run

A run is a play session started by the user after selecting a programming language and difficulty level.

A run has a fixed number of questions in the MVP. This quantity must be configured as an application constant.

### Question

A question is a prompt displayed to the player during the run.

The same question cannot repeat within the same run.

### Options

Options are the alternatives displayed for a question.

Each question display must contain:

- exactly 1 correct option;
- enough wrong options to complete the total expected by the level;
- shuffled final order.

## Difficulty Rules

Total number of options displayed per question:

- easy: 3 options;
- moderate: 5 options;
- hard: 7 options;
- hardcore: 7 options.

The level must influence:

- the complexity of eligible questions;
- the number of options displayed;
- the run-ending rule in hardcore mode.

## Run Rules

When starting a run, the backend must select questions compatible with the chosen language and level.

During the run:

- the backend must track which questions have already been used;
- the backend must randomly select only unused questions;
- the backend must validate answers;
- the frontend must not receive the full question bank;
- the frontend must not receive the list of correct answers.

The run ends when:

- the player decides to finish;
- the fixed number of run questions is reached;
- the available questions run out;
- at the hardcore level, the player gets a question wrong.

## Final Result

At the end of the run, the frontend must display:

- a statistical report for the run;
- a declarative report with correct and wrong questions.

The final result must not display:

- the correct answer;
- question explanation;
- educational content;
- any attempt to teach the user.

## Minimum Statistics

The statistical report must contain at least:

- total answered questions;
- total correct answers;
- total wrong answers;
- accuracy percentage;
- chosen language;
- chosen level;
- run finish reason.

## Out of Scope for the MVP

The MVP must not implement:

- signup;
- login;
- global ranking;
- admin panel;
- database;
- AI;
- full-frontend version;
- answer explanations;
- answer key.
