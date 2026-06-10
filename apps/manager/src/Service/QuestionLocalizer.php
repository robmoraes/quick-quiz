<?php

namespace App\Service;

interface QuestionLocalizer
{
    /**
     * @param array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>} $question
     * @param list<string> $locales
     * @return array{detectedLanguage:string, localizations:array<string,array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}>}
     */
    public function localize(array $question, array $locales, bool $translateOptions = true): array;
}
