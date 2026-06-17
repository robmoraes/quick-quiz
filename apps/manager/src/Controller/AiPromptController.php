<?php

namespace App\Controller;

use App\Repository\AiPromptRepository;
use App\Service\AiPromptImportService;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AiPromptController extends BaseController
{
    #[Route('/ai-prompts', name: 'ai_prompts', methods: ['GET'])]
    public function index(AiPromptRepository $prompts, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }
        $theme = $this->themeContext->requireSelectedTheme();

        return $this->render('ai_prompt/index.html.twig', [
            'promptTheme' => $theme,
            'prompts' => $prompts->listPrompts($theme),
            'importedCount' => $request->query->getInt('imported', -1),
        ]);
    }

    #[Route('/ai-prompts/import', name: 'ai_prompts_import', methods: ['POST'])]
    public function import(AiPromptRepository $prompts, AiPromptImportService $importer, Request $request): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }
        $theme = $this->themeContext->requireSelectedTheme();

        try {
            $this->csrf->assertValid($request);
            $file = $request->files->get('promptFile');
            if (!$file instanceof UploadedFile) {
                throw new RuntimeException('Select an AI prompt JSON file to import.');
            }
            if (!$file->isValid()) {
                throw new RuntimeException('The uploaded AI prompt JSON file is not valid.');
            }

            $contents = file_get_contents($file->getPathname());
            if (!is_string($contents)) {
                throw new RuntimeException('Could not read the uploaded AI prompt JSON file.');
            }

            $count = $importer->importJson($theme, $contents);

            return $this->redirect('ai_prompts', ['imported' => $count]);
        } catch (RuntimeException $error) {
            return $this->render('ai_prompt/index.html.twig', [
                'promptTheme' => $theme,
                'prompts' => $prompts->listPrompts($theme),
                'importedCount' => -1,
                'error' => $error->getMessage(),
            ]);
        }
    }

    #[Route('/ai-prompts/{key}/edit', name: 'ai_prompt_edit', requirements: ['key' => '[a-z0-9_]+'], methods: ['GET'])]
    public function edit(AiPromptRepository $prompts, string $key): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }
        $theme = $this->themeContext->requireSelectedTheme();

        try {
            return $this->render('ai_prompt/form.html.twig', [
                'promptTheme' => $theme,
                'prompt' => $prompts->prompt($theme, $key),
            ]);
        } catch (RuntimeException) {
            return $this->redirect('ai_prompts');
        }
    }

    #[Route('/ai-prompts/{key}/save', name: 'ai_prompt_save', requirements: ['key' => '[a-z0-9_]+'], methods: ['POST'])]
    public function save(AiPromptRepository $prompts, Request $request, string $key): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }
        $theme = $this->themeContext->requireSelectedTheme();

        try {
            $this->csrf->assertValid($request);
            $prompts->save($theme, $key, (string) $request->request->get('text', ''));

            return $this->redirect('ai_prompts');
        } catch (RuntimeException $error) {
            try {
                $prompt = $prompts->prompt($theme, $key);
            } catch (RuntimeException) {
                return $this->redirect('ai_prompts');
            }

            $prompt['text'] = (string) $request->request->get('text', '');

            return $this->render('ai_prompt/form.html.twig', [
                'promptTheme' => $theme,
                'prompt' => $prompt,
                'error' => $error->getMessage(),
            ]);
        }
    }

    #[Route('/ai-prompts/{key}/restore', name: 'ai_prompt_restore', requirements: ['key' => '[a-z0-9_]+'], methods: ['POST'])]
    public function restore(AiPromptRepository $prompts, Request $request, string $key): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($redirect = $this->requireSelectedTheme()) {
            return $redirect;
        }
        $theme = $this->themeContext->requireSelectedTheme();

        $this->csrf->assertValid($request);
        $prompts->restoreDefault($theme, $key);

        return $this->redirect('ai_prompt_edit', ['key' => $key]);
    }
}
