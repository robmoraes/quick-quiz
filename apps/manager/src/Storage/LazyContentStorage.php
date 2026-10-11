<?php

namespace App\Storage;

use Closure;

/** Defers storage construction and SDK credential resolution until an actual storage operation. */
final class LazyContentStorage implements IterableContentStorage
{
    private ?ContentStorage $storage = null;

    public function __construct(private readonly Closure $factory) {}

    private function storage(): ContentStorage
    {
        return $this->storage ??= ($this->factory)();
    }

    public function description(): string { return $this->storage()->description(); }
    public function location(string $key): string { return $this->storage()->location($key); }
    public function exists(string $key): bool { return $this->storage()->exists($key); }
    public function read(string $key): string { return $this->storage()->read($key); }
    public function write(string $key, string $contents): void { $this->storage()->write($key, $contents); }
    public function delete(string $key): bool { return $this->storage()->delete($key); }
    public function list(string $prefix): array { return $this->storage()->list($prefix); }
    public function iterate(string $prefix): iterable
    {
        $storage = $this->storage();
        yield from $storage instanceof IterableContentStorage ? $storage->iterate($prefix) : $storage->list($prefix);
    }
}
