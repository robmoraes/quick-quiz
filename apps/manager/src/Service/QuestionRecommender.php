<?php

namespace App\Service;

interface QuestionRecommender
{
    /**
     * @param list<string> $existingPrompts
     * @param array{theme?:string, key:string, name:string, description:string} $topic
     * @param array{label:string, optionCount:int, wrongRequired:int} $difficulty
     * @return array{prompt:string, correctOptions:list<string>, wrongOptions:list<string>}
     */
    public function recommend(string $locale, array $topic, int $difficultyId, array $difficulty, array $existingPrompts): array;
}
