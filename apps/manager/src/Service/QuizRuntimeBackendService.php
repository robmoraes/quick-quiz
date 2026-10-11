<?php

namespace App\Service;

use App\Repository\ManagerDatabase;
use App\Repository\QuizCatalogLock;
use App\Storage\ContentStorage;
use App\Storage\ContentStorageFactory;
use PDO;
use RuntimeException;

/** Deliberate operator transition; no startup promotion or outage fallback. */
final class QuizRuntimeBackendService
{
    public function __construct(
        private readonly ManagerDatabase $database,
        private readonly QuizRuntimeCatalog $runtime,
        private readonly QuizContentRules $rules,
        private readonly QuizCatalogStream $stream,
        private readonly QuizLegacyExportService $verification,
    ) {}

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function run(string $provider, int $revision, array $evidence, bool $apply, bool $writesFrozen = false): array
    {
        if (!in_array($provider, ['postgres', 'content_storage'], true) || ($apply && !$writesFrozen)) {
            throw new RuntimeException('Choose a supported backend; apply requires explicit --writes-frozen coordination.');
        }
        $connection = $this->database->connection();
        $ownsTransaction = !$connection->inTransaction();
        // Acquire before the read snapshot: waiting for a writer must not produce a stale dry-run snapshot.
        QuizCatalogLock::session($connection);
        try {
            return $this->database->transactional(function (PDO $db) use ($provider, $revision, $evidence, $apply, $ownsTransaction): array {
                if (!$apply && $ownsTransaction) {
                    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }
                $state = $this->runtime->state($db, $apply);
                if ((int) $state['current_revision'] !== $revision || (int) $state['published_revision'] !== $revision) {
                    throw new RuntimeException('Expected revision must be current and already published; reconcile legacy pending changes explicitly first.');
                }
                if (($evidence['version'] ?? null) !== 1 || ($evidence['revision'] ?? null) !== $revision
                    || ($evidence['publishedRevision'] ?? null) !== $revision || ($evidence['complete'] ?? false) !== true
                    || ($evidence['verified'] ?? false) !== true || ($evidence['localeParity'] ?? false) !== true
                    || ($evidence['checksumAlgorithm'] ?? '') !== 'quiz-legacy-stream-v1-sha256'
                    || !is_string($evidence['target'] ?? null) || trim($evidence['target']) === '') {
                    throw new RuntimeException('Complete verified revisioned comparison/export evidence is required.');
                }
                if ($provider === 'postgres') {
                    if (($evidence['type'] ?? '') !== 'comparison' || ($evidence['legacyProviderRuns'] ?? null) !== 0
                        || ($evidence['legacyProviderRunsSource'] ?? '') !== 'operator-attested-api-metric') {
                        throw new RuntimeException('PostgreSQL activation requires verified comparison and explicit attestation of zero legacy-provider-dependent runs.');
                    }
                } elseif (($evidence['type'] ?? '') !== 'legacy-export' || ($evidence['applied'] ?? false) !== true
                    || !is_int($evidence['snapshotReaderVersion'] ?? null) || $evidence['snapshotReaderVersion'] < 2
                    || ($evidence['snapshotReaderVersionSource'] ?? '') !== 'operator-attested-compatible-api-image') {
                    throw new RuntimeException('Storage fallback requires a complete applied export and an explicitly attested version-2-compatible reader.');
                }
                $current = $this->stream->inspect($revision);
                foreach (['checksum', 'objectCount', 'fallbackLocale', 'supportedLocales'] as $field) {
                    if (($evidence[$field] ?? null) !== $current[$field]) {
                        throw new RuntimeException('Verification evidence is stale or has incompatible catalog/locales.');
                    }
                }
                // Re-read the actual comparison/export target under the same authoring lock, not just its old report.
                $target = $this->target((string) ($evidence['target'] ?? ''));
                $verified = $this->verification->compare($revision, $target, $provider === 'content_storage');
                if (!$verified['verified'] || $verified['checksum'] !== $current['checksum']) {
                    throw new RuntimeException('Verification target has changed or does not match the current authoring catalog.');
                }
                if ($apply) {
                    $statement = $db->prepare('UPDATE quiz_catalog_state SET publication_backend=:backend,contract_version=1,
                        fallback_locale=:fallback,supported_locales=ARRAY(SELECT jsonb_array_elements_text(CAST(:locales AS jsonb))),
                        updated_at=CURRENT_TIMESTAMP WHERE singleton=1');
                    $statement->execute(['backend' => $provider, 'fallback' => $this->rules->fallbackLocale(),
                        'locales' => json_encode($this->rules->supportedLocales(), JSON_THROW_ON_ERROR)]);
                }
                return ['from' => $state['publication_backend'], 'backend' => $provider, 'revision' => $revision,
                    'contractVersion' => 1, 'fallbackLocale' => $current['fallbackLocale'], 'supportedLocales' => $current['supportedLocales'],
                    'checksum' => $current['checksum'], 'applied' => $apply, 'apiReloadRequired' => $provider !== 'postgres'];
            });
        } finally {
            QuizCatalogLock::release($connection);
        }
    }

    private function target(string $location): ContentStorage
    {
        if (str_starts_with($location, '/') && $location !== '/') {
            return ContentStorageFactory::create('local', $location, '', '', '', '', false);
        }
        $parts = parse_url($location);
        if (is_array($parts) && ($parts['scheme'] ?? '') === 's3' && !empty($parts['host'])) {
            return ContentStorageFactory::create('s3', '', (string) (getenv('AWS_REGION') ?: 'us-east-1'),
                $parts['host'], ltrim((string) ($parts['path'] ?? ''), '/'), (string) getenv('S3_ENDPOINT_URL'),
                filter_var(getenv('S3_FORCE_PATH_STYLE'), FILTER_VALIDATE_BOOL));
        }
        throw new RuntimeException('Verification report must identify an explicit local or S3 target.');
    }
}
