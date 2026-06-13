# QuickQuiz Dev MVP: frontend and backend flow

## Objective

Improve usability by using the new metadata provided by the API;

## Start Screen / Session Start

When the application opens, it must fetch only the available languages and
frameworks from the API (as it already does); languages must be displayed with
ordering first alphabetically by name, and then by weight;

When choosing a language, its description must be displayed;

The first screen must have only the language selection and the advance and reset
session buttons; the reset session button must appear only when there is an open
session;

The start screen also applies when the session is reset;

## Second Screen: Difficulty Selection (Severity)

After choosing the language, the second screen opens with the difficulty options
specific to the chosen language;

It must follow the same current model; when choosing a difficulty, it must
display the explanatory text in the same style;

The buttons "start run" and "reset session" must be displayed.

## Run Start: Questions

The questions and answers screen must remain the same.

## Run End, or End the Session

### At the End of the Run

It must continue displaying only the result screen for the run that just ended;

It must display the buttons "New run", "End Session", and "Reset Session".

### End Session

Whenever a session is ended under any circumstance, a Session Result screen must
be displayed;

The model must be the same as the Run Result screen, but with values accumulated
from all runs in the session;

## Run Result and Session Result Screen

The screen must keep the same structure, with changes that apply to the
Run/Session context;

The table must have an always-visible header row with the `code review` theme
and these fields:

|      | Pull request         | code review |
| ---- | -------------------- | ----------- |
| icon | question description | accept      |
| icon | question description | rejected    |
