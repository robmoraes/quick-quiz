<?php

namespace App\Controller;

use App\Exception\QuizPublicationException;
use App\Service\QuizAuthoringService;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class QuizPublicationController extends BaseController
{
    #[Route('/publication/retry', name: 'quiz_publication_retry', methods: ['POST'])]
    public function retry(Request $request, QuizAuthoringService $packs): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        try {
            $this->csrf->assertValid($request);
            $packs->retryPublication();
        } catch (QuizPublicationException) {
            // The persistent warning displays the durable failure state.
        } catch (RuntimeException) {
            return new Response('Publication retry is unavailable.', 503);
        }
        return $this->redirect('themes');
    }
}
