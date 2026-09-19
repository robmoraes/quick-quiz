<?php

namespace App\Controller;

use App\Exception\AdminApiException;
use App\Exception\QuizPublicationException;
use App\Exception\QuizDatabaseException;
use PDOException;
use App\Service\QuizAdministrationService;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/api/admin/quiz', name: 'quiz_admin_api_')]
final class QuizAdminController extends AbstractController
{
    public function __construct(
        private readonly QuizAdministrationService $administration,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/catalog', name: 'catalog', methods: ['GET'])]
    public function catalog(Request $request): JsonResponse
    {
        return $this->query(fn (): array => $this->administration->catalog(
            (string) $request->query->get('locale', ''),
        ));
    }

    #[Route('/publication', name: 'publication', methods: ['GET'])]
    public function publication(): JsonResponse
    {
        return $this->query(fn (): array => ['publication' => $this->administration->publicationStatus()]);
    }

    #[Route('/publication', name: 'publication_retry', methods: ['POST'])]
    public function retryPublication(Request $request): JsonResponse
    {
        return $this->mutation($request, 'retry', 'publication',
            fn (): array => ['publication' => $this->administration->retryPublication()]);
    }

    #[Route('/themes', name: 'themes', methods: ['GET'])]
    public function themes(): JsonResponse
    {
        return $this->query(fn (): array => ['themes' => $this->administration->themes()]);
    }

    #[Route('/themes', name: 'theme_create', methods: ['POST'])]
    public function createTheme(Request $request): JsonResponse
    {
        return $this->mutation($request, 'create', 'theme', fn (): array => $this->administration->createTheme(
            $this->jsonBody($request),
        ), 201);
    }

    #[Route('/themes/{theme}', name: 'theme', methods: ['GET'])]
    public function theme(string $theme): JsonResponse
    {
        return $this->query(fn (): array => ['theme' => $this->administration->theme($theme)]);
    }

    #[Route('/themes/{theme}', name: 'theme_replace', methods: ['PUT'])]
    public function replaceTheme(Request $request, string $theme): JsonResponse
    {
        return $this->mutation($request, 'replace', 'theme/'.$theme, fn (): array => $this->administration->replaceTheme(
            $theme,
            $this->jsonBody($request),
        ));
    }

    #[Route('/themes/{theme}', name: 'theme_delete', methods: ['DELETE'])]
    public function deleteTheme(Request $request, string $theme): JsonResponse
    {
        return $this->mutation($request, 'delete', 'theme/'.$theme, fn (): array => $this->administration->deleteTheme(
            $theme,
            $this->recursive($request),
        ));
    }

    #[Route('/themes/{theme}/topics', name: 'topics', methods: ['GET'])]
    public function topics(Request $request, string $theme): JsonResponse
    {
        return $this->query(fn (): array => ['topics' => $this->administration->topics(
            $theme,
            (string) $request->query->get('locale', ''),
        )]);
    }

    #[Route('/themes/{theme}/topics', name: 'topic_create', methods: ['POST'])]
    public function createTopic(Request $request, string $theme): JsonResponse
    {
        return $this->mutation($request, 'create', 'theme/'.$theme.'/topic', fn (): array => $this->administration->createTopic(
            $theme,
            $this->jsonBody($request),
        ), 201);
    }

    #[Route('/themes/{theme}/topics/{topic}', name: 'topic', methods: ['GET'])]
    public function topic(Request $request, string $theme, string $topic): JsonResponse
    {
        return $this->query(fn (): array => ['topic' => $this->administration->topic(
            $theme,
            $topic,
            (string) $request->query->get('locale', ''),
        )]);
    }

    #[Route('/themes/{theme}/topics/{topic}', name: 'topic_replace', methods: ['PUT'])]
    public function replaceTopic(Request $request, string $theme, string $topic): JsonResponse
    {
        return $this->mutation($request, 'replace', 'theme/'.$theme.'/topic/'.$topic, fn (): array => $this->administration->replaceTopic(
            $theme,
            $topic,
            $this->jsonBody($request),
        ));
    }

    #[Route('/themes/{theme}/topics/{topic}', name: 'topic_delete', methods: ['DELETE'])]
    public function deleteTopic(Request $request, string $theme, string $topic): JsonResponse
    {
        return $this->mutation($request, 'delete', 'theme/'.$theme.'/topic/'.$topic, fn (): array => $this->administration->deleteTopic(
            $theme,
            $topic,
            $this->recursive($request),
        ));
    }

    #[Route('/themes/{theme}/topics/{topic}/questions', name: 'questions', methods: ['GET'])]
    public function questions(Request $request, string $theme, string $topic): JsonResponse
    {
        $rawDifficulty = $request->query->get('difficulty');
        $difficulty = $rawDifficulty === null || $rawDifficulty === '' ? null : filter_var($rawDifficulty, FILTER_VALIDATE_INT);
        if ($difficulty === false) {
            return $this->problem(AdminApiException::badRequest('invalid_difficulty', 'difficulty must be an integer from 1 to 4.'));
        }

        return $this->query(fn (): array => $this->administration->questions(
            $theme,
            $topic,
            $difficulty,
            (string) $request->query->get('locale', ''),
        ));
    }

    #[Route('/themes/{theme}/topics/{topic}/questions', name: 'question_create', methods: ['POST'])]
    public function createQuestions(Request $request, string $theme, string $topic): JsonResponse
    {
        return $this->mutation($request, 'create', 'theme/'.$theme.'/topic/'.$topic.'/questions', fn (): array => $this->administration->createQuestions(
            $theme,
            $topic,
            $this->jsonBody($request),
        ), 201);
    }

    #[Route('/themes/{theme}/topics/{topic}/questions/{difficulty}/{questionId}', name: 'question', methods: ['GET'], requirements: ['difficulty' => '[1-4]'])]
    public function question(string $theme, string $topic, int $difficulty, string $questionId): JsonResponse
    {
        return $this->query(fn (): array => $this->administration->question(
            $theme,
            $topic,
            $difficulty,
            $questionId,
        ));
    }

    #[Route('/themes/{theme}/topics/{topic}/questions/{difficulty}/{questionId}', name: 'question_replace', methods: ['PUT'], requirements: ['difficulty' => '[1-4]'])]
    public function replaceQuestion(Request $request, string $theme, string $topic, int $difficulty, string $questionId): JsonResponse
    {
        return $this->mutation($request, 'replace', 'theme/'.$theme.'/topic/'.$topic.'/question/'.$questionId, fn (): array => $this->administration->replaceQuestion(
            $theme,
            $topic,
            $difficulty,
            $questionId,
            $this->jsonBody($request),
        ));
    }

    #[Route('/themes/{theme}/topics/{topic}/questions/{difficulty}/{questionId}', name: 'question_delete', methods: ['DELETE'], requirements: ['difficulty' => '[1-4]'])]
    public function deleteQuestion(Request $request, string $theme, string $topic, int $difficulty, string $questionId): JsonResponse
    {
        return $this->mutation($request, 'delete', 'theme/'.$theme.'/topic/'.$topic.'/question/'.$questionId, fn (): array => $this->administration->deleteQuestion(
            $theme,
            $topic,
            $difficulty,
            $questionId,
        ));
    }

    /** @param callable():array<string,mixed> $operation */
    private function query(callable $operation): JsonResponse
    {
        try {
            return new JsonResponse($operation());
        } catch (AdminApiException $error) {
            return $this->problem($error);
        } catch (RuntimeException $error) {
            return $this->runtimeProblem($error);
        } catch (Throwable) {
            return $this->problem(new AdminApiException('internal_error', 'The request could not be completed.', 500));
        }
    }

    /** @param callable():array<string,mixed> $operation */
    private function mutation(Request $request, string $operationName, string $resource, callable $operation, int $status = 200): JsonResponse
    {
        $requestId = $this->safeLogValue((string) $request->headers->get('X-Request-ID', ''), 128);
        if ($requestId === '') {
            $requestId = bin2hex(random_bytes(8));
        }
        $resource = $this->safeLogValue($resource, 256);

        try {
            $response = new JsonResponse($operation(), $status);
            $this->logger->info('quiz_admin_mutation', [
                'operation' => $operationName,
                'resource' => $resource,
                'outcome' => 'success',
                'request_id' => $requestId,
            ]);
        } catch (AdminApiException $error) {
            $response = $this->problem($error);
            $this->logger->notice('quiz_admin_mutation', [
                'operation' => $operationName,
                'resource' => $resource,
                'outcome' => 'rejected',
                'error_code' => $error->errorCode,
                'request_id' => $requestId,
            ]);
        } catch (RuntimeException $error) {
            $response = $this->runtimeProblem($error);
            $this->logger->error('quiz_admin_mutation', [
                'operation' => $operationName,
                'resource' => $resource,
                'outcome' => 'error',
                'request_id' => $requestId,
            ]);
        } catch (Throwable) {
            $response = $this->problem(new AdminApiException('internal_error', 'The request could not be completed.', 500));
            $this->logger->error('quiz_admin_mutation', [
                'operation' => $operationName,
                'resource' => $resource,
                'outcome' => 'error',
                'request_id' => $requestId,
            ]);
        }

        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    /** @return array<string,mixed> */
    private function jsonBody(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw AdminApiException::badRequest('invalid_json', 'The request body must be a valid JSON object.');
        }
        if (!is_array($body) || array_is_list($body)) {
            throw AdminApiException::badRequest('invalid_json', 'The request body must be a valid JSON object.');
        }

        return $body;
    }

    private function recursive(Request $request): bool
    {
        $raw = $request->query->get('recursive', 'false');
        $value = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw AdminApiException::badRequest('invalid_recursive', 'recursive must be true or false.');
        }

        return $value;
    }

