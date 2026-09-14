<?php

namespace App\Storage;

use Aws\S3\S3Client;
use RuntimeException;

final class ContentStorageFactory
{
    public static function create(
        string $provider,
        string $contentRoot,
        string $region,
        string $bucket,
        string $prefix,
        string $endpointUrl,
        bool $forcePathStyle,
    ): ContentStorage {
        return match (strtolower(trim($provider))) {
            'local' => new LocalContentStorage($contentRoot),
            's3' => new S3ContentStorage(
                new S3Client(array_filter([
                    'version' => 'latest',
                    'region' => trim($region) === '' ? 'us-east-1' : trim($region),
                    'endpoint' => trim($endpointUrl) === '' ? null : trim($endpointUrl),
                    'use_path_style_endpoint' => $forcePathStyle,
                ], static fn (mixed $value): bool => $value !== null)),
                trim($bucket),
                trim($prefix, '/'),
            ),
            default => throw new RuntimeException(sprintf('Unsupported Manager content storage provider "%s".', $provider)),
        };
    }
}
