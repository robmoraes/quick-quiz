<?php

namespace App\Tests\Support;

use App\Storage\ContentStorage;
use RuntimeException;

final class FaultInjectingContentStorage implements ContentStorage
{
    /** @var array<string,string> */
    private array $objects = [];
    private ?int $failureMutation = null;
    private int $mutations = 0;

    public function description(): string
    {
        return 'memory://quiz-test';
    }

    public function location(string $key): string
    {
        return $this->description().'/'.trim($key, '/');
    }

    public function exists(string $key): bool
    {
        return array_key_exists(trim($key, '/'), $this->objects);
    }

    public function read(string $key): string
    {
        $key = trim($key, '/');
        if (!array_key_exists($key, $this->objects)) {
            throw new RuntimeException('Could not read '.$this->location($key).'.');
        }

        return $this->objects[$key];
    }

    public function write(string $key, string $contents): void
    {
        $key = trim($key, '/');
        $this->objects[$key] = $contents;
        $this->failIfArmed();
    }

    public function delete(string $key): bool
    {
        $key = trim($key, '/');
        if (!array_key_exists($key, $this->objects)) {
            return false;
        }

        unset($this->objects[$key]);
        $this->failIfArmed();

        return true;
    }

    public function list(string $prefix): array
    {
        $prefix = trim($prefix, '/');
        $keys = array_values(array_filter(
            array_keys($this->objects),
            static fn (string $key): bool => $key === $prefix || str_starts_with($key, $prefix.'/'),
        ));
        sort($keys);

        return $keys;
    }

    public function failOnMutation(int $number): void
    {
        $this->failureMutation = $number;
        $this->mutations = 0;
    }

    /** @return array<string,string> */
    public function snapshot(): array
    {
        $objects = $this->objects;
        ksort($objects);

        return $objects;
    }

    private function failIfArmed(): void
    {
        ++$this->mutations;
        if ($this->failureMutation === $this->mutations) {
            throw new RuntimeException('Could not complete injected storage mutation.');
        }
    }
}
