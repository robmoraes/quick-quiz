<?php

namespace App\Service;

final class QuizContentComparator
{
    /**
     * @param array<string,array<string,mixed>> $source
     * @param array<string,array<string,mixed>> $target
     * @return array{missing:list<string>,extra:list<string>,changed:list<string>,equal:bool}
     */
    public function compare(array $source, array $target): array
    {
        $missing = array_values(array_diff(array_keys($source), array_keys($target)));
        $extra = array_values(array_diff(array_keys($target), array_keys($source)));
        $changed = [];
        foreach (array_intersect(array_keys($source), array_keys($target)) as $key) {
            if ($this->canonical($source[$key]) !== $this->canonical($target[$key])) {
                $changed[] = $key;
            }
        }
        sort($missing);
        sort($extra);
        sort($changed);
        return compact('missing', 'extra', 'changed') + ['equal' => $missing === [] && $extra === [] && $changed === []];
    }

    /** @param array<string,array<string,mixed>> $objects */
    public function checksum(array $objects): string
    {
        ksort($objects);
        $canonical = [];
        foreach ($objects as $key => $value) {
            $canonical[$key] = $this->normalized($value);
        }
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $value */
    private function canonical(array $value): string
    {
        return json_encode($this->normalized($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function normalized(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->normalized($child);
        }
        return $value;
    }
}
