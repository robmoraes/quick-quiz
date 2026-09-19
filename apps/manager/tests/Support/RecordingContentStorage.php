<?php

namespace App\Tests\Support;

use App\Storage\ContentStorage;
use RuntimeException;

final class RecordingContentStorage implements ContentStorage
{
    public int $calls = 0;
    public bool $unavailable = false;

    public function __construct(private readonly ContentStorage $inner) {}
    public function description(): string { return $this->inner->description(); }
    public function location(string $key): string { return $this->inner->location($key); }
    public function exists(string $key): bool { $this->access(); return $this->inner->exists($key); }
    public function read(string $key): string { $this->access(); return $this->inner->read($key); }
    public function write(string $key, string $contents): void { $this->access(); $this->inner->write($key, $contents); }
    public function delete(string $key): bool { $this->access(); return $this->inner->delete($key); }
    public function list(string $prefix): array { $this->access(); return $this->inner->list($prefix); }

    private function access(): void
    {
        ++$this->calls;
        if ($this->unavailable) {
            throw new RuntimeException('Content storage is unavailable.');
        }
    }
}
