# QuickQuiz Dev MVP: frontend and backend flow

## Objective

Remodel the folder and JSON file structure that serves as the database for
Questions, Options, and Answers.

Allow the API to build the list of programming languages and frameworks
dynamically according to an index file;

Allow new languages to be prepared without the API publishing them immediately,
because it will start respecting the index file;

Allow the API to deliver metadata about the language or framework;

Secondary objective that we can do now or leave for the next stage if it is more
convenient: the API must deliver enough information for the frontend to know
which languages to display; which difficulties exist for the chosen language;
how many questions exist for the chosen language/difficulty. In this spec we
should solve only the backend; the frontend will be adapted after this spec is
complete and tested.

## Proposed Modeling

We will create the structure in the local environment, where the data is
currently defined as fake; from now on it becomes legitimate data.

### Model Template

- `WORKSPACE_DIR/backend/.local`
  - /pt-BR
    - /index.json
    - /programming_language_or_framework
      - /difficulty_id
        - /question_id.json
  - /en-US

### Architecture Example with the Model Implementation

- `WORKSPACE_DIR/backend/.local`
  - /pt-BR
    - /index.json
    - /php
      - /1
        - /php-1-1.json
        - /php-1-2.json
        - /php-1-3.json
      - /2
        - /php-2-1.json
      - /3
      - /4
    - /go
      - /1
        - /go-1-1.json
        - /go-1-2.json
      - /2
        - /go-2-1.json
      - /3
      - /4

### Role of the index.json File

`WORKSPACE_DIR/backend/.local/index.json`

```json
{
  "languages": [
    {
      "key": "php",
      "name": "General PHP",
      "description": "Version-agnostic language features",
      "weight": 100,
      "created_at": "2026-01-01T00:00:00-03"
    },
    {
      "key": "go",
      "name": "General Golang",
      "description": "Version-agnostic language features",
      "weight": 200,
      "created_at": "2026-01-01T00:00:00-03"
    }
  ]
}
```

| field       | description                                                                                 |
| ----------- | ------------------------------------------------------------------------------------------- |
| key         | unique key for the language or framework; correlates with the language folder name           |
| name        | friendly name of the language or framework                                                   |
| description | description of the expected type of question about the language                              |
| weight      | weight used to suggest ordering                                                             |
| created_at  | date and time with tz for publication of the language or framework                           |

## Question JSON Structure

The question JSON model can be simplified; the folder structure and file name
must define the question metadata.

From the folder structure and file name we can extract:

`WORKSPACE_DIR/backend/.local/en-US/php/1/php-1-1.json`

- id: `php-1-1`; part of the question JSON file name `php-1-1.json`
- language: `php`; correlates with the `key` field in `index.json`;
- difficulty: `1`;
- locale: `en-US`;

```json
{
  "prompt": "Which PHP tag starts a standard PHP code block?",
  "correctOptions": ["<?php"],
  "wrongOptions": [
    "<?",
    "<?=",
    "<script>",
    "<php>",
    "<%=",
    "<%php",
    "{{php}}",
    "<!--php-->",
    "<?ph",
    "<code>",
    "<server>",
    "<runtime>",
    "<%script",
    "<php?>",
    "<php start>",
    "<go>",
    "<?js",
    "<module>",
    "<block>",
    "<%"
  ]
}
```
