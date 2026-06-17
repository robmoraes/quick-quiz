<?php

namespace App\Controller;

use App\Service\AuthService;
use App\Service\CsrfService;
use App\Service\ManagerVersion;
use App\Service\OpenAiConfiguration;
use App\Service\ThemeContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

abstract class BaseController
{
    public function __construct(
        protected readonly Environment $twig,
        protected readonly AuthService $auth,
        protected readonly CsrfService $csrf,
        protected readonly UrlGeneratorInterface $url,
        protected readonly ThemeContext $themeContext,
        protected readonly OpenAiConfiguration $openAi,
        protected readonly ManagerVersion $managerVersion,
        protected readonly RequestStack $requestStack,
    ) {
    }

    protected function requireAuth(): ?RedirectResponse
    {
        if ($this->auth->isAuthenticated()) {
            return null;
        }
        return new RedirectResponse($this->url->generate('login'));
    }

    protected function requireSelectedTheme(): ?RedirectResponse
    {
        if ($this->themeContext->selectedTheme() !== '') {
            return null;
        }
        return new RedirectResponse($this->url->generate('themes'));
    }

    /** @param array<string,mixed> $context */
    protected function render(string $template, array $context = []): Response
    {
        $context['adminEmail'] = $this->auth->adminEmail();
        $context['selectedTheme'] = $this->themeContext->selectedTheme();
        $context['csrf'] = $this->csrf->token();
        $context['openAiConfigured'] = $this->openAi->isConfigured();
        $context['openAiModel'] = $this->openAi->model();
        $context['openAiModels'] = $this->openAi->availableModels();
        $context['managerVersion'] = $this->managerVersion->value();
        $request = $this->requestStack->getCurrentRequest();
        $context['currentPath'] = $request === null ? '/' : $request->getRequestUri();
        return new Response($this->twig->render($template, $context));
    }

    protected function redirect(string $route, array $parameters = []): RedirectResponse
    {
        return new RedirectResponse($this->url->generate($route, $parameters));
    }

    protected function redirectToPath(string $path): RedirectResponse
    {
        return new RedirectResponse($path);
    }
}
