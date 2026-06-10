<?php

namespace App\Controller;

use App\Service\QuizPackService;
use App\Service\ThemeContext;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ThemeController extends BaseController
{
    #[Route('/themes', name: 'themes', methods: ['GET'])]
    public function index(QuizPackService $packs): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        return $this->render('theme/index.html.twig', [
            'themes' => $packs->listThemes(),
        ]);
    }

    #[Route('/themes/select', name: 'theme_select', methods: ['POST'])]
    public function select(QuizPackService $packs, ThemeContext $themeContext, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        try {
            $this->csrf->assertValid($request);
            $theme = (string) $request->request->get('theme', '');
            if ($packs->theme($theme) === null) {
                throw new RuntimeException('Theme not found.');
            }
            $themeContext->selectTheme($theme);
            return $this->redirect('catalog');
        } catch (RuntimeException $error) {
            return $this->render('theme/index.html.twig', [
                'themes' => $packs->listThemes(),
                'error' => $error->getMessage(),
            ]);
        }
    }

    #[Route('/themes/new', name: 'theme_new', methods: ['GET'])]
    public function new(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        return $this->render('theme/form.html.twig', [
            'theme' => ['active' => true, 'weight' => 100],
            'isNew' => true,
        ]);
    }

    #[Route('/themes/{id}/edit', name: 'theme_edit', methods: ['GET'])]
    public function edit(QuizPackService $packs, string $id): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        $theme = $packs->theme($id);
        if ($theme === null) {
            return $this->redirect('themes');
        }

        return $this->render('theme/form.html.twig', [
            'theme' => $theme,
            'isNew' => false,
        ]);
    }

    #[Route('/themes/save', name: 'theme_save', methods: ['POST'])]
    public function save(QuizPackService $packs, ThemeContext $themeContext, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        try {
            $this->csrf->assertValid($request);
            $packs->saveTheme($request->request->all());
            $selected = $themeContext->selectedTheme();
            if ($selected === '') {
                $themeContext->selectTheme((string) $request->request->get('id', ''));
            }
            return $this->redirect('themes');
        } catch (RuntimeException $error) {
            return $this->render('theme/form.html.twig', [
                'theme' => $request->request->all(),
                'isNew' => false,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
