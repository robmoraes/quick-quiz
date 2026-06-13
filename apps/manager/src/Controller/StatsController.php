<?php

namespace App\Controller;

use App\Service\QuizPackService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StatsController extends BaseController
{
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function index(QuizPackService $packs): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        return $this->render('stats/index.html.twig', [
            'stats' => $packs->contentStats(),
        ]);
    }
}
