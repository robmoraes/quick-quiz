<?php

namespace App\Runtime;

use RuntimeException;

final class SecretFileLoader
{
    public static function load(): void
    {
        $processEnvironment = getenv();
        $environment = array_merge(
            is_array($processEnvironment) ? $processEnvironment : [],
            $_SERVER,
            $_ENV,
        );

        foreach ($environment as $name => $filePath) {
            if (!is_string($name) || !str_ends_with($name, '__FILE')) {
                continue;
            }
            if (!is_string($filePath) || '' === trim($filePath)) {
                continue;
            }

            $target = substr($name, 0, -strlen('__FILE'));
            if ('' === $target) {
                throw new RuntimeException(sprintf('Invalid file-backed environment variable "%s".', $name));
            }

            $filePath = trim($filePath);
            if (!is_file($filePath) || !is_readable($filePath)) {
                throw new RuntimeException(sprintf('Cannot read %s from "%s".', $name, $filePath));
            }

            $contents = file_get_contents($filePath);
            if (false === $contents) {
                throw new RuntimeException(sprintf('Cannot read %s from "%s".', $name, $filePath));
            }
            $value = rtrim($contents, "\r\n");
            if ('' === $value) {
                throw new RuntimeException(sprintf('Cannot read %s from "%s": secret file is empty.', $name, $filePath));
            }

            $_ENV[$target] = $value;
            $_SERVER[$target] = $value;
            if (!putenv($target.'='.$value)) {
                throw new RuntimeException(sprintf('Cannot apply %s.', $name));
            }
        }
    }
}
