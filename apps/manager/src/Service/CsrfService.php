<?php

namespace App\Service;

use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CsrfService
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function token(): string
    {
        $session = $this->requestStack->getSession();
        $token = $session->get('csrf_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->set('csrf_token', $token);
        }
        return $token;
    }

    public function assertValid(Request $request): void
    {
        $submitted = (string) $request->request->get('_csrf', '');
        if (!hash_equals($this->token(), $submitted)) {
            throw new RuntimeException('Invalid CSRF token.');
        }
    }
}
