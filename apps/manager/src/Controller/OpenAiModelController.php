<?php

namespace App\Controller;

use App\Service\OpenAiModelProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OpenAiModelController extends BaseController
{
    #[Route('/openai/model', name: 'openai_model_select', methods: ['POST'])]
    public function select(OpenAiModelProvider $models, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        $this->csrf->assertValid($request);
        try {
            $models->selectModel((string) $request->request->get('model', ''));
        } catch (RuntimeException) {
        }

        $returnTo = (string) $request->request->get('returnTo', '');
        if ($returnTo !== '' && str_starts_with($returnTo, '/')) {
            return $this->redirectToPath($returnTo);
        }

        return $this->redirect($this->themeContext->selectedTheme() === '' ? 'themes' : 'catalog');
    }
}
