<?php

namespace App\Exception;

use RuntimeException;

final class AdminApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $code, string $message): self
    {
        return new self($code, $message, 400);
    }

    public static function unauthorized(): self
    {
        return new self('unauthorized', 'A valid administrative Bearer token is required.', 401);
    }

    public static function disabled(): self
    {
        return new self('admin_api_disabled', 'The quiz administration API is not configured.', 503);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', $message, 404);
    }

    public static function conflict(string $message): self
    {
        return new self('conflict', $message, 409);
    }

    public static function validation(string $message): self
    {
        return new self('validation_failed', $message, 422);
    }
}
