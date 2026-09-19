<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Repository\ManagerDatabase;
use App\Repository\QuizContentWriter;
use App\Service\QuizContentRules;

[$script, $theme, $topic, $barrier] = $argv;
$deadline = microtime(true) + 10;
while (!is_file($barrier)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "barrier timeout\n");
        exit(2);
    }
    usleep(10000);
}
$database = new ManagerDatabase((string) getenv('MANAGER_DATABASE_URL'));
$writer = new QuizContentWriter($database, new QuizContentRules('en-US', 'en-US,pt-BR'));
$result = $writer->createLocalizedQuestionSets($theme, $topic, 1, [[
    'translations' => [
        'en-US' => ['prompt' => 'Concurrent?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C']],
        'pt-BR' => ['prompt' => 'Concorrente?', 'correctOptions' => ['A'], 'wrongOptions' => ['B', 'C']],
    ],
]]);
echo $result['questionIds'][0], "\n";
