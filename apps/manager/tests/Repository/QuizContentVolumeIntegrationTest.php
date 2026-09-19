<?php

namespace App\Tests\Repository;

use App\Repository\ManagerDatabase;
use App\Repository\MigrationRunner;
use App\Repository\QuizContentRepository;
use App\Service\QuizContentRules;
use App\Service\QuizContentStatistics;
use PHPUnit\Framework\TestCase;

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
            $repository = new QuizContentRepository($database);
            $statistics = new QuizContentStatistics($repository, new QuizContentRules('en-US', 'en-US,pt-BR'), 10);
            $start = hrtime(true);
            foreach ($themes as [$theme, $topic, $count]) {
                self::assertSame($count, $repository->topics($theme, 'pt-BR', 'en-US')[0]['questionCount']);
                self::assertCount($count, $repository->questions($theme, $topic, 'pt-BR', 1));
                self::assertSame($count, $statistics->forTheme($theme)['totals']['canonicalQuestions']);
            }
            $elapsedMs = round((hrtime(true) - $start) / 1_000_000, 2);
            if (getenv('QUIZ_BENCHMARK_OUTPUT') === '1') {
                fwrite(STDERR, sprintf("409 synthetic canonical questions: %.2f ms for both theme, topic, question, and stats reads.\n", $elapsedMs));
            }
        } finally {
            $db->rollBack();
        }
    }
}
