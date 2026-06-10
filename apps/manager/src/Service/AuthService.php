<?php

namespace App\Service;

use App\Repository\AdminRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuthService
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function login(string $email, string $password): bool
    {
        $admin = $this->admins->findByEmail($email);
        if ($admin === null || !password_verify($password, $admin['password_hash'])) {
            return false;
        }

        $session = $this->requestStack->getSession();
        $session->migrate(true);
        $session->set('admin_id', $admin['id']);
        $session->set('admin_email', $admin['email']);
        return true;
    }

    public function logout(): void
    {
        $this->requestStack->getSession()->invalidate();
    }

    public function isAuthenticated(): bool
    {
        return $this->requestStack->getSession()->has('admin_id');
    }

    public function adminEmail(): string
    {
        return (string) $this->requestStack->getSession()->get('admin_email', '');
    }
}
