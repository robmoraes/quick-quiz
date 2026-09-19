<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Repository\ManagerDatabase;
use App\Repository\QuizContentWriter;
use App\Service\QuizContentRules;

[$script, $theme, $topic, $barrier, $prefix] = $argv;
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
$reader = new \App\Repository\QuizContentRepository($database);
$input = $reader->topics($theme, 'en-US', 'en-US')[0];
$input['tags'] = [$prefix.'-one', $prefix.'-two'];
$result = $writer->saveTopicSet($theme, $input);
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
