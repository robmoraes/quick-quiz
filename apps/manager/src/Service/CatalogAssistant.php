<?php

namespace App\Service;

interface CatalogAssistant
{
    public function suggestDescription(string $canonicalLocale, string $name): string;

    /** @return array{name:string, description:string} */
    public function canonicalize(string $canonicalLocale, string $name, string $description): array;

    /** @return array{name:string, description:string} */
    public function translate(string $targetLocale, string $name, string $description): array;
}
