<?php

namespace App\Controller;

use App\Service\ApiSessionMonitor;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SessionMonitorController extends BaseController
{
    #[Route('/sessions/active', name: 'session_monitor', methods: ['GET'])]
    public function index(ApiSessionMonitor $monitor): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        $error = null;
        $activeSessions = [
            'apiBaseUrl' => $monitor->apiBaseUrl(),
            'generatedAt' => '',
            'total' => 0,
            'sessions' => [],
        ];

        try {
            $activeSessions = $monitor->activeSessions();
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        return $this->render('session_monitor/index.html.twig', [
            'activeSessions' => $activeSessions,
            'monitorError' => $error,
        ]);
    }
}
