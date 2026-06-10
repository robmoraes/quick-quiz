<?php

namespace App\Controller;

use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends BaseController
{
    #[Route('/login', name: 'login', methods: ['GET'])]
    public function loginForm(): Response
    {
        return $this->render('auth/login.html.twig');
    }

    #[Route('/login', name: 'login_submit', methods: ['POST'])]
    public function login(Request $request): Response
    {
        try {
            $this->csrf->assertValid($request);
            if ($this->auth->login((string) $request->request->get('email'), (string) $request->request->get('password'))) {
                return $this->redirect('catalog');
            }
            throw new RuntimeException('Invalid email or password.');
        } catch (RuntimeException $error) {
            return $this->render('auth/login.html.twig', ['error' => $error->getMessage()]);
        }
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): RedirectResponse
    {
        $this->csrf->assertValid($request);
        $this->auth->logout();
        return $this->redirect('login');
    }
}
