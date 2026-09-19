<?php

namespace App\Service;

use App\Exception\QuizPublicationException;
use App\Repository\ManagerDatabase;
use App\Repository\QuizCatalogLock;
use PDO;
use RuntimeException;
use Throwable;

final class QuizPublicationService
{
    public function __construct(
        private readonly ManagerDatabase $database,
        private readonly QuizProjectionRenderer $renderer,
        private readonly QuizProjectionPublisher $publisher,
    ) {
    }

    /**
     * @param callable():mixed $mutation
     * @param callable(mixed):array{keys:list<string>,deletePrefixes?:list<string>} $scope
     * @return array{result:mixed,publication:array<string,mixed>}
     */
    public function mutate(callable $mutation, callable $scope): array
    {
        return $this->locked(function () use ($mutation, $scope): array {
            $previous = $this->status();
            $result = $mutation();
            $revision = is_array($result) ? (int) ($result['revision'] ?? 0) : (int) $result;
            if ($revision <= $previous['currentRevision']) {
                throw new RuntimeException('Quiz mutation did not create a new revision.');
            }
            $selected = $previous['currentRevision'] === $previous['publishedRevision'] ? $scope($result) : null;
            return ['result' => $result, 'publication' => $this->publishRevision($revision, $selected)];
        });
    }

    /** @return array<string,mixed> */
    public function publishPending(): array
    {
        return $this->locked(function (): array {
            $status = $this->status();
            if ($status['currentRevision'] === $status['publishedRevision'] && $status['status'] === 'published') {
                return $status;
            }
            return $this->publishRevision($status['currentRevision'], null);
        });
    }

