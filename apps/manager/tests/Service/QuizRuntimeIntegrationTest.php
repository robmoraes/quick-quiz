<?php

namespace App\Tests\Service;

use App\Exception\QuizDatabaseException;
use App\Repository\AiPromptRepository;
use App\Repository\DatabaseConnectionFactory;
use App\Repository\ManagerDatabase;
use App\Repository\MigrationRunner;
use App\Repository\QuizContentWriter;
use App\Service\AiPromptDefaults;
use App\Service\AiPromptExportService;
use App\Service\QuizCatalogStream;
use App\Service\QuizContentComparator;
use App\Service\QuizContentRules;
use App\Service\QuizLegacyExportService;
use App\Service\QuizProjectionPublisher;
use App\Service\QuizProjectionRenderer;
use App\Service\QuizPublicationService;
use App\Service\QuizRuntimeBackendService;
use App\Service\QuizRuntimeCatalog;
use App\Service\QuizRuntimeReaderProvisioner;
use App\Storage\ContentStorageFactory;
use App\Storage\LocalContentStorage;
use App\Tests\Support\FaultInjectingContentStorage;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QuizRuntimeIntegrationTest extends TestCase
{
    private ManagerDatabase $database;
    private QuizContentRules $rules;
    private QuizRuntimeCatalog $runtime;
    private QuizContentWriter $writer;
    private QuizPublicationService $publication;
    private QuizCatalogStream $stream;
    private QuizLegacyExportService $exporter;
    private QuizRuntimeBackendService $backend;
    private string $theme;
    private string $url;
    private array $originalState;
    private array $directories = [];
    private ?string $reader = null;

    protected function setUp(): void
    {
        $this->url = (string) getenv('QUICKQUIZ_MANAGER_POSTGRES_TEST_URL');
        if ($this->url === '') {
            self::markTestSkipped('Set QUICKQUIZ_MANAGER_POSTGRES_TEST_URL to the dedicated disposable runtime test database.');
        }
        $parts = parse_url($this->url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['postgresql', 'postgres'], true)
            || rawurldecode(ltrim((string) ($parts['path'] ?? ''), '/')) !== 'quickquiz_manager_runtime_test') {
            self::fail('Runtime integration requires the disposable database quickquiz_manager_runtime_test.');
        }
        $this->database = new ManagerDatabase($this->url);
        $db = $this->database->connection();
        if ($db->query('SELECT current_database()')->fetchColumn() !== 'quickquiz_manager_runtime_test') {
            self::fail('Connected database does not match the disposable runtime test database.');
        }
        (new MigrationRunner($this->database, dirname(__DIR__, 2).'/migrations'))->migrate();
        $this->originalState = $db->query('SELECT *,array_to_json(supported_locales) AS locales_json FROM quiz_catalog_state WHERE singleton=1')->fetch(PDO::FETCH_ASSOC);
        $this->theme = 'runtime-test-'.bin2hex(random_bytes(5));
        $db->exec("UPDATE quiz_catalog_state SET publication_backend='postgres',published_revision=current_revision,
            fallback_locale='en-US',supported_locales=ARRAY['en-US','pt-BR'] WHERE singleton=1");
        $this->rules = new QuizContentRules('en-US', 'en-US,pt-BR');
        $this->runtime = new QuizRuntimeCatalog($this->rules);
        $this->writer = new QuizContentWriter($this->database, $this->rules, 'postgres');
        $storage = new FaultInjectingContentStorage();
        $storage->failOnMutation(1);
        $this->publication = new QuizPublicationService($this->database, new QuizProjectionRenderer($this->database, $this->rules),
            new QuizProjectionPublisher($storage, new QuizContentComparator()), 'postgres');
        $this->stream = new QuizCatalogStream($this->database, $this->rules, $this->runtime);
        $this->exporter = new QuizLegacyExportService($this->stream);
        $this->backend = new QuizRuntimeBackendService($this->database, $this->runtime, $this->rules, $this->stream, $this->exporter);
    }

    protected function tearDown(): void
    {
        if (!isset($this->database, $this->originalState, $this->theme)) { return; }
        $db = $this->database->connection();
        $db->exec('DROP TRIGGER IF EXISTS runtime_test_publication_failure ON quiz_publications');
        $db->exec('DROP FUNCTION IF EXISTS runtime_test_fail_publication()');
        $db->prepare('DELETE FROM quiz_themes WHERE id=:theme')->execute(['theme' => $this->theme]);
        $db->prepare('DELETE FROM ai_prompts WHERE theme=:theme')->execute(['theme' => $this->theme]);
        $db->prepare('DELETE FROM quiz_publications WHERE affected_theme_id=:theme')->execute(['theme' => $this->theme]);
        $old = $this->originalState;
        $statement = $db->prepare('UPDATE quiz_catalog_state SET current_revision=:current,published_revision=:published,
            publication_backend=:backend,contract_version=:version,fallback_locale=:fallback,
            supported_locales=CASE WHEN CAST(:locales AS jsonb) IS NULL THEN NULL ELSE ARRAY(SELECT jsonb_array_elements_text(CAST(:locales AS jsonb))) END
            WHERE singleton=1');
        $statement->execute(['current' => $old['current_revision'], 'published' => $old['published_revision'],
            'backend' => $old['publication_backend'], 'version' => $old['contract_version'],
            'fallback' => $old['fallback_locale'], 'locales' => $old['locales_json']]);
        if ($this->reader !== null) {
            $db->exec('DROP OWNED BY "'.$this->reader.'"');
            $db->exec('DROP ROLE "'.$this->reader.'"');
        }
        foreach ($this->directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
            rmdir($directory);
        }
    }

    public function testCrudPublishesAtomicallyWithoutStorageAndPublicationCallsAreIdempotent(): void
    {
        $before = $this->publication->status()['currentRevision'];
        $result = $this->publication->mutate(fn () => $this->writer->saveTheme(['id' => $this->theme, 'name' => 'Literature', 'active' => true]), fn () => throw new RuntimeException('Legacy projection scope must never be called.'));
        self::assertSame($before + 1, $result['publication']['revision']);
        self::assertSame('postgres', $result['publication']['backend']);
        self::assertFalse($result['publication']['apiReloadRequired']);
        self::assertNull($result['publication']['checksum']);
        self::assertNull($result['publication']['objectCount']);
        self::assertSame($before + 1, $this->publication->status()['publishedRevision']);
        self::assertSame($this->publication->publishPending(), $this->publication->publishCurrent());
        self::assertSame($before + 1, $this->publication->status()['currentRevision']);
        $this->writer->saveTopicSet($this->theme, ['key' => 'poetry', 'name' => 'Poetry', 'active' => true]);
        $this->writer->createLocalizedQuestionSets($this->theme, 'poetry', 1, [$this->question('one')]);
        self::assertSame(1, $this->viewCount('question_keys'));
        $this->writer->replaceLocalizedQuestionSet($this->theme, 'poetry', 1, 'one', $this->question('one', 'Revised')['translations']);
        self::assertSame('Revised en-US', $this->database->connection()->query("SELECT prompt FROM quiz_runtime_v1.question_translations WHERE theme_id='".$this->theme."' AND locale='en-US'")->fetchColumn());
        $this->writer->deleteLocalizedQuestionSet($this->theme, 'poetry', 1, 'one');
        self::assertSame(0, $this->viewCount('question_keys'));
    }

    public function testPublicationInsertFailureRollsBackContentAndBothRevisions(): void
    {
        $before = $this->publication->status();
        $db = $this->database->connection();
        $db->exec("CREATE FUNCTION runtime_test_fail_publication() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'synthetic test failure'; END \$\$");
        $db->exec('CREATE TRIGGER runtime_test_publication_failure BEFORE INSERT ON quiz_publications FOR EACH ROW EXECUTE FUNCTION runtime_test_fail_publication()');
        try {
            $this->writer->saveTheme(['id' => $this->theme, 'name' => 'Study']);
            self::fail('Expected publication failure.');
        } catch (QuizDatabaseException) {}
        self::assertSame($before, $this->publication->status());
        $query = $db->prepare('SELECT COUNT(*) FROM quiz_themes WHERE id=:theme'); $query->execute(['theme' => $this->theme]);
        self::assertSame(0, (int) $query->fetchColumn());
    }

    public function testInactiveParentsStayHiddenAndActivationRejectsIncompletePackages(): void
    {
        $this->seed(false, false);
        self::assertSame(0, $this->viewCount('topics'));
        $this->writer->saveTheme(['id' => $this->theme, 'name' => 'Study', 'active' => true]);
        self::assertSame(0, $this->viewCount('question_keys'));
        $this->database->connection()->prepare("DELETE FROM quiz_question_translations WHERE theme_id=:theme AND locale='pt-BR'")->execute(['theme' => $this->theme]);
        $before = $this->publication->status()['currentRevision'];
        try {
            $this->writer->saveTopicSet($this->theme, ['key' => 'topic', 'name' => 'Topic', 'active' => true]);
            self::fail('Incomplete activation must fail.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('incomplete locale', $e->getMessage());
        }
        self::assertSame($before, $this->publication->status()['currentRevision']);
        self::assertSame(0, $this->viewCount('topics'));
    }

    public function testInvalidLocalizedReplacementAndTagOnlyChangesDoNotAdvancePublication(): void
    {
        $this->seed();
        $before = $this->publication->status()['currentRevision'];
        try {
            $this->writer->replaceLocalizedQuestionSet($this->theme, 'topic', 1, 'one', ['en-US' => $this->question('one')['translations']['en-US']]);
            self::fail('Missing locale must fail.');
        } catch (RuntimeException) {}
        self::assertSame($before, $this->publication->status()['currentRevision']);
        $saved = $this->writer->saveTopicSet($this->theme, ['key' => 'topic', 'name' => 'Topic', 'active' => true, 'tags' => ['memory']]);
        self::assertFalse($saved['publicationRequired']);
        self::assertSame($before, $this->publication->status()['publishedRevision']);
    }

    public function testBackendAndLocaleMismatchBlockWriters(): void
    {
        $this->expectException(RuntimeException::class);
        (new QuizContentWriter($this->database, new QuizContentRules('en-US', 'en-US'), 'postgres'))->saveTheme(['id' => $this->theme, 'name' => 'Study']);
    }

    public function testLegacyWriterCannotMutatePostgresRuntime(): void
    {
        $this->expectException(RuntimeException::class);
        (new QuizContentWriter($this->database, $this->rules))->saveTheme(['id' => $this->theme, 'name' => 'Study']);
    }

    public function testPromptsRemainReadableAfterThemeDeletionWithoutStorageWrites(): void
    {
        $this->seed();
        $prompts = new AiPromptRepository($this->url, 'postgres');
        $prompts->save($this->theme, AiPromptDefaults::QUESTION_SOLUTION, 'Explain with a literature example.');
        $storage = ContentStorageFactory::deferred('s3', '', '', '', '', '', false);
        (new AiPromptExportService('', $storage, 'postgres'))->export($this->theme, AiPromptDefaults::QUESTION_SOLUTION, 'Explain with a literature example.');
        $this->writer->deleteTheme($this->theme, true);
        $query = $this->database->connection()->prepare('SELECT prompt_text FROM quiz_runtime_v1.solution_prompts WHERE theme=:theme');
        $query->execute(['theme' => $this->theme]);
        self::assertSame('Explain with a literature example.', $query->fetchColumn());
        self::assertSame('Explain the correct answer for a quiz question. Be concise, accurate, and answer in the requested locale.', AiPromptDefaults::renderDefault(AiPromptDefaults::QUESTION_SOLUTION));
        $prompts->restoreDefault($this->theme, AiPromptDefaults::QUESTION_SOLUTION);
        self::assertSame(AiPromptDefaults::renderDefault(AiPromptDefaults::QUESTION_SOLUTION), $prompts->text($this->theme, AiPromptDefaults::QUESTION_SOLUTION));
    }

    public function testStreamedExportIncludesInactiveTreeTagsPromptsAndExactPackagesAcrossBatches(): void
    {
        $this->seed(false, false);
        $this->writer->saveTheme(['id' => $this->theme, 'name' => 'Study', 'active' => false, 'tags' => ['dev']]);
        $this->writer->saveTopicSet($this->theme, ['key' => 'topic', 'name' => 'Topic', 'active' => false, 'tags' => ['private-topic-tag']]);
        $items = []; for ($i = 2; $i <= 50; ++$i) { $items[] = $this->question('q'.$i); }
        $this->writer->createLocalizedQuestionSets($this->theme, 'topic', 1, $items);
        $this->writer->createLocalizedQuestionSets($this->theme, 'topic', 1, [$this->question('q51'), $this->question('q52')]);
        (new AiPromptRepository($this->url, 'postgres'))->save($this->theme, AiPromptDefaults::QUESTION_SOLUTION, 'Explain precisely.');
        $target = new LocalContentStorage($this->directory());
        $revision = $this->publication->status()['currentRevision'];
        $dry = $this->exporter->export($revision, $target, false);
        self::assertFalse($dry['verified']); self::assertSame([], $target->list(''));
        $report = $this->exporter->export($revision, $target, true);
        self::assertTrue($report['verified']); self::assertSame(count($target->list('')), $report['objectCount']);
        self::assertSame($dry['checksum'], $report['checksum']);
        $themes = json_decode($target->read('themes.json'), true)['themes'];
        $theme = array_values(array_filter($themes, fn (array $t): bool => $t['id'] === $this->theme))[0];
        self::assertFalse($theme['active']); self::assertSame(['dev'], $theme['tags']);
        $topic = json_decode($target->read($this->theme.'/index.json'), true)['topics'][0];
        self::assertFalse($topic['active']); self::assertArrayNotHasKey('tags', $topic);
        self::assertSame('Explain precisely.'."\n", $target->read($this->theme.'/ai-prompts/question-solution-prompt.txt'));
        foreach (['en-US', 'pt-BR'] as $locale) { self::assertTrue($target->exists($this->theme.'/'.$locale.'/topic/1/q52.json')); }
        self::assertSame($revision, $this->publication->status()['publishedRevision']);
        self::assertTrue($this->exporter->compare($revision, $target, true)['verified']);
        $this->expectException(RuntimeException::class);
        $this->exporter->export($revision, $target, true);
    }

    public function testComparisonRejectsJsonObjectsUsedInPlaceOfOptionArrays(): void
    {
        $this->seed();
        $revision = $this->publication->status()['currentRevision'];
        $target = new LocalContentStorage($this->directory());
        $this->exporter->export($revision, $target, true);
        $key = $this->theme.'/en-US/topic/1/one.json';
        $payload = json_decode($target->read($key), true);
        $payload['correctOptions'] = (object) ['0' => 'Correct'];
        $target->write($key, json_encode($payload, JSON_THROW_ON_ERROR));
        $report = $this->exporter->compare($revision, $target, true);
        self::assertFalse($report['verified']);
        self::assertContains($key, $report['differences']);
    }

    public function testStaleRevisionFailsBeforeExportWrites(): void
    {
        $target = new LocalContentStorage($this->directory());
        try { $this->exporter->export($this->publication->status()['currentRevision'] + 1, $target, true); self::fail('Stale revision accepted.'); }
        catch (RuntimeException) {}
        self::assertSame([], $target->list(''));
    }

    public function testFallbackRequiresFreshVerifiedExportAndCompatibleReaderAndFreeze(): void
    {
        $this->seed();
        $revision = $this->publication->status()['currentRevision'];
        $target = new LocalContentStorage($this->directory());
        $report = $this->exporter->export($revision, $target, true);
        try { $this->backend->run('content_storage', $revision, $report, false); self::fail('Reader evidence missing.'); }
        catch (RuntimeException $e) { self::assertStringContainsString('version-2-compatible', $e->getMessage()); }
        $report += ['snapshotReaderVersion' => 2, 'snapshotReaderVersionSource' => 'operator-attested-compatible-api-image'];
        self::assertFalse($this->backend->run('content_storage', $revision, $report, false)['applied']);
        try { $this->backend->run('content_storage', $revision, $report, true); self::fail('Freeze missing.'); }
        catch (RuntimeException $e) { self::assertStringContainsString('writes-frozen', $e->getMessage()); }
        $result = $this->backend->run('content_storage', $revision, $report, true, true);
        self::assertTrue($result['applied']); self::assertSame('content_storage', $this->publication->status()['backend']);
        $comparison = $this->exporter->compare($revision, $target) + ['legacyProviderRuns' => 0, 'legacyProviderRunsSource' => 'operator-attested-api-metric'];
        self::assertTrue($this->backend->run('postgres', $revision, $comparison, true, true)['applied']);
    }

    public function testPendingLegacyAndStaleOrChangedTargetEvidenceCannotPromote(): void
    {
        $this->seed();
        $revision = $this->publication->status()['currentRevision'];
        $target = new LocalContentStorage($this->directory());
        $this->exporter->export($revision, $target, true);
        $report = $this->exporter->compare($revision, $target) + ['legacyProviderRuns' => 0, 'legacyProviderRunsSource' => 'operator-attested-api-metric'];
        $db = $this->database->connection();
        $db->exec("UPDATE quiz_catalog_state SET publication_backend='content_storage',current_revision=current_revision+1 WHERE singleton=1");
        try { $this->backend->run('postgres', $revision + 1, $report, true, true); self::fail('Pending revision accepted.'); }
        catch (RuntimeException $e) { self::assertStringContainsString('already published', $e->getMessage()); }
        $db->exec('UPDATE quiz_catalog_state SET current_revision=published_revision WHERE singleton=1');
        $target->write($this->theme.'/en-US/topic/1/one.json', '{"prompt":"tampered"}');
        try { $this->backend->run('postgres', $revision, $report, false); self::fail('Changed target accepted.'); }
        catch (RuntimeException $e) { self::assertStringContainsString('target has changed', $e->getMessage()); }
        self::assertSame('content_storage', $this->publication->status()['backend']);
    }

    public function testDedicatedReaderCanSelectViewsButCannotReadBaseTablesWriteOrCreate(): void
    {
        $db = $this->database->connection();
        if (!$db->query("SELECT rolcreaterole OR rolsuper FROM pg_roles WHERE rolname=current_user")->fetchColumn()) {
            self::markTestSkipped('Reader provisioning needs an operator role with CREATEROLE.');
        }
        $this->seed();
        $this->reader = 'runtime_test_'.bin2hex(random_bytes(4));
        $provisioner = new QuizRuntimeReaderProvisioner($this->database);
        self::assertFalse($provisioner->run($this->reader, '', false)['applied']);
        self::assertTrue($provisioner->run($this->reader, 'synthetic_test_password', true)['applied']);
        $parts = parse_url($this->url);
        $readerUrl = 'postgresql://'.$this->reader.':synthetic_test_password@'.$parts['host'].':'.($parts['port'] ?? 5432).$parts['path'];
        $reader = DatabaseConnectionFactory::connect($readerUrl);
        foreach (QuizRuntimeReaderProvisioner::VIEWS as $view) { self::assertIsArray($reader->query('SELECT * FROM quiz_runtime_v1.'.$view.' LIMIT 1')->fetchAll()); }
        $reader->exec('SET default_transaction_read_only=off');
        foreach (['SELECT * FROM public.quiz_questions', 'SELECT * FROM public.ai_prompts',
            'UPDATE quiz_runtime_v1.state SET current_revision=0', 'CREATE TABLE public.reader_denied (id int)',
            'CREATE TABLE quiz_runtime_v1.reader_denied (id int)', 'CREATE SCHEMA reader_denied', 'CREATE TEMP TABLE reader_denied (id int)'] as $sql) {
            try { $reader->exec($sql); self::fail('Reader unexpectedly permitted: '.$sql); }
            catch (PDOException $e) { self::assertSame('42501', $e->getCode()); }
        }
    }

    public function testCompiledKernelBootLoginCrudAndPromptSaveWithoutS3OrLegacyMount(): void
    {
        $settings = [
            'APP_SECRET' => 'synthetic-kernel-secret', 'MANAGER_DATABASE_URL' => $this->url,
            'MANAGER_QUIZ_PERSISTENCE_PROVIDER' => 'postgres', 'MANAGER_QUIZ_PUBLICATION_PROVIDER' => 'postgres',
            'MANAGER_CONTENT_STORAGE_PROVIDER' => 's3', 'S3_BUCKET' => '', 'MANAGER_CONTENT_ROOT' => '',
            'FALLBACK_LOCALE' => 'en-US', 'SUPPORTED_LOCALES' => 'en-US,pt-BR',
            'MANAGER_ADMIN_API_TOKEN' => 'synthetic-kernel-api-token-0123456789abcdef', 'OPENAI_API_KEY' => '',
        ];
        foreach (array_keys($settings) as $key) { $settings[$key.'__FILE'] = null; }
        $previous = [];
        foreach ($settings as $key => $value) {
            $previous[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            if ($value === null) { putenv($key); unset($_ENV[$key], $_SERVER[$key]); }
            else { putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value; }
        }
        $kernel = new \App\Kernel('runtime_smoke_'.bin2hex(random_bytes(5)), false);
        try {
            $session = new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
            $request = \Symfony\Component\HttpFoundation\Request::create('/login'); $request->setSession($session);
            $response = $kernel->handle($request); $kernel->terminate($request, $response);
            self::assertSame(200, $response->getStatusCode()); self::assertStringContainsString('name="password"', (string) $response->getContent());
            $request = \Symfony\Component\HttpFoundation\Request::create('/api/admin/quiz/themes', 'POST', server: [
                'HTTP_AUTHORIZATION' => 'Bearer synthetic-kernel-api-token-0123456789abcdef', 'CONTENT_TYPE' => 'application/json',
            ], content: json_encode(['id' => $this->theme, 'name' => 'Agronomy', 'active' => false]));
            $response = $kernel->handle($request); $kernel->terminate($request, $response);
            self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
            $publication = json_decode((string) $response->getContent(), true)['publication'];
            self::assertSame('postgres', $publication['backend']); self::assertFalse($publication['apiReloadRequired']);
            self::assertSame($publication['currentRevision'], $publication['publishedRevision']);
            $session->set('admin_id', 1); $session->set('admin_email', 'test@example.invalid'); $session->set('selected_theme', $this->theme);
            $request = \Symfony\Component\HttpFoundation\Request::create('/ai-prompts/question_solution/edit'); $request->setSession($session);
            $response = $kernel->handle($request); $kernel->terminate($request, $response);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            foreach (['save', 'restore'] as $action) {
                $request = \Symfony\Component\HttpFoundation\Request::create('/ai-prompts/question_solution/'.$action, 'POST', [
                    '_csrf' => $session->get('csrf_token'), 'text' => 'Explain the agricultural reasoning.',
                ]); $request->setSession($session);
                $response = $kernel->handle($request); $kernel->terminate($request, $response);
                self::assertSame(302, $response->getStatusCode(), (string) $response->getContent());
            }
        } finally {
            $kernel->shutdown(); (new \Symfony\Component\Filesystem\Filesystem())->remove($kernel->getCacheDir());
            foreach ($previous as $key => [$process, $env, $server]) {
                $process === false ? putenv($key) : putenv($key.'='.$process);
                if ($env === null) { unset($_ENV[$key]); } else { $_ENV[$key] = $env; }
                if ($server === null) { unset($_SERVER[$key]); } else { $_SERVER[$key] = $server; }
            }
        }
    }

    private function seed(bool $themeActive = true, bool $topicActive = true): void
    {
        $this->writer->saveTheme(['id' => $this->theme, 'name' => 'Study', 'active' => $themeActive]);
        $this->writer->saveTopicSet($this->theme, ['key' => 'topic', 'name' => 'Topic', 'active' => $topicActive]);
        $this->writer->createLocalizedQuestionSets($this->theme, 'topic', 1, [$this->question('one')]);
    }

    private function question(string $id, string $prompt = 'Question'): array
    {
        $translations = [];
        foreach (['en-US', 'pt-BR'] as $locale) { $translations[$locale] = ['prompt' => $prompt.' '.$locale,
            'correctOptions' => ['Correct'], 'wrongOptions' => ['Wrong one', 'Wrong two']]; }
        return ['id' => $id, 'translations' => $translations];
    }

    private function viewCount(string $view): int
    {
        $query = $this->database->connection()->prepare('SELECT COUNT(*) FROM quiz_runtime_v1.'.$view.' WHERE theme_id=:theme');
        $query->execute(['theme' => $this->theme]);
        return (int) $query->fetchColumn();
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir().'/qq-runtime-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true); $this->directories[] = $directory; return $directory;
    }
}
