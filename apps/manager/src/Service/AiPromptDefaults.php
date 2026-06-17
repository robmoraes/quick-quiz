<?php

namespace App\Service;

use RuntimeException;

final class AiPromptDefaults
{
    public const QUESTION_RECOMMENDATION = 'question_recommendation';
    public const ANSWER_RECOMMENDATION = 'answer_recommendation';
    public const QUESTION_LOCALIZATION_WITH_OPTIONS = 'question_localization_with_options';
    public const QUESTION_LOCALIZATION_PROMPT_ONLY = 'question_localization_prompt_only';
    public const CATALOG_DESCRIPTION_SUGGESTION = 'catalog_description_suggestion';
    public const CATALOG_CANONICALIZATION = 'catalog_canonicalization';
    public const CATALOG_TRANSLATION = 'catalog_translation';
    public const QUESTION_SOLUTION = 'question_solution';

    /**
     * @return array<string,array{key:string,title:string,description:string,defaultText:string}>
     */
    public static function all(): array
    {
        return [
            self::QUESTION_RECOMMENDATION => [
                'key' => self::QUESTION_RECOMMENDATION,
                'title' => 'Question draft generation',
                'description' => 'Creates one new QuickQuiz question draft with prompt and answers.',
                'defaultText' => implode("\n", [
                    'You recommend one new QuickQuiz question draft.',
                    'Use the requested locale to choose the human language of the draft.',
                    'Always return exactly {{ wrongOptionTarget }} wrongOptions.',
                    'Avoid repeating existing prompts exactly.',
                    'Use existing prompts only as context; they do not include answers.',
                    'If generationGuidance is provided, treat it as directional guidance for creating the question, not as the question prompt itself.',
                    'Do not copy generationGuidance into prompt unless that exact wording is genuinely the best generated question.',
                    'Do not choose or return locale, topic key, difficulty, question id, explanations, hints, or extra fields.',
                    'Return only data that matches the schema.',
                ]),
            ],
            self::ANSWER_RECOMMENDATION => [
                'key' => self::ANSWER_RECOMMENDATION,
                'title' => 'Answer option generation',
                'description' => 'Creates correct and wrong answer options for an existing prompt.',
                'defaultText' => implode("\n", [
                    'You recommend answer options for one existing QuickQuiz prompt.',
                    'Write answers in the same human language as the provided prompt.',
                    'Use the requested locale only as a formatting hint when the prompt language is ambiguous.',
                    'Do not rewrite, translate, summarize, or return the prompt.',
                    'Always return exactly {{ wrongOptionTarget }} wrongOptions.',
                    'Return only correctOptions and wrongOptions that match the schema.',
                ]),
            ],
            self::QUESTION_LOCALIZATION_WITH_OPTIONS => [
                'key' => self::QUESTION_LOCALIZATION_WITH_OPTIONS,
                'title' => 'Question localization with answers',
                'description' => 'Localizes question prompt and answer options for requested locales.',
                'defaultText' => implode("\n", [
                    'You localize QuickQuiz question JSON.',
                    'Detect the human language used by the input question.',
                    'Return localized content for every requested BCP 47 locale as an array of localization items.',
                    'Localize prompt, correctOptions, and wrongOptions for every requested locale.',
                    'Preserve answer semantics: correct options remain correct and wrong options remain wrong.',
                    'Do not add, remove, merge, split, reorder, or duplicate options.',
                    'Do not include locale, topic key, difficulty, question id, explanations, hints, or extra fields.',
                    'Return only data that matches the schema.',
                ]),
            ],
            self::QUESTION_LOCALIZATION_PROMPT_ONLY => [
                'key' => self::QUESTION_LOCALIZATION_PROMPT_ONLY,
                'title' => 'Question prompt-only localization',
                'description' => 'Localizes only the prompt and copies answer options unchanged.',
                'defaultText' => implode("\n", [
                    'You localize QuickQuiz question JSON.',
                    'Detect the human language used by the input question.',
                    'Return localized content for every requested BCP 47 locale as an array of localization items.',
                    'Localize only prompt. Copy correctOptions and wrongOptions exactly as provided for every requested locale.',
                    'Preserve answer semantics: correct options remain correct and wrong options remain wrong.',
                    'Do not add, remove, merge, split, reorder, or duplicate options.',
                    'Do not include locale, topic key, difficulty, question id, explanations, hints, or extra fields.',
                    'Return only data that matches the schema.',
                ]),
            ],
            self::CATALOG_DESCRIPTION_SUGGESTION => [
                'key' => self::CATALOG_DESCRIPTION_SUGGESTION,
                'title' => 'Catalog topic description suggestion',
                'description' => 'Writes a concise canonical topic description from a topic name.',
                'defaultText' => implode("\n", [
                    'You write concise QuickQuiz catalog topic descriptions.',
                    'Use the requested canonical BCP 47 locale for the description language.',
                    'Return only the name and description fields that match the schema.',
                    'Keep name exactly as provided.',
                    'Write one short operational description suitable for an admin catalog.',
                ]),
            ],
            self::CATALOG_CANONICALIZATION => [
                'key' => self::CATALOG_CANONICALIZATION,
                'title' => 'Catalog topic canonicalization',
                'description' => 'Normalizes topic name and description into the canonical locale.',
                'defaultText' => implode("\n", [
                    'You normalize QuickQuiz catalog topic metadata.',
                    'Detect the human language used by the provided name and description.',
                    'Translate or rewrite both fields into the requested canonical BCP 47 locale.',
                    'Return only the name and description fields that match the schema.',
                    'Do not add explanations, locale fields, metadata, or extra keys.',
                ]),
            ],
            self::CATALOG_TRANSLATION => [
                'key' => self::CATALOG_TRANSLATION,
                'title' => 'Catalog topic translation',
                'description' => 'Translates topic name and description into a target locale.',
                'defaultText' => implode("\n", [
                    'You translate QuickQuiz catalog topic metadata.',
                    'Translate both fields into the requested target BCP 47 locale.',
                    'Return only the name and description fields that match the schema.',
                    'Do not add explanations, locale fields, metadata, or extra keys.',
                ]),
            ],
            self::QUESTION_SOLUTION => [
                'key' => self::QUESTION_SOLUTION,
                'title' => 'Question solution explanation',
                'description' => 'Explains why the correct answer is correct when the API lazily creates a solution.',
                'defaultText' => implode("\n", [
                    'You explain the correct answer for a programming quiz question.',
                    'Use only the requested locale for natural language.',
                    'Preserve code, identifiers, commands, APIs, and technical names exactly as they should appear.',
                    'Explain why the correct answer is correct and briefly address the main misconception behind the wrong answers.',
                    'Return only the final explanation text.',
                ]),
            ],
        ];
    }

    /** @return array{key:string,title:string,description:string,defaultText:string} */
    public static function get(string $key): array
    {
        $prompts = self::all();
        if (!isset($prompts[$key])) {
            throw new RuntimeException(sprintf('Unknown AI prompt key "%s".', $key));
        }

        return $prompts[$key];
    }

    /** @param array<string,int|string> $variables */
    public static function renderDefault(string $key, array $variables = []): string
    {
        return self::render(self::get($key)['defaultText'], $variables);
    }

    /** @param array<string,int|string> $variables */
    public static function render(string $text, array $variables = []): string
    {
        foreach ($variables as $name => $value) {
            $text = str_replace('{{ '.$name.' }}', (string) $value, $text);
        }

        return $text;
    }
}