    private function safeLogValue(string $value, int $maxLength): string
    {
        $value = preg_replace('/[^a-zA-Z0-9._:\/-]/', '_', trim($value)) ?? '';

        return substr($value, 0, $maxLength);
    }

    private function runtimeProblem(RuntimeException $error): JsonResponse
    {
        if ($error instanceof QuizPublicationException) {
            return new JsonResponse([
                'error' => [
                    'code' => 'publication_failed',
                    'message' => $error->getMessage(),
                ],
                'publication' => [
                    'revision' => $error->revision,
                    'status' => 'failed',
                    'apiReloadRequired' => false,
                ],
            ], 503);
        }
        if ($error instanceof QuizDatabaseException || $error instanceof PDOException) {
            return $this->problem(new AdminApiException('database_error', 'Quiz database is unavailable.', 503));
        }
        if ($error->getMessage() === 'Could not retry publication while legacy quiz authoring is selected.') {
            return $this->problem(new AdminApiException('publication_unavailable',
                'Publication retry requires PostgreSQL quiz authoring.', 503));
        }
        if (str_starts_with($error->getMessage(), 'Could not ')) {
            return $this->problem(new AdminApiException('storage_error', 'Content storage is unavailable.', 503));
        }

        return $this->problem(AdminApiException::validation($error->getMessage()));
    }

    private function problem(AdminApiException $error): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $error->errorCode,
                'message' => $error->getMessage(),
            ],
        ], $error->status);
    }
}
