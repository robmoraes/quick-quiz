<?php

namespace App\Tests\Service;

use App\Service\OpenAiModelProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class OpenAiModelProviderTest extends TestCase
{
    public function testUsesDefaultModelUntilSelectionChanges(): void
    {
        $provider = new OpenAiModelProvider(
            $this->requestStack(),
            new MockHttpClient([new MockResponse(json_encode([
                'data' => [
                    ['id' => 'gpt-a'],
                    ['id' => 'gpt-5.4'],
                    ['id' => 'text-embedding-3-large'],
                ],
            ]))]),
            'test-key',
            'gpt-a',
        );

        self::assertSame('gpt-a', $provider->activeModel());

        $provider->selectModel('gpt-5.4');

        self::assertSame('gpt-5.4', $provider->activeModel());
    }

    public function testCachesAvailableModelsInSession(): void
    {
        $requests = 0;
        $provider = new OpenAiModelProvider(
            $this->requestStack(),
            new MockHttpClient(function () use (&$requests): MockResponse {
                $requests++;

                return new MockResponse(json_encode([
                    'data' => [
                        ['id' => 'gpt-5.4-mini'],
                        ['id' => 'gpt-5.4-realtime'],
                        ['id' => 'gpt-4.1'],
                        ['id' => 'gpt-image-1'],
                        ['id' => 'text-embedding-3-large'],
                        ['id' => 'o3'],
                    ],
                ]));
            }),
            'test-key',
            'gpt-default',
        );

        self::assertSame(['gpt-4.1', 'gpt-5.4-mini', 'gpt-default'], $provider->availableModels());
        self::assertSame(['gpt-4.1', 'gpt-5.4-mini', 'gpt-default'], $provider->availableModels());
        self::assertSame(1, $requests);
    }

    public function testFallsBackToDefaultModelWithoutApiKey(): void
    {
        $provider = new OpenAiModelProvider($this->requestStack(), new MockHttpClient([]), '', 'gpt-default');

        self::assertFalse($provider->isConfigured());
        self::assertSame(['gpt-default'], $provider->availableModels());
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
