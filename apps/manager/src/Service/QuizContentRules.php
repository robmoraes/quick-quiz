<?php

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class QuizContentRules
{
    private const DIFFICULTIES = [
        1 => ['label' => 'easy', 'optionCount' => 3, 'wrongRequired' => 2],
        2 => ['label' => 'normal', 'optionCount' => 5, 'wrongRequired' => 4],
        3 => ['label' => 'hard', 'optionCount' => 7, 'wrongRequired' => 6],
        4 => ['label' => 'hardcore', 'optionCount' => 7, 'wrongRequired' => 6],
    ];

    /** @var list<string> */
    private array $supportedLocales;

    public function __construct(
        private readonly string $fallbackLocale,
        string $supportedLocales,
    ) {
        $locales = [$this->fallbackLocale];
        foreach (explode(',', $supportedLocales) as $locale) {
            $locale = trim($locale);
            if ($locale !== '') {
                $locales[] = $locale;
            }
        }
        $this->supportedLocales = array_values(array_unique($locales));
    }

    public function fallbackLocale(): string
    {
        return $this->fallbackLocale;
    }

    /** @return list<string> */
    public function supportedLocales(): array
    {
        return $this->supportedLocales;
    }

    /** @return array<int,array{label:string,optionCount:int,wrongRequired:int}> */
    public function difficulties(): array
    {
        return self::DIFFICULTIES;
    }

    public function assertSupportedLocale(string $locale): void
    {
        if (!in_array($locale, $this->supportedLocales, true)) {
            throw new RuntimeException(sprintf('Unsupported locale "%s".', $locale));
        }
    }

    public function assertDifficulty(int $difficulty): void
    {
        if (!isset(self::DIFFICULTIES[$difficulty])) {
            throw new RuntimeException(sprintf('Invalid difficulty "%d".', $difficulty));
        }
    }

    public function normalizeIdentifier(string $value, string $label): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $value)) {
            throw new RuntimeException(sprintf('Invalid %s.', $label));
        }

        return $value;
    }

    public function normalizeCreatedAtUtc(string $createdAt): string
    {
        $createdAt = trim($createdAt);
        if ($createdAt === '') {
            return '';
        }
        if (!preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $createdAt)) {
            throw new RuntimeException('Created at must be a valid datetime with timezone.');
        }

        try {
            return (new DateTimeImmutable($createdAt))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:sP');
        } catch (\Exception) {
            throw new RuntimeException('Created at must be a valid datetime with timezone.');
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array{prompt:string,correctOptions:list<string>,wrongOptions:list<string>}
     */
    public function questionPayload(array $input, int $difficulty): array
    {
        $this->assertDifficulty($difficulty);
        $question = $this->normalizeQuestionPayload($input);

        $errors = $this->questionPayloadErrors($question, $difficulty);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        return $question;
    }

    /** @param array<string,mixed> $question @return list<string> */
    public function questionPayloadErrors(array $question, int $difficulty): array
    {
        $this->assertDifficulty($difficulty);
        $errors = [];
        if (trim((string) ($question['prompt'] ?? '')) === '') {
            $errors[] = 'prompt is required.';
        }
        if (($question['correctOptions'] ?? []) === []) {
            $errors[] = 'correctOptions must contain at least one option.';
        }
        $wrongRequired = self::DIFFICULTIES[$difficulty]['wrongRequired'];
        if (count($question['wrongOptions'] ?? []) < $wrongRequired) {
            $errors[] = sprintf('wrongOptions must contain at least %d options for difficulty %d.', $wrongRequired, $difficulty);
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{prompt:string,correctOptions:list<string>,wrongOptions:list<string>}
     */
    public function normalizeQuestionPayload(array $input): array
    {
        return [
            'prompt' => trim((string) ($input['prompt'] ?? '')),
            'correctOptions' => $this->normalizeOptions($input['correctOptions'] ?? []),
            'wrongOptions' => $this->normalizeOptions($input['wrongOptions'] ?? []),
        ];
    }

    /** @param list<string> $existingIds */
    public function nextQuestionId(string $topic, int $difficulty, array $existingIds): string
    {
        $topic = $this->normalizeIdentifier($topic, 'topic key');
        $this->assertDifficulty($difficulty);
        $prefix = sprintf('%s-%d-', $topic, $difficulty);
        $highest = 0;

        foreach ($existingIds as $id) {
            if (!str_starts_with($id, $prefix)) {
                continue;
            }
            $suffix = substr($id, strlen($prefix));
            if (ctype_digit($suffix)) {
                $highest = max($highest, (int) $suffix);
            }
        }

        return sprintf('%s-%d-%03d', $topic, $difficulty, $highest + 1);
    }

    /** @param mixed $options @return list<string> */
    private function normalizeOptions(mixed $options): array
    {
        if (is_string($options)) {
            $options = preg_split('/\R/', $options) ?: [];
        }
        if (!is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $option) {
            $option = trim((string) $option);
            if ($option !== '') {
                $normalized[] = $option;
            }
        }

        return array_values(array_unique($normalized));
    }
}
