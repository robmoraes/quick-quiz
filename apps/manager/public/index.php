<?php

use App\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->handle(\Symfony\Component\HttpFoundation\Request::createFromGlobals())->send();
