<?php

namespace App\Tests\Controller;

use App\Controller\CatalogController;
use App\Repository\AdminRepository;
use App\Service\AuthService;
use App\Service\CatalogAssistant;
use App\Service\CsrfService;
use App\Service\ManagerVersion;
use App\Service\OpenAiConfiguration;
use App\Service\QuizAuthoringService;
use App\Service\ThemeContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class CatalogTopicTagsTest extends TestCase
{
    public function testAiSuggestionAndFailureKeepUnsavedTags(): void
    {
        $request = Request::create('/catalog/ai/suggest-description', 'POST', [
            '_csrf' => 'valid', 'name' => 'AWS', 'tags' => ' AWS , redes', 'isNew' => '1',
        ]);
        [$controller, $packs, $openAi] = $this->controller($request);
        $assistant = $this->createMock(CatalogAssistant::class);
        $assistant->method('suggestDescription')->willReturn('Suggested');
        $response = $controller->suggestDescription($packs, $assistant, $openAi, $request);
        self::assertSame(' AWS , redes|Suggested|', $response->getContent());
        $assistant = $this->createMock(CatalogAssistant::class);
        $assistant->method('canonicalize')->willThrowException(new RuntimeException('AI unavailable'));
        $response = $controller->saveWithAi($packs, $assistant, $openAi, $request);
        self::assertSame(' AWS , redes||AI unavailable', $response->getContent());
    }

    public function testAiSavePassesTagsWithTranslationsAndValidatesBeforeCallingAi(): void
    {
        $request = Request::create('/catalog/ai/save', 'POST', [
            '_csrf' => 'valid', 'key' => 'aws', 'name' => 'AWS', 'tags' => ' AWS , redes,aws',
        ]);
        [$controller, $packs, $openAi] = $this->controller($request);
        $assistant = $this->createMock(CatalogAssistant::class);
        $assistant->expects(self::once())->method('canonicalize')->willReturn(['name' => 'Cloud', 'description' => 'Canonical']);
        $assistant->expects(self::once())->method('translate')->willReturn(['name' => 'Nuvem', 'description' => 'Traduzida']);
        $packs->expects(self::once())->method('saveTopicSet')->with(
            self::callback(fn (array $input): bool => $input['tags'] === ['aws', 'redes'] && $input['name'] === 'Cloud'),
            ['pt-BR' => ['name' => 'Nuvem', 'description' => 'Traduzida']],
        )->willReturn(['apiReloadRequired' => true]);
        self::assertSame(302, $controller->saveWithAi($packs, $assistant, $openAi, $request)->getStatusCode());
        $request->request->set('tags', 'invalid tag');
        $response = $controller->saveWithAi($packs, $assistant, $openAi, $request);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('invalid tag|', (string) $response->getContent());
    }

    private function controller(Request $request): array
    {
        $session = new Session(new MockArraySessionStorage());
        foreach (['admin_id' => 1, 'admin_email' => 'test@example.invalid', 'selected_theme' => 'study', 'csrf_token' => 'valid'] as $key => $value) {
            $session->set($key, $value);
        }
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);
        $packs = $this->createMock(QuizAuthoringService::class);
        $packs->method('fallbackLocale')->willReturn('en-US');
        $packs->method('supportedLocales')->willReturn(['en-US', 'pt-BR']);
        $url = $this->createMock(UrlGeneratorInterface::class);
        $url->method('generate')->willReturn('/catalog');
        $openAi = new OpenAiConfiguration('test-key', 'test-model');
        $twig = new Environment(new ArrayLoader([
            'catalog/form.html.twig' => '{{ topic.tags }}|{{ topic.description|default("") }}|{{ error|default("") }}',
        ]));
        return [new CatalogController($twig, new AuthService(new AdminRepository('sqlite:///:memory:'), $stack),
            new CsrfService($stack), $url, new ThemeContext($stack), $openAi, new ManagerVersion('test'), $packs, $stack), $packs, $openAi];
    }
}
