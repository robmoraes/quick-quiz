<?php

namespace App\Tests\Controller;

use App\Controller\QuizAdminController;
use App\Service\QuizAdministrationService;
use App\Service\QuizPackService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class QuizAdminControllerTest extends TestCase
{
    private string $root;
    private QuizAdminController $controller;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/quickquiz-admin-controller-test-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
        $this->controller = new QuizAdminController(
            new QuizAdministrationService(new QuizPackService($this->root, 'en-US', 'en-US,pt-BR')),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testReturnsStableJsonErrorForMalformedBody(): void
    {
        $request = Request::create('/api/admin/quiz/themes', 'POST', server: [
            'HTTP_X_REQUEST_ID' => 'request-123',
        ], content: '{broken');

        $response = $this->controller->createTheme($request);
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_json', $body['error']['code']);
        self::assertSame('request-123', $response->headers->get('X-Request-ID'));
    }

    public function testCreatesThemeWithExpectedStatusAndPublicationSignal(): void
    {
        $request = Request::create(
            '/api/admin/quiz/themes',
            'POST',
            content: json_encode([
                'id' => 'dev',
                'name' => 'Development',
                'description' => 'Software development.',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller->createTheme($request);
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('dev', $body['theme']['id']);
        self::assertTrue($body['publication']['apiReloadRequired']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) $response->headers->get('X-Request-ID'));
    }

    public function testRejectsInvalidDifficultyQuery(): void
    {
        $request = Request::create(
            '/api/admin/quiz/themes/dev/topics/git/questions?difficulty=hard',
            'GET',
        );

        $response = $this->controller->questions($request, 'dev', 'git');
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_difficulty', $body['error']['code']);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path.'/'.$item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
