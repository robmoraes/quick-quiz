<?php

namespace App\Service;

use RuntimeException;

final class QuizTags
{
    /** @return list<string> */
    public static function normalize(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > 20) {
            throw new RuntimeException('Tags must be an array of at most 20 strings.');
        }
        $tags = [];
        foreach ($input as $tag) {
            if (!is_string($tag) || strlen($tag) > 50) {
                throw new RuntimeException('Each tag must be a string of at most 50 characters.');
            }
            $tag = strtolower(trim($tag, " \t\r\n\f\v"));
            if (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $tag)) {
                throw new RuntimeException('Tags must use letters a-z, digits, and single hyphens between words.');
            }
            $tags[] = $tag;
        }
        $tags = array_values(array_unique($tags));
        sort($tags, SORT_STRING);
        return $tags;
    }

    /** Published tags must already be canonical; absence is handled by the reader. */
    public static function fromPublication(mixed $input): array
    {
        $tags = self::normalize($input);
        if ($tags !== $input) {
            throw new RuntimeException('Published theme tags must be canonical, unique, and sorted.');
        }
        return $tags;
    }

    /** @return list<string> */
    public static function fromForm(mixed $input): array
    {
        if (!is_string($input)) {
            throw new RuntimeException('Tags must be comma-separated text.');
        }
        return self::normalize(trim($input, " \t\r\n\f\v") === '' ? [] : explode(',', $input));
    }
}
