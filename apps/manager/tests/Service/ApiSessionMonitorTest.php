<?php

namespace App\Tests\Service;

use App\Service\ApiSessionMonitor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApiSessionMonitorTest extends TestCase
{
    public function testFetchesAndNormalizesActiveSessions(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = [$method, $url];

            return new MockResponse(json_encode([
                'generatedAt' => '2026-06-22T16:00:00Z',
                'total' => 1,
                'sessions' => [[
                    'runId' => 'run_001',
                    'sessionId' => 'session_001',
                    'theme' => 'dslab',
                    'locale' => 'pt-BR',
                    'topic' => 'route53',
                    'difficulty' => 1,
                    'status' => 'finished',
                    'finished' => true,
                    'finishReason' => 'max_questions_reached',
                    'answered' => 2,
                    'total' => 5,
                    'currentQuestionId' => 'route53-easy-003',
                    'currentQuestionPosition' => 3,
                    'createdAt' => '2026-06-22T15:50:00Z',
                    'updatedAt' => '2026-06-22T15:59:00Z',
                    'idleSeconds' => 60,
                ]],
            ]));
        });

        $monitor = new ApiSessionMonitor($client, 'http://api.local/');

        $result = $monitor->activeSessions();

        self::assertSame([['GET', 'http://api.local/api/sessions/active']], $requests);
        self::assertSame('http://api.local', $result['apiBaseUrl']);
        self::assertSame(1, $result['total']);
        self::assertSame('Easy', $result['sessions'][0]['difficultyLabel']);
        self::assertSame('session_001', $result['sessions'][0]['sessionId']);
        self::assertTrue($result['sessions'][0]['finished']);
        self::assertSame('max_questions_reached', $result['sessions'][0]['finishReason']);
    }

    public function testThrowsWhenApiReturnsError(): void
    {
        $monitor = new ApiSessionMonitor(new MockHttpClient([
            new MockResponse('{"code":"internal_error"}', ['http_code' => 500]),
        ]), 'http://api.local');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');

        $monitor->activeSessions();
    }
}