    /** @return array<string,mixed> */
    public function publishCurrent(?string $theme = null): array
    {
        return $this->locked(function () use ($theme): array {
            $status = $this->status();
            if ($status['currentRevision'] === 0) {
                return $status;
            }
            if ($theme !== null) {
                $theme = trim($theme);
                if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $theme)) {
                    throw new RuntimeException('Invalid theme ID for publication.');
                }
                if ($status['currentRevision'] > $status['publishedRevision']) {
                    $statement = $this->database->connection()->prepare(
                        'SELECT affected_theme_id FROM quiz_publications WHERE revision=:revision ORDER BY id DESC LIMIT 1');
                    $statement->execute(['revision' => $status['currentRevision']]);
                    if ($status['currentRevision'] !== $status['publishedRevision'] + 1
                        || $statement->fetchColumn() !== $theme) {
                        throw new RuntimeException('Theme-scoped publication cannot cover all pending changes; run full publication.');
                    }
                }
            }
            return $this->publishRevision($status['currentRevision'], null, $theme);
        });
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $db = $this->database->connection();
        $state = $db->query('SELECT current_revision,published_revision FROM quiz_catalog_state WHERE singleton=1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($state)) {
            throw new RuntimeException('Quiz catalog state is missing; run database migrations.');
        }
        $statement = $db->prepare('SELECT revision,status,content_checksum,object_count,failure_summary,
                started_at,finished_at,
                CASE WHEN started_at IS NOT NULL AND finished_at IS NOT NULL
                    THEN ROUND(EXTRACT(EPOCH FROM (finished_at-started_at))*1000)::INTEGER
                    ELSE NULL END AS duration_ms
                FROM quiz_publications WHERE revision=:revision ORDER BY id DESC LIMIT 1');
        $statement->execute(['revision' => $state['current_revision']]);
        $publication = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $current = (int) $state['current_revision'];
        $published = (int) $state['published_revision'];
        return [
            'currentRevision' => $current,
            'publishedRevision' => $published,
            'status' => $publication['status'] ?? ($current === $published ? 'published' : 'pending'),
            'checksum' => $publication['content_checksum'] ?? null,
            'objectCount' => ($publication['object_count'] ?? null) === null ? null : (int) $publication['object_count'],
            'failureSummary' => $publication['failure_summary'] ?? null,
            'startedAt' => $publication['started_at'] ?? null,
            'finishedAt' => $publication['finished_at'] ?? null,
            'durationMs' => ($publication['duration_ms'] ?? null) === null ? null : (int) $publication['duration_ms'],
        ];
    }

    /** @param array{keys:list<string>,deletePrefixes?:list<string>}|null $scope @return array<string,mixed> */
    private function publishRevision(int $revision, ?array $scope, ?string $theme = null): array
    {
        $db = $this->database->connection();
        $statement = $db->prepare('UPDATE quiz_publications SET status=\'pending\',started_at=CURRENT_TIMESTAMP,
            finished_at=NULL,failure_summary=NULL WHERE revision=:revision');
        $statement->execute(['revision' => $revision]);
        if ($statement->rowCount() === 0) {
            throw new RuntimeException(sprintf('Quiz publication revision %d is missing.', $revision));
        }
        $phase = 'projection';
        try {
            $objects = $this->renderer->render();
            if ($theme !== null) {
                $scope = [
                    'keys' => array_values(array_filter(array_keys($objects),
                        static fn (string $key): bool => $key === 'themes.json' || str_starts_with($key, $theme.'/'))),
                    'deletePrefixes' => [$theme.'/'],
                ];
            }
            $phase = 'storage';
            $result = $this->publisher->publish($objects, $scope['keys'] ?? null, $scope['deletePrefixes'] ?? []);
            $phase = 'database';
            $this->database->transactional(function (PDO $connection) use ($revision, $result): void {
                $statement = $connection->prepare('UPDATE quiz_catalog_state SET published_revision=:revision,
                    updated_at=CURRENT_TIMESTAMP WHERE singleton=1 AND current_revision=:revision');
                $statement->execute(['revision' => $revision]);
                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException('Quiz revision changed during publication.');
                }
                $statement = $connection->prepare('UPDATE quiz_publications SET status=\'published\',
                    content_checksum=:checksum,object_count=:object_count,finished_at=CURRENT_TIMESTAMP,
                    failure_summary=NULL WHERE revision=:revision');
                $statement->execute([
                    'revision' => $revision,
                    'checksum' => $result['checksum'],
                    'object_count' => $result['objectCount'],
                ]);
            });
            return [
                'revision' => $revision,
                'status' => 'published',
                'checksum' => $result['checksum'],
                'objectCount' => $result['objectCount'],
                'durationMs' => $this->status()['durationMs'],
                'apiReloadRequired' => true,
                'reason' => 'The Quiz API loads quiz content during startup.',
            ];
        } catch (Throwable $error) {
            $compensationFailed = false;
            if ($phase === 'database' && isset($result['previousObjects'])) {
                try {
                    $this->publisher->restore($result['previousObjects']);
                } catch (Throwable) {
                    $compensationFailed = true;
                }
            }
            $summary = match ($phase) {
                'projection' => 'Quiz projection failed.',
                'storage' => str_contains($error->getMessage(), 'compensation was incomplete')
                    ? 'Quiz storage publication failed; compensation was incomplete.'
                    : 'Quiz storage publication failed.',
                default => 'Quiz publication state update failed.',
            };
            if ($compensationFailed) {
                $summary = 'Quiz publication failed; compensation was incomplete.';
            }
            $statement = $db->prepare('UPDATE quiz_publications SET status=\'failed\',
                failure_summary=:summary,finished_at=CURRENT_TIMESTAMP WHERE revision=:revision');
            $statement->execute(['revision' => $revision, 'summary' => $summary]);
            throw new QuizPublicationException($revision);
        }
    }

    /** @param callable():mixed $operation */
    private function locked(callable $operation): mixed
    {
        $db = $this->database->connection();
        QuizCatalogLock::session($db);
        try {
            return $operation();
        } finally {
            QuizCatalogLock::release($db);
        }
    }
}
