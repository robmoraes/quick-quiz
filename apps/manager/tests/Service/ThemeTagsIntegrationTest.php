<?php

namespace App\Tests\Service;

use App\Controller\QuizAdminController;
use App\Exception\QuizDatabaseException;
use App\Exception\QuizPublicationException;
use App\Repository\ManagerDatabase;
use App\Repository\MigrationRunner;
use App\Repository\QuizContentRepository;
use App\Repository\QuizContentWriter;
use App\Service\PostgresQuizAuthoringService;
use App\Service\QuizAdministrationService;
use App\Service\QuizContentComparator;
use App\Service\QuizContentRules;
use App\Service\QuizContentStatistics;
use App\Service\QuizProjectionPublisher;
use App\Service\QuizProjectionRenderer;
use App\Service\QuizPublicationService;
use App\Tests\Support\FaultInjectingContentStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class ThemeTagsIntegrationTest extends TestCase
{
    private ManagerDatabase $database;
    private QuizAdministrationService $admin;
    private QuizPublicationService $publication;
    private QuizProjectionRenderer $renderer;
    private FaultInjectingContentStorage $storage;
    private string $theme;

    protected function setUp(): void
    {
        $url = (string) getenv('MANAGER_DATABASE_URL');
        if (!str_starts_with($url, 'postgres://') && !str_starts_with($url, 'postgresql://')) {
            self::markTestSkipped('MANAGER_DATABASE_URL is not PostgreSQL.');
        }
        $this->database = new ManagerDatabase($url);
        (new MigrationRunner($this->database, dirname(__DIR__, 2).'/migrations'))->migrate();
        $this->theme = 'theme-tags-'.bin2hex(random_bytes(5));
        $rules = new QuizContentRules('en-US', 'en-US,pt-BR');
        $reader = new QuizContentRepository($this->database);
        $this->renderer = new QuizProjectionRenderer($this->database, $rules);
        $this->storage = new FaultInjectingContentStorage();
        $this->publication = new QuizPublicationService($this->database, $this->renderer,
            new QuizProjectionPublisher($this->storage, new QuizContentComparator()));
        $packs = new PostgresQuizAuthoringService($reader, new QuizContentWriter($this->database, $rules),
            new QuizContentStatistics($reader, $rules, 10), $rules, $this->publication, fixedTheme: $this->theme);
        $this->admin = new QuizAdministrationService($packs);
        $this->admin->createTheme(['id' => $this->theme, 'name' => 'Kubernetes', 'tags' => [' Dev ', 'cloud', 'dev']]);
        $this->admin->createTopic($this->theme, ['key' => 'pods', 'name' => 'Pods', 'description' => '', 'tags' => ['dev']]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $db = $this->database->connection();
            $db->prepare('DELETE FROM quiz_themes WHERE id=:id')->execute(['id' => $this->theme]);
            $db->prepare('DELETE FROM quiz_publications WHERE affected_theme_id=:id')->execute(['id' => $this->theme]);
        }
    }

    public function testTagsAppearOnAllReadSurfacesAndOmissionPreservesThem(): void
    {
        self::assertSame(['cloud', 'dev'], $this->admin->theme($this->theme)['tags']);
        $themes = array_column($this->admin->themes(), null, 'id');
        self::assertSame(['cloud', 'dev'], $themes[$this->theme]['tags']);
        $catalog = array_column($this->admin->catalog()['themes'], null, 'id');
        self::assertSame(['cloud', 'dev'], $catalog[$this->theme]['tags']);
        $result = $this->admin->replaceTheme($this->theme, $this->input(['name' => 'Renamed']));
        self::assertSame(['cloud', 'dev'], $result['theme']['tags']);
        $fresh = new QuizContentRepository(new ManagerDatabase((string) getenv('MANAGER_DATABASE_URL')));
        self::assertSame(['cloud', 'dev'], $fresh->theme($this->theme)['tags']);
    }

    public function testThemeTagsOnlySavePublishesWithoutChangingTopicProjectionOrTags(): void
    {
        $before = $this->renderer->render();
        $revision = $this->publication->status()['currentRevision'];
        $result = $this->admin->replaceTheme($this->theme, $this->input(['tags' => []]));
        self::assertSame([], $result['theme']['tags']);
        self::assertTrue($result['publication']['apiReloadRequired']);
        self::assertSame($revision + 1, $result['publication']['revision']);
        $published = json_decode($this->storage->read('themes.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], array_column($published['themes'], null, 'id')[$this->theme]['tags']);
        $after = $this->renderer->render();
        unset($before['themes.json'], $after['themes.json']);
        self::assertSame($before, $after);
        self::assertSame(['dev'], $this->admin->topic($this->theme, 'pods')['tags']);
    }

    public function testMalformedJsonTagsCannotMutateMetadataOrPublication(): void
    {
        $controller = new QuizAdminController($this->admin, new NullLogger());
        $before = $this->publication->status();
        foreach ([null, 'dev', (object) [], (object) ['0' => 'dev'], [42], ['dev ops'], ['á'], [str_repeat('a', 51)], array_fill(0, 21, 'dev')] as $tags) {
            $request = Request::create('/', 'PUT', content: json_encode($this->input(['name' => 'Invalid', 'tags' => $tags]), JSON_THROW_ON_ERROR));
            $response = $controller->replaceTheme($request, $this->theme);
            self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame('validation_failed', json_decode((string) $response->getContent(), true)['error']['code']);
        }
        self::assertSame('Kubernetes', $this->admin->theme($this->theme)['name']);
        self::assertSame(['cloud', 'dev'], $this->admin->theme($this->theme)['tags']);
        self::assertSame($before, $this->publication->status());
    }

    public function testDatabaseFailureRollsBackThemeTagsAndDictionary(): void
    {
        $db = $this->database->connection();
        $trigger = 'theme_tags_failure_'.bin2hex(random_bytes(5));
        $tag = 'rejected-'.bin2hex(random_bytes(5));
        $before = $this->publication->status();
        $db->exec("CREATE FUNCTION $trigger() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
            IF NEW.tag_slug = '$tag' THEN RAISE EXCEPTION 'injected failure'; END IF; RETURN NEW; END $$");
        $db->exec("CREATE TRIGGER $trigger BEFORE INSERT ON quiz_theme_tags FOR EACH ROW EXECUTE FUNCTION $trigger()");
        try {
            $this->admin->replaceTheme($this->theme, $this->input(['name' => 'Changed', 'tags' => [$tag]]));
            self::fail('Expected database failure.');
        } catch (QuizDatabaseException) {
            self::assertSame('Kubernetes', $this->admin->theme($this->theme)['name']);
            self::assertSame(['cloud', 'dev'], $this->admin->theme($this->theme)['tags']);
            $statement = $db->prepare('SELECT COUNT(*) FROM quiz_tags WHERE slug=:tag');
            $statement->execute(['tag' => $tag]);
            self::assertSame(0, (int) $statement->fetchColumn());
            self::assertSame($before, $this->publication->status());
        } finally {
            $db->exec("DROP TRIGGER $trigger ON quiz_theme_tags");
            $db->exec("DROP FUNCTION $trigger()");
        }
    }

    public function testFailedPublicationKeepsSavedTagsAndCanBeRetried(): void
    {
        $before = $this->storage->read('themes.json');
        $this->storage->failOnMutation(1);
        try {
            $this->admin->replaceTheme($this->theme, $this->input(['tags' => ['study']]));
            self::fail('Expected publication failure.');
        } catch (QuizPublicationException) {
            self::assertSame(['study'], $this->admin->theme($this->theme)['tags']);
            self::assertSame('failed', $this->publication->status()['status']);
            self::assertSame($before, $this->storage->read('themes.json'));
        }
        $this->publication->publishCurrent();
        $published = json_decode($this->storage->read('themes.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['study'], array_column($published['themes'], null, 'id')[$this->theme]['tags']);
        $this->admin->deleteTheme($this->theme, true);
        $statement = $this->database->connection()->prepare('SELECT COUNT(*) FROM quiz_theme_tags WHERE theme_id=:id');
        $statement->execute(['id' => $this->theme]);
        self::assertSame(0, (int) $statement->fetchColumn());
        self::assertSame(1, (int) $this->database->connection()->query("SELECT COUNT(*) FROM quiz_tags WHERE slug='study'")->fetchColumn());
    }

    private function input(array $changes = []): array
    {
        $theme = $this->admin->theme($this->theme);
        unset($theme['tags']);
        return array_replace($theme, $changes);
    }
}
