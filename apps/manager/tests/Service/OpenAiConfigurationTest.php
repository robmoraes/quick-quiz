<?php

namespace App\Tests\Service;

use App\Service\OpenAiConfiguration;
use App\Service\OpenAiModelProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class OpenAiConfigurationTest extends TestCase
{
    public function testRequiresApiKeyAndModel(): void
    {
        self::assertFalse((new OpenAiConfiguration('', ''))->isConfigured());
        self::assertFalse((new OpenAiConfiguration('test-key', ''))->isConfigured());
        self::assertFalse((new OpenAiConfiguration('', 'gpt-test'))->isConfigured());
        self::assertTrue((new OpenAiConfiguration('test-key', 'gpt-test'))->isConfigured());
    }

    public function testExposesTrimmedModel(): void
    {
        self::assertSame('gpt-test', (new OpenAiConfiguration('test-key', ' gpt-test '))->model());
    }

    public function testCanUseRuntimeModelProvider(): void
    {
        $provider = new OpenAiModelProvider($this->requestStack(), new MockHttpClient([]), 'test-key', 'gpt-default');
        $config = new OpenAiConfiguration('', '', $provider);

        self::assertTrue($config->isConfigured());
        self::assertSame('gpt-default', $config->model());
        self::assertSame(['gpt-default'], $config->availableModels());
    }

    private function requestStack(): RequestStack
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }
}
