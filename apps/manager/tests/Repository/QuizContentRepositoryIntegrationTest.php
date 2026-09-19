<?php

namespace App\Tests\Repository;

use App\Repository\ManagerDatabase;
use App\Repository\MigrationRunner;
use App\Repository\QuizContentRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class QuizContentRepositoryIntegrationTest extends TestCase
{
    private ManagerDatabase $database;
    private QuizContentRepository $repository;
    private string $theme;
    private string $otherTheme;

    protected function setUp(): void
    {
        $databaseUrl = (string) getenv('MANAGER_DATABASE_URL');
        if (!str_starts_with($databaseUrl, 'postgres://') && !str_starts_with($databaseUrl, 'postgresql://')) {
            self::markTestSkipped('MANAGER_DATABASE_URL is not PostgreSQL.');
        }

        $suffix = bin2hex(random_bytes(5));
        $this->theme = 'integration-'.$suffix;
        $this->otherTheme = 'integration-other-'.$suffix;
        $this->database = new ManagerDatabase($databaseUrl);
        (new MigrationRunner($this->database, dirname(__DIR__, 2).'/migrations'))->migrate();
        $this->repository = new QuizContentRepository($this->database);
        $this->seedCatalog();
    }

    protected function tearDown(): void
    {
        if (!isset($this->database)) {
            return;
        }

        $statement = $this->database->connection()->prepare(
            'DELETE FROM quiz_themes WHERE id IN (:theme, :other_theme)',
        );
        $statement->execute([
            'theme' => $this->theme,
            'other_theme' => $this->otherTheme,
        ]);
    }

    public function testListsThemesInCatalogOrderAndFindsOneTheme(): void
    {
        $themes = array_values(array_filter(
            $this->repository->themes(),
            fn (array $theme): bool => in_array($theme['id'], [$this->theme, $this->otherTheme], true),
        ));

        self::assertSame([$this->theme, $this->otherTheme], array_column($themes, 'id'));
        self::assertSame('Integration Theme', $this->repository->theme($this->theme)['name'] ?? null);
        self::assertSame('2026-09-18T12:00:00+00:00', $themes[0]['createdAt']);
        self::assertTrue($themes[0]['active']);
        self::assertNull($this->repository->theme('missing-'.$this->theme));
    }

    public function testListsLocalizedTopicsWithCanonicalQuestionCounts(): void
    {
        $topics = $this->repository->topics($this->theme, 'pt-BR', 'en-US');

        self::assertSame(['php', 'go'], array_column($topics, 'key'));
        self::assertSame('PHP em Português', $topics[0]['localizedName']);
        self::assertSame('Conceitos de PHP.', $topics[0]['localizedDescription']);
        self::assertSame(2, $topics[0]['questionCount']);
        self::assertSame(0, $topics[1]['questionCount']);
        self::assertSame('', $topics[1]['localizedName']);
    }

    public function testListsQuestionSummariesWithAnswerCountsAndDifficultyFilter(): void
    {
        $questions = $this->repository->questions($this->theme, 'php', 'pt-BR');

        self::assertSame(['php-1-001', 'php-2-001'], array_column($questions, 'id'));
        self::assertSame('Qual tag inicia PHP?', $questions[0]['prompt']);
        self::assertSame(1, $questions[0]['correctCount']);
        self::assertSame(2, $questions[0]['wrongCount']);
        self::assertSame(
            ['php-2-001'],
            array_column($this->repository->questions($this->theme, 'php', 'pt-BR', 2), 'id'),
        );
    }

    public function testReadsLocalizedQuestionSetWithStableAnswerOrder(): void
    {
        $set = $this->repository->localizedQuestionSet($this->theme, 'php', 1, 'php-1-001');

        self::assertSame(['en-US', 'pt-BR'], array_keys($set));
        self::assertSame(['<?php'], $set['en-US']['correctOptions']);
        self::assertSame(['<?', '<script>'], $set['en-US']['wrongOptions']);
        self::assertSame(['<?', '<script>'], $set['pt-BR']['wrongOptions']);
    }

    public function testCountsCanonicalQuestionsByDifficulty(): void
    {
        self::assertSame(
            [1 => 1, 2 => 1],
            $this->repository->questionCountsByDifficulty($this->theme, 'php', 'en-US'),
        );
    }

    public function testThemeDeletionCascadesThroughQuestionsAndAnswers(): void
    {
        $statement = $this->database->connection()->prepare('DELETE FROM quiz_themes WHERE id = :theme');
        $statement->execute(['theme' => $this->theme]);

        foreach (['quiz_topics', 'quiz_questions', 'quiz_question_translations', 'quiz_answers'] as $table) {
            $statement = $this->database->connection()->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE theme_id = :theme', $table));
            $statement->execute(['theme' => $this->theme]);
            self::assertSame(0, (int) $statement->fetchColumn(), $table);
        }
    }

    private function seedCatalog(): void
    {
        $this->insert(
            'INSERT INTO quiz_themes (id, name, description, weight, active, created_at, updated_at)
             VALUES (:id, :name, :description, :weight, :active, :created_at, :updated_at)',
            [
                'id' => $this->theme,
                'name' => 'Integration Theme',
                'description' => 'Repository integration tests.',
                'weight' => 10,
                'active' => 'true',
                'created_at' => '2026-09-18T12:00:00+00:00',
                'updated_at' => '2026-09-18T12:00:00+00:00',
            ],
        );
        $this->insert(
            'INSERT INTO quiz_themes (id, name, description, weight, active, created_at, updated_at)
             VALUES (:id, :name, :description, :weight, :active, :created_at, :updated_at)',
            [
                'id' => $this->otherTheme,
                'name' => 'Other Theme',
                'description' => '',
                'weight' => 20,
                'active' => 'false',
                'created_at' => '2026-09-18T13:00:00+00:00',
                'updated_at' => '2026-09-18T13:00:00+00:00',
            ],
        );
        $this->topic('php', 'PHP', 10, true);
        $this->topic('go', 'Go', 20, false);
        $this->insert(
            'INSERT INTO quiz_topic_translations
                (theme_id, topic_key, locale, name, description, updated_at)
             VALUES (:theme, :topic, :locale, :name, :description, :updated_at)',
            [
                'theme' => $this->theme,
                'topic' => 'php',
                'locale' => 'pt-BR',
                'name' => 'PHP em Português',
                'description' => 'Conceitos de PHP.',
                'updated_at' => '2026-09-18T12:00:00+00:00',
            ],
        );
        $this->question(1, 'php-1-001', [
            'en-US' => ['Which tag starts PHP?', ['<?php'], ['<?', '<script>']],
            'pt-BR' => ['Qual tag inicia PHP?', ['<?php'], ['<?', '<script>']],
        ]);
        $this->question(2, 'php-2-001', [
            'en-US' => ['Which construct outputs text?', ['echo'], ['select', 'mount', 'render', 'display']],
            'pt-BR' => ['Qual construção exibe texto?', ['echo'], ['select', 'mount', 'render', 'display']],
        ]);
    }

    private function topic(string $key, string $name, int $weight, bool $active): void
    {
        $this->insert(
            'INSERT INTO quiz_topics
                (theme_id, topic_key, name, description, weight, active, created_at, updated_at)
             VALUES (:theme, :key, :name, :description, :weight, :active, :created_at, :updated_at)',
            [
                'theme' => $this->theme,
                'key' => $key,
                'name' => $name,
                'description' => $name.' concepts.',
                'weight' => $weight,
                'active' => $active ? 'true' : 'false',
                'created_at' => '2026-09-18T12:00:00+00:00',
                'updated_at' => '2026-09-18T12:00:00+00:00',
            ],
        );
    }

    /** @param array<string,array{0:string,1:list<string>,2:list<string>}> $translations */
    private function question(int $difficulty, string $questionId, array $translations): void
    {
        $parameters = [
            'theme' => $this->theme,
            'topic' => 'php',
            'difficulty' => $difficulty,
            'question_id' => $questionId,
            'created_at' => '2026-09-18T12:00:00+00:00',
            'updated_at' => '2026-09-18T12:00:00+00:00',
        ];
        $this->insert(
            'INSERT INTO quiz_questions
                (theme_id, topic_key, difficulty, question_id, created_at, updated_at)
             VALUES (:theme, :topic, :difficulty, :question_id, :created_at, :updated_at)',
            $parameters,
        );

        foreach ($translations as $locale => [$prompt, $correct, $wrong]) {
            $this->insert(
                'INSERT INTO quiz_question_translations
                    (theme_id, topic_key, difficulty, question_id, locale, prompt, updated_at)
                 VALUES (:theme, :topic, :difficulty, :question_id, :locale, :prompt, :updated_at)',
                [
                    'theme' => $this->theme,
                    'topic' => 'php',
                    'difficulty' => $difficulty,
                    'question_id' => $questionId,
                    'locale' => $locale,
                    'prompt' => $prompt,
                    'updated_at' => '2026-09-18T12:00:00+00:00',
                ],
            );
            foreach (['correct' => $correct, 'wrong' => $wrong] as $kind => $answers) {
                foreach ($answers as $position => $answer) {
                    $this->insert(
                        'INSERT INTO quiz_answers
                            (theme_id, topic_key, difficulty, question_id, locale, kind, answer_position, answer_text)
                         VALUES (:theme, :topic, :difficulty, :question_id, :locale, :kind, :position, :answer)',
                        [
                            'theme' => $this->theme,
                            'topic' => 'php',
                            'difficulty' => $difficulty,
                            'question_id' => $questionId,
                            'locale' => $locale,
                            'kind' => $kind,
                            'position' => $position,
                            'answer' => $answer,
                        ],
                    );
                }
            }
        }
    }

    /** @param array<string,mixed> $parameters */
    private function insert(string $sql, array $parameters): void
    {
        $statement = $this->database->connection()->prepare($sql);
        $statement->execute($parameters);
    }
}
