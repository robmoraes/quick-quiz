<?php

namespace App\Controller;

use App\Service\AdService;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdController extends BaseController
{
    #[Route('/ads', name: 'ads', methods: ['GET'])]
    public function index(AdService $ads, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        $theme = trim((string) $request->query->get('theme', ''));

        return $this->render('ad/index.html.twig', [
            'contentRoot' => $ads->contentRoot(),
            'adsApiBaseUrl' => $ads->adsApiBaseUrl(),
            'adsFileExists' => $ads->exists(),
            'ads' => $ads->listAds($theme),
            'themes' => $ads->listThemes(),
            'selectedAdTheme' => $theme,
        ]);
    }

    #[Route('/ads/create-file', name: 'ads_create_file', methods: ['POST'])]
    public function createFile(AdService $ads, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        $this->csrf->assertValid($request);
        $ads->createBaseFile();

        return $this->redirect('ads');
    }

    #[Route('/ads/new', name: 'ad_new', methods: ['GET'])]
    public function new(AdService $ads): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if (!$ads->exists()) {
            return $this->redirect('ads');
        }

        return $this->render('ad/form.html.twig', [
            'ad' => $ads->emptyAd(),
            'themes' => $ads->listThemes(),
            'topicsByTheme' => $ads->listTopicsByTheme(),
            'index' => null,
            'isNew' => true,
        ]);
    }

    #[Route('/ads/{id}/edit', name: 'ad_edit', requirements: ['id' => '[0-9a-fA-F-]+'], methods: ['GET'])]
    public function edit(AdService $ads, string $id): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        $ad = $ads->ad($id);
        if ($ad === null) {
            return $this->redirect('ads');
        }

        return $this->render('ad/form.html.twig', [
            'ad' => $ad,
            'themes' => $ads->listThemes(),
            'topicsByTheme' => $ads->listTopicsByTheme(),
            'isNew' => false,
        ]);
    }

    #[Route('/ads/save', name: 'ad_save', methods: ['POST'])]
    public function save(AdService $ads, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        $id = trim((string) $request->request->get('id', ''));
        $isNew = $id === '';

        try {
            $this->csrf->assertValid($request);
            $ads->saveAd($isNew ? null : $id, $request->request->all());

            return $this->redirect('ads');
        } catch (RuntimeException $error) {
            return $this->render('ad/form.html.twig', [
                'ad' => $ads->formAd($request->request->all()),
                'themes' => $ads->listThemes(),
                'topicsByTheme' => $ads->listTopicsByTheme(),
                'isNew' => $isNew,
                'error' => $error->getMessage(),
            ]);
        }
    }

    #[Route('/ads/{id}/delete', name: 'ad_delete', requirements: ['id' => '[0-9a-fA-F-]+'], methods: ['POST'])]
    public function delete(AdService $ads, Request $request, string $id): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }

        $this->csrf->assertValid($request);
        $ads->deleteAd($id);

        return $this->redirect('ads');
    }
}
