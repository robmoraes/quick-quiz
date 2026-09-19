<?php

namespace App\Service;

use App\Storage\ContentStorage;
use RuntimeException;
use Throwable;

/** Applies a JSON projection and restores prior objects if any operation fails. */
final class QuizProjectionPublisher
{
    public function __construct(private readonly ContentStorage $storage, private readonly QuizContentComparator $comparator)
    {
    }

    /**
     * @param array<string,array<string,mixed>> $objects
     * @param list<string>|null $keys Null publishes the complete projection.
     * @param list<string> $deletePrefixes
     * @return array{checksum:string,objectCount:int,previousObjects:array<string,string|null>}
     */
    public function publish(array $objects, ?array $keys = null, array $deletePrefixes = []): array
    {
        if ($keys === null) {
            $keys = array_merge(array_keys($objects), array_filter(
                $this->storage->list(''),
                fn (string $key): bool => $this->isQuizKey($key),
            ));
        }
        foreach ($deletePrefixes as $prefix) {
            $keys = array_merge($keys, array_filter(
                $this->storage->list($prefix),
                fn (string $key): bool => $this->isQuizKey($key),
            ));
        }
        $keys = array_values(array_unique(array_filter($keys,
            fn (string $key): bool => $this->isQuizKey($key),
        )));
        sort($keys);
        $previous = [];
        $changes = [];
        foreach ($keys as $key) {
            $previous[$key] = $this->storage->exists($key) ? $this->storage->read($key) : null;
            $next = isset($objects[$key]) ? $this->encode($objects[$key]) : null;
            if ($next !== $previous[$key]) {
                $changes[$key] = $next;
            }
        }
        $touched = [];
        try {
            foreach ($changes as $key => $next) {
                $touched[] = $key;
                if ($next === null) {
                    if (!$this->storage->delete($key)) {
                        throw new RuntimeException('Could not delete a publication object.');
                    }
                } else {
                    $this->storage->write($key, $next);
                }
            }
        } catch (Throwable $error) {
            $restored = true;
            try {
                $this->restore(array_intersect_key($previous, array_flip($touched)));
            } catch (Throwable) {
                $restored = false;
            }
            throw new RuntimeException(
                $restored ? 'Quiz publication failed; previous objects were restored.'
                    : 'Quiz publication failed and compensation was incomplete.',
                previous: $error,
            );
        }
        return [
            'checksum' => $this->comparator->checksum($objects),
            'objectCount' => count($changes),
            'previousObjects' => array_intersect_key($previous, $changes),
        ];
    }

    /** @param array<string,string|null> $previous */
    public function restore(array $previous): void
    {
        $failed = false;
        foreach (array_reverse($previous, true) as $key => $contents) {
            try {
                if ($contents === null) {
                    $this->storage->delete($key);
                } else {
                    $this->storage->write($key, $contents);
                }
            } catch (Throwable) {
                $failed = true;
            }
        }
        if ($failed) {
            throw new RuntimeException('Quiz publication compensation was incomplete.');
        }
    }

    private function isQuizKey(string $key): bool
    {
        return $key === 'themes.json'
            || preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._-]*/index\.json$~', $key) === 1
            || preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._-]*/[a-zA-Z0-9-]+/index\.json$~', $key) === 1
            || preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._-]*/[a-zA-Z0-9-]+/[a-zA-Z0-9][a-zA-Z0-9._-]*/[1-4]/[a-zA-Z0-9][a-zA-Z0-9._-]*\.json$~', $key) === 1;
    }

    /** @param array<string,mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}
