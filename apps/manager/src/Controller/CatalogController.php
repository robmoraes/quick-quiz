<?php

namespace App\Controller;

use App\Service\QuizPackService;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends BaseController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->redirect($this->themeContext->selectedTheme() === '' ? 'themes' : 'catalog');
    }

    #[Route('/catalog', name: 'catalog', methods: ['GET'])]
    public function catalog(QuizPackService $packs): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        return $this->render('catalog/index.html.twig', [
            'contentRoot' => $packs->contentRoot(),
            'theme' => $packs->selectedThemeMetadata(),
            'fallbackLocale' => $packs->fallbackLocale(),
            'supportedLocales' => $packs->supportedLocales(),
            'topics' => $packs->listTopics(),
            'validationErrors' => $packs->validateAll(),
        ]);
    }

    #[Route('/catalog/new', name: 'catalog_new', methods: ['GET'])]
    public function new(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        return $this->render('catalog/form.html.twig', [
            'topic' => ['active' => true, 'weight' => 100],
            'isNew' => true,
        ]);
    }

    #[Route('/catalog/{key}', name: 'catalog_edit', methods: ['GET'])]
    public function edit(QuizPackService $packs, string $key): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        foreach ($packs->listTopics() as $topic) {
            if ($topic['key'] === $key) {
                return $this->render('catalog/form.html.twig', ['topic' => $topic, 'isNew' => false]);
            }
        }

        return $this->redirect('catalog');
    }

    #[Route('/catalog/save', name: 'catalog_save', methods: ['POST'])]
    public function save(QuizPackService $packs, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        try {
            $this->csrf->assertValid($request);
            $packs->saveTopic($request->request->all());
            return $this->redirect('catalog');
        } catch (RuntimeException $error) {
            return $this->render('catalog/form.html.twig', [
                'topic' => $request->request->all(),
                'isNew' => false,
                'error' => $error->getMessage(),
            ]);
        }
    }

    #[Route('/catalog/{key}/delete', name: 'catalog_delete', methods: ['POST'])]
    public function delete(QuizPackService $packs, Request $request, string $key): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $this->csrf->assertValid($request);
        $packs->deleteTopic($key);
        return $this->redirect('catalog');
    }

    #[Route('/catalog/{locale}/{key}/localization', name: 'catalog_localization', methods: ['GET'])]
    public function localization(QuizPackService $packs, string $locale, string $key): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        $localized = ['key' => $key, 'name' => '', 'description' => ''];
        foreach ($packs->readLocalizedCatalog($locale)['topics'] as $topic) {
            if (($topic['key'] ?? '') === $key) {
                $localized = $topic;
                break;
            }
        }

        return $this->render('catalog/localization.html.twig', [
            'locale' => $locale,
            'topic' => $localized,
        ]);
    }

    #[Route('/catalog/{locale}/{key}/localization', name: 'catalog_localization_save', methods: ['POST'])]
    public function saveLocalization(QuizPackService $packs, Request $request, string $locale, string $key): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }

        try {
            $this->csrf->assertValid($request);
            $payload = $request->request->all();
            $payload['key'] = $key;
            $packs->saveLocalizedTopic($locale, $payload);
            return $this->redirect('catalog');
        } catch (RuntimeException $error) {
            return $this->render('catalog/localization.html.twig', [
                'locale' => $locale,
                'topic' => $request->request->all() + ['key' => $key],
                'error' => $error->getMessage(),
            ]);
        }
    }
}
