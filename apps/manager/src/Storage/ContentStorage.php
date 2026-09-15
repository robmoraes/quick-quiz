<?php

namespace App\Storage;

interface ContentStorage
{
    public function description(): string;

    public function location(string $key): string;

    public function exists(string $key): bool;

    public function read(string $key): string;

    public function write(string $key, string $contents): void;

    public function delete(string $key): bool;

    /** @return list<string> */
    public function list(string $prefix): array;
}
