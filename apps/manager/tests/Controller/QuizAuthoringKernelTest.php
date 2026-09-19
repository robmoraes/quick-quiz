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
        } finally {
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
}
