<?php

namespace App\Tests\Controller;

use App\Kernel;
use App\Repository\ManagerDatabase;
use App\Repository\MigrationRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class QuizAuthoringKernelTest extends TestCase
{
    public function testLegacyProviderServesRequestsThroughCompiledKernel(): void
    {
        $this->assertProviderServesRequests('legacy', 'sqlite:///:memory:');
    }

    public function testPostgresProviderServesRequestsThroughCompiledKernel(): void
    {
        $url = (string) getenv('MANAGER_DATABASE_URL');
        if (!str_starts_with($url, 'postgres://') && !str_starts_with($url, 'postgresql://')) {
            self::markTestSkipped('MANAGER_DATABASE_URL is not PostgreSQL.');
        }
        (new MigrationRunner(new ManagerDatabase($url), dirname(__DIR__, 2).'/migrations'))->migrate();
        $this->assertProviderServesRequests('postgres', $url);
    }

    private function assertProviderServesRequests(string $provider, string $databaseUrl): void
    {
        $suffix = bin2hex(random_bytes(6));
        $contentRoot = sys_get_temp_dir().'/quiz-kernel-content-'.$suffix;
        mkdir($contentRoot, 0700);
        $environment = [
            'APP_SECRET' => 'kernel-regression-secret',
            'MANAGER_DATABASE_URL' => $databaseUrl,
            'MANAGER_CONTENT_ROOT' => $contentRoot,
            'MANAGER_CONTENT_STORAGE_PROVIDER' => 'local',
            'MANAGER_QUIZ_PERSISTENCE_PROVIDER' => $provider,
            'MANAGER_ADMIN_API_TOKEN' => 'kernel-regression-token-0123456789abcdef',
            'FALLBACK_LOCALE' => 'en-US',
            'SUPPORTED_LOCALES' => 'en-US,pt-BR',
            'OPENAI_API_KEY' => '',
            'OPENAI_MODEL' => '',
        ];
        foreach (array_keys($environment) as $name) {
            $environment[$name.'__FILE'] = null;
        }
        $previous = [];
        foreach ($environment as $name => $value) {
            $previous[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            if ($value === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$value);
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
        // No environment-specific package overrides exist; debug=false exercises
        // the dumped container used by production without sharing its cache path.
        $kernel = new Kernel('factory_smoke_'.$suffix, false);
        $theme = 'kernel-tags-'.$suffix;
        try {
            $request = Request::create('/api/admin/quiz/publication', server: [
                'HTTP_AUTHORIZATION' => 'Bearer kernel-regression-token-0123456789abcdef',
            ]);
            $response = $kernel->handle($request);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame($provider, json_decode((string) $response->getContent(), true)['publication']['provider']);
            $kernel->terminate($request, $response);

            $session = new Session(new MockArraySessionStorage());
            $session->set('admin_id', 1);
            $session->set('admin_email', 'kernel-test@example.invalid');
            $session->set('openai.available_models', []);
            $request = Request::create('/themes');
            $request->setSession($session);
            $response = $kernel->handle($request);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            self::assertStringContainsString('<html', (string) $response->getContent());
            $kernel->terminate($request, $response);
            $this->assertTopicTags($kernel, $session, $provider, $theme);
        } finally {
            if ($provider === 'postgres') {
                $db = (new ManagerDatabase($databaseUrl))->connection();
                $db->prepare('DELETE FROM quiz_themes WHERE id=:theme')->execute(['theme' => $theme]);
                $db->prepare('DELETE FROM quiz_publications WHERE affected_theme_id=:theme')->execute(['theme' => $theme]);
            }
            $kernel->shutdown();
            (new Filesystem())->remove([$kernel->getCacheDir(), $contentRoot]);
            foreach ($previous as $name => [$process, $env, $server]) {
                $process === false ? putenv($name) : putenv($name.'='.$process);
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }

    private function assertTopicTags(Kernel $kernel, Session $session, string $provider, string $theme): void
    {
        $base = '/api/admin/quiz/themes/'.$theme;
        $this->api($kernel, '/api/admin/quiz/themes', 'POST', ['id' => $theme, 'name' => 'Tag forms'], 201);
        $session->set('selected_theme', $theme);
        $payload = ['key' => 'aws', 'name' => 'AWS', 'description' => '', 'weight' => 10, 'active' => true];
        $unauthenticated = Request::create($base.'/topics', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload));
        $response = $kernel->handle($unauthenticated);
        self::assertSame(401, $response->getStatusCode());
        $kernel->terminate($unauthenticated, $response);
        $this->api($kernel, $base.'/topics', 'POST', $payload, 201);
        $html = $this->form($kernel, $session, '/catalog/aws');
        if ($provider === 'legacy') {
            self::assertStringNotContainsString('id="tags"', $html);
            foreach (['PUT', 'POST'] as $method) {
                $path = $base.'/topics'.($method === 'PUT' ? '/aws' : '');
                $input = array_replace($payload, ['key' => 'new', 'tags' => []]);
                $error = $this->api($kernel, $path, $method, $input, 409);
                self::assertSame('topic_tags_unavailable', $error['error']['code']);
            }
            self::assertArrayNotHasKey('tags', $this->api($kernel, $base.'/topics/aws')['topic']);
            self::assertCount(1, $this->api($kernel, $base.'/topics')['topics']);
            return;
        }
        self::assertStringContainsString('id="tags"', $html);
        $before = $this->api($kernel, '/api/admin/quiz/publication');
        $result = $this->api($kernel, $base.'/topics/aws', 'PUT', $payload + ['tags' => [' AWS ', 'redes']]);
        self::assertSame(['aws', 'redes'], $result['topic']['tags']);
        self::assertSame(['apiReloadRequired' => false, 'reason' => 'topic_tags_only'], $result['publication']);
        self::assertSame($before, $this->api($kernel, '/api/admin/quiz/publication'));
        $html = $this->form($kernel, $session, '/catalog/aws');
        self::assertStringContainsString('value="aws,redes"', $html);
        self::assertStringContainsString('>aws</span>', $this->form($kernel, $session, '/catalog'));
        $form = $payload + ['_csrf' => $session->get('csrf_token'), 'isNew' => '0'];
        $this->form($kernel, $session, '/catalog/save', $form + ['tags' => ' AWS , cloud , aws '], 302);
        self::assertSame(['aws', 'cloud'], $this->api($kernel, $base.'/topics/aws')['topic']['tags']);
        self::assertSame($before, $this->api($kernel, '/api/admin/quiz/publication'));
        $invalid = $this->form($kernel, $session, '/catalog/save', $form + ['tags' => 'aws, <script>alert(1)</script>']);
        self::assertStringContainsString('id="topic-error"', $invalid);
        self::assertStringContainsString('&lt;script&gt;', $invalid);
        self::assertStringNotContainsString('<script>alert(1)</script>', $invalid);
        self::assertSame(['aws', 'cloud'], $this->api($kernel, $base.'/topics/aws')['topic']['tags']);
        $this->form($kernel, $session, '/catalog/save', array_replace($form, ['_csrf' => 'invalid', 'tags' => 'bad-csrf']));
        self::assertSame(['aws', 'cloud'], $this->api($kernel, $base.'/topics/aws')['topic']['tags']);
        $this->form($kernel, $session, '/catalog/save', $form + ['tags' => ''], 302);
        self::assertSame([], $this->api($kernel, $base.'/topics/aws')['topic']['tags']);
        self::assertSame($before, $this->api($kernel, '/api/admin/quiz/publication'));
        $this->form($kernel, $session, '/catalog/new');
        $this->form($kernel, $session, '/catalog/save', array_replace($form, ['key' => 'new', 'isNew' => '1', 'tags' => ' AWS ']), 302);
        self::assertSame(['aws'], $this->api($kernel, $base.'/topics/new')['topic']['tags']);
        $oldClient = $this->api($kernel, $base.'/topics/new', 'PUT', array_replace($payload, ['name' => 'Changed by old client']));
        self::assertSame(['aws'], $oldClient['topic']['tags']);
        self::assertTrue($oldClient['publication']['apiReloadRequired']);
        $longTag = str_repeat('z', 50);
        $this->api($kernel, $base.'/topics/aws', 'PUT', $payload + ['tags' => ['aws', $longTag]]);
        $html = $this->form($kernel, $session, '/catalog/aws');
        self::assertStringContainsString('value="aws,'.$longTag.'"', $html);
        $this->form($kernel, $session, '/catalog/save', $form + ['tags' => 'aws,'.$longTag], 302);
        self::assertSame(['aws', $longTag], $this->api($kernel, $base.'/topics/aws')['topic']['tags']);
    }

    /** @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function api(Kernel $kernel, string $path, string $method = 'GET', ?array $payload = null, int $status = 200): array
    {
        $request = Request::create($path, $method, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer kernel-regression-token-0123456789abcdef',
        ], content: $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed>|null $payload */
    private function form(Kernel $kernel, Session $session, string $path, ?array $payload = null, int $status = 200): string
    {
        $request = Request::create($path, $payload === null ? 'GET' : 'POST', $payload ?? []);
        $request->setSession($session);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        return (string) $response->getContent();
    }

}
