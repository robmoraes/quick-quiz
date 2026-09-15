<?php

namespace App\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use RuntimeException;

final class S3ContentStorage implements ContentStorage
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly string $prefix = '',
    ) {
        if (trim($this->bucket) === '') {
            throw new RuntimeException('S3 bucket is required for Manager content storage.');
        }
    }

    public function description(): string
    {
        $prefix = trim($this->prefix, '/');

        return 's3://'.$this->bucket.($prefix === '' ? '' : '/'.$prefix);
    }

    public function location(string $key): string
    {
        return 's3://'.$this->bucket.'/'.$this->objectKey($key);
    }

    public function exists(string $key): bool
    {
        try {
            $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->objectKey($key),
            ]);

            return true;
        } catch (AwsException $error) {
            if ($error->getStatusCode() === 404 || in_array($error->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true)) {
                return false;
            }

            throw $this->storageError('check', $key, $error);
        }
    }

    public function read(string $key): string
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->objectKey($key),
            ]);

            return (string) $result['Body'];
        } catch (AwsException $error) {
            throw $this->storageError('read', $key, $error);
        }
    }

    public function write(string $key, string $contents): void
    {
        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $this->objectKey($key),
                'Body' => $contents,
                'ContentType' => str_ends_with($key, '.json') ? 'application/json' : 'text/plain; charset=utf-8',
            ]);
        } catch (AwsException $error) {
            throw $this->storageError('write', $key, $error);
        }
    }

    public function delete(string $key): bool
    {
        if (!$this->exists($key)) {
            return false;
        }

        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $this->objectKey($key),
            ]);

            return true;
        } catch (AwsException $error) {
            throw $this->storageError('delete', $key, $error);
        }
    }

    public function list(string $prefix): array
    {
        $objectPrefix = $this->objectKey($prefix);
        if ($objectPrefix !== '') {
            $objectPrefix = rtrim($objectPrefix, '/').'/';
        }

        $keys = [];
        $continuationToken = null;
        try {
            do {
                $arguments = [
                    'Bucket' => $this->bucket,
                    'Prefix' => $objectPrefix,
                ];
                if (is_string($continuationToken) && $continuationToken !== '') {
                    $arguments['ContinuationToken'] = $continuationToken;
                }

                $result = $this->client->listObjectsV2($arguments);
                foreach ($result['Contents'] ?? [] as $object) {
                    $objectKey = (string) ($object['Key'] ?? '');
                    $logicalKey = $this->logicalKey($objectKey);
                    if ($logicalKey !== '') {
                        $keys[] = $logicalKey;
                    }
                }
                $continuationToken = filter_var($result['IsTruncated'] ?? false, FILTER_VALIDATE_BOOL)
                    ? (string) ($result['NextContinuationToken'] ?? '')
                    : null;
            } while (is_string($continuationToken) && $continuationToken !== '');
        } catch (AwsException $error) {
            throw $this->storageError('list', $prefix, $error);
        }

        sort($keys);

        return array_values(array_unique($keys));
    }

    private function objectKey(string $key): string
    {
        $prefix = trim($this->prefix, '/');
        $key = $this->normalizeKey($key);

        return implode('/', array_filter([$prefix, $key], static fn (string $part): bool => $part !== ''));
    }

    private function logicalKey(string $objectKey): string
    {
        $prefix = trim($this->prefix, '/');
        if ($prefix === '') {
            return $this->normalizeKey($objectKey);
        }
        if (!str_starts_with($objectKey, $prefix.'/')) {
            return '';
        }

        return $this->normalizeKey(substr($objectKey, strlen($prefix) + 1));
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

    private function storageError(string $operation, string $key, AwsException $error): RuntimeException
    {
        return new RuntimeException(
            sprintf('Could not %s Manager content at %s.', $operation, $this->location($key)),
            0,
            $error,
        );
    }
}
