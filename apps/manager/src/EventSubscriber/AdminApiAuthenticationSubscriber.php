<?php

namespace App\EventSubscriber;

use App\Exception\AdminApiException;
use App\Security\AdminApiTokenAuthenticator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 32)]
final class AdminApiAuthenticationSubscriber
{
    public function __construct(private readonly AdminApiTokenAuthenticator $authenticator)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/admin/quiz')) {
            return;
        }

        try {
            $this->authenticator->assertAuthorized($request);
        } catch (AdminApiException $error) {
            $response = new JsonResponse([
                'error' => [
                    'code' => $error->errorCode,
                    'message' => $error->getMessage(),
                ],
            ], $error->status);
            if ($error->status === 401) {
                $response->headers->set('WWW-Authenticate', 'Bearer');
            }
            $event->setResponse($response);
        }
    }
}
