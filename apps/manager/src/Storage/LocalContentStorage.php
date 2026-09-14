<?php

namespace App\Storage;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class LocalContentStorage implements ContentStorage
{
    public function __construct(private readonly string $root)
    {
    }

    public function description(): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR);
    }

    public function location(string $key): string
    {
        $key = $this->normalizeKey($key);
        $root = $this->description();

        return $key === ''
            ? $root
            : $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $key);
    }

    public function exists(string $key): bool
    {
        return is_file($this->location($key));
    }

    public function read(string $key): string
    {
        $contents = file_get_contents($this->location($key));
        if (!is_string($contents)) {
            throw new RuntimeException(sprintf('Could not read %s.', $this->location($key)));
        }

        return $contents;
    }

    public function write(string $key, string $contents): void
    {
        $path = $this->location($key);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create directory %s.', $directory));
        }

        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Could not write %s.', $temporary));
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException(sprintf('Could not replace %s.', $path));
        }
    }

    public function delete(string $key): bool
    {
        $path = $this->location($key);
        if (!is_file($path)) {
            return false;
        }
        if (!unlink($path)) {
            throw new RuntimeException(sprintf('Could not delete %s.', $path));
        }

        return true;
    }

    public function list(string $prefix): array
    {
        $prefix = $this->normalizeKey($prefix);
        $path = $this->location($prefix);
        if (is_file($path)) {
            return [$prefix];
        }
        if (!is_dir($path)) {
            return [];
        }

        $rootLength = strlen($this->description()) + 1;
        $keys = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $keys[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), $rootLength));
        }
        sort($keys);

        return $keys;
    }

    private function normalizeKey(string $key): string
    {
        $key = trim(str_replace('\\', '/', $key), '/');
        foreach (explode('/', $key) as $part) {
            if ($part === '.' || $part === '..') {
                throw new RuntimeException('Content storage key contains an invalid path segment.');
            }
        }

        return $key;
    }
}
