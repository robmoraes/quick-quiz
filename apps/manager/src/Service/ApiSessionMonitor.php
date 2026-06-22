<?php

namespace App\Service;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ApiSessionMonitor
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiBaseUrl,
    ) {
    }

    /** @return array{apiBaseUrl:string,generatedAt:string,total:int,sessions:list<array{runId:string,sessionId:string,theme:string,locale:string,topic:string,difficulty:int,difficultyLabel:string,status:string,finished:bool,finishReason:string,answered:int,total:int,currentQuestionId:string,currentQuestionPosition:int,createdAt:string,updatedAt:string,idleSeconds:int}>} */
    public function activeSessions(): array
    {
        $url = $this->activeSessionsUrl();

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 3]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $error) {
            throw new RuntimeException(sprintf('Could not reach Quick Quiz API at %s.', $url), 0, $error);
        } catch (ExceptionInterface $error) {
            throw new RuntimeException(sprintf('Quick Quiz API returned an invalid response from %s.', $url), 0, $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Quick Quiz API returned HTTP %d from %s.', $status, $url));
        }

        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Quick Quiz API returned an invalid response from %s.', $url));
        }

        return [
            'apiBaseUrl' => $this->normalizedBaseUrl(),
            'generatedAt' => trim((string) ($data['generatedAt'] ?? '')),
            'total' => max(0, (int) ($data['total'] ?? 0)),
            'sessions' => $this->normalizeSessions($data['sessions'] ?? []),
        ];
    }

    public function activeSessionsUrl(): string
    {
        return $this->normalizedBaseUrl().'/api/sessions/active';
    }

    public function apiBaseUrl(): string
    {
        return $this->normalizedBaseUrl();
    }

    private function normalizedBaseUrl(): string
    {
        $baseUrl = rtrim(trim($this->apiBaseUrl), '/');
        return $baseUrl === '' ? 'http://127.0.0.1:8080' : $baseUrl;
    }

    /** @param mixed $sessions @return list<array{runId:string,sessionId:string,theme:string,locale:string,topic:string,difficulty:int,difficultyLabel:string,status:string,finished:bool,finishReason:string,answered:int,total:int,currentQuestionId:string,currentQuestionPosition:int,createdAt:string,updatedAt:string,idleSeconds:int}> */
    private function normalizeSessions(mixed $sessions): array
    {
        if (!is_array($sessions)) {
            return [];
        }

        $normalized = [];
        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }
            $difficulty = (int) ($session['difficulty'] ?? 0);
            $normalized[] = [
                'runId' => trim((string) ($session['runId'] ?? '')),
                'sessionId' => trim((string) ($session['sessionId'] ?? '')),
                'theme' => trim((string) ($session['theme'] ?? '')),
                'locale' => trim((string) ($session['locale'] ?? '')),
                'topic' => trim((string) ($session['topic'] ?? '')),
                'difficulty' => $difficulty,
                'difficultyLabel' => $this->difficultyLabel($difficulty),
                'status' => trim((string) ($session['status'] ?? '')),
                'finished' => filter_var($session['finished'] ?? false, FILTER_VALIDATE_BOOL),
                'finishReason' => trim((string) ($session['finishReason'] ?? '')),
                'answered' => max(0, (int) ($session['answered'] ?? 0)),
                'total' => max(0, (int) ($session['total'] ?? 0)),
                'currentQuestionId' => trim((string) ($session['currentQuestionId'] ?? '')),
                'currentQuestionPosition' => max(0, (int) ($session['currentQuestionPosition'] ?? 0)),
                'createdAt' => trim((string) ($session['createdAt'] ?? '')),
                'updatedAt' => trim((string) ($session['updatedAt'] ?? '')),
                'idleSeconds' => max(0, (int) ($session['idleSeconds'] ?? 0)),
            ];
        }

        return $normalized;
    }

    private function difficultyLabel(int $difficulty): string
    {
        return match ($difficulty) {
            1 => 'Easy',
            2 => 'Normal',
            3 => 'Hard',
            4 => 'Hardcore',
            default => 'Unknown',
        };
    }
}
