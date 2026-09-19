<?php

namespace App\Tests\Repository;

use App\Repository\ManagerDatabase;
use App\Repository\MigrationRunner;
use App\Repository\QuizContentRepository;
use App\Service\QuizContentRules;
use App\Service\QuizContentStatistics;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\CountingStatement;
use PDO;

final class QuizContentVolumeIntegrationTest extends TestCase
{
    public function testGroupedNavigationAtCurrentCatalogVolume(): void
    {
        $url = (string) getenv('MANAGER_DATABASE_URL');
        if (!str_starts_with($url, 'postgres://') && !str_starts_with($url, 'postgresql://')) {
            self::markTestSkipped('MANAGER_DATABASE_URL is not PostgreSQL.');
        }
        $database = new ManagerDatabase($url);
        (new MigrationRunner($database, dirname(__DIR__, 2).'/migrations'))->migrate();
        $db = $database->connection();
        $db->beginTransaction();
        try {
            $suffix = bin2hex(random_bytes(5));
            $themes = [
                ['volume-a-'.$suffix, 'alpha', 128],
                ['volume-b-'.$suffix, 'beta', 281],
            ];
            foreach ($themes as [$theme, $topic, $count]) {
                $statement = $db->prepare('INSERT INTO quiz_themes
                    (id,name,description,weight,active,created_at,updated_at)
                    VALUES (:theme,:name,\'\',100,TRUE,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
                $statement->execute(['theme' => $theme, 'name' => $theme]);
                $statement = $db->prepare('INSERT INTO quiz_topics
                    (theme_id,topic_key,name,description,weight,active,created_at,updated_at)
                    VALUES (:theme,:topic,:name,\'\',100,TRUE,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
                $statement->execute(['theme' => $theme, 'topic' => $topic, 'name' => $topic]);
                $statement = $db->prepare('INSERT INTO quiz_questions
                    (theme_id,topic_key,difficulty,question_id,created_at,updated_at)
                    SELECT :theme,:topic,1,:topic_prefix || \'-1-\' || lpad(number::text,3,\'0\'),
                        CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
                    FROM generate_series(1,:amount) AS number');
                $statement->execute([
                    'theme' => $theme, 'topic' => $topic,
                    'topic_prefix' => $topic, 'amount' => $count,
                ]);
                $statement = $db->prepare('INSERT INTO quiz_question_translations
                    (theme_id,topic_key,difficulty,question_id,locale,prompt,updated_at)
                    SELECT question.theme_id,question.topic_key,question.difficulty,question.question_id,
                        locale.locale,\'Synthetic question\',CURRENT_TIMESTAMP
                    FROM quiz_questions question
                    CROSS JOIN (VALUES (\'en-US\'),(\'pt-BR\')) AS locale(locale)
                    WHERE question.theme_id=:theme');
                $statement->execute(['theme' => $theme]);
                $statement = $db->prepare('INSERT INTO quiz_answers
                    (theme_id,topic_key,difficulty,question_id,locale,kind,answer_position,answer_text)
                    SELECT translation.theme_id,translation.topic_key,translation.difficulty,
                        translation.question_id,translation.locale,answer.kind,
                        answer.position,answer.text
                    FROM quiz_question_translations translation
                    CROSS JOIN (VALUES (\'correct\',0,\'A\'),(\'wrong\',0,\'B\'),(\'wrong\',1,\'C\'))
                        AS answer(kind,position,text)
                    WHERE translation.theme_id=:theme');
                $statement->execute(['theme' => $theme]);
            }
            $db->exec("INSERT INTO quiz_tags(slug) VALUES ('aws'),('cloud') ON CONFLICT DO NOTHING");
            foreach ($themes as [$theme, $topic, $count]) {
                $db->prepare("INSERT INTO quiz_topic_tags(theme_id,topic_key,tag_slug)
                    VALUES (:theme,:topic,'aws'),(:theme,:topic,'cloud')")->execute(['theme' => $theme, 'topic' => $topic]);
            }
            $repository = new QuizContentRepository($database);
            $statistics = new QuizContentStatistics($repository, new QuizContentRules('en-US', 'en-US,pt-BR'), 10);
            $start = hrtime(true);
            foreach ($themes as [$theme, $topic, $count]) {
                $topics = $repository->topics($theme, 'pt-BR', 'en-US');
                self::assertSame($count, $topics[0]['questionCount']);
                self::assertSame(['aws', 'cloud'], $topics[0]['tags']);
                self::assertCount($count, $repository->questions($theme, $topic, 'pt-BR', 1));
                self::assertSame($count, $statistics->forTheme($theme)['totals']['canonicalQuestions']);
            }
            // Increasing the number of tagged topics must not add a query per topic.
            $db->prepare("INSERT INTO quiz_topics(theme_id,topic_key,name,description,weight,active,created_at,updated_at)
                SELECT :theme,'extra-' || n,'Extra','',200,TRUE,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
                FROM generate_series(1,100) n")->execute(['theme' => $themes[0][0]]);
            $db->prepare("INSERT INTO quiz_topic_tags(theme_id,topic_key,tag_slug)
                SELECT theme_id,topic_key,'aws' FROM quiz_topics WHERE theme_id=:theme AND topic_key LIKE 'extra-%'")
                ->execute(['theme' => $themes[0][0]]);
            $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class]);
            CountingStatement::$executions = 0;
            $topics = $repository->topics($themes[0][0], 'pt-BR', 'en-US');
            self::assertSame(1, CountingStatement::$executions);
            self::assertCount(101, $topics);
            self::assertSame(['aws'], $topics[100]['tags']);
            $elapsedMs = round((hrtime(true) - $start) / 1_000_000, 2);
            if (getenv('QUIZ_BENCHMARK_OUTPUT') === '1') {
                fwrite(STDERR, sprintf("409 synthetic canonical questions: %.2f ms for both theme, topic, question, and stats reads.\n", $elapsedMs));
            }
        } finally {
            $db->rollBack();
        }
    }
}
