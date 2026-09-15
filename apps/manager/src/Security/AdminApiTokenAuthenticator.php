<?php

namespace App\Security;

use App\Exception\AdminApiException;
use Symfony\Component\HttpFoundation\Request;

final class AdminApiTokenAuthenticator
{
    public function __construct(private readonly string $token)
    {
    }

    public function assertAuthorized(Request $request): void
    {
        $configured = trim($this->token);
        if (strlen($configured) < 32) {
            throw AdminApiException::disabled();
        }

        $authorization = trim((string) $request->headers->get('Authorization', ''));
        if (!preg_match('/^Bearer ([^\\s]+)$/', $authorization, $matches)) {
            throw AdminApiException::unauthorized();
        }

        if (!hash_equals($configured, $matches[1])) {
            throw AdminApiException::unauthorized();
        }
    }
}
