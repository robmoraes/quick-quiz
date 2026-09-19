<?php

namespace App\Tests\Support;

use PDOStatement;

final class CountingStatement extends PDOStatement
{
    public static int $executions = 0;

    public function execute(?array $params = null): bool
    {
        ++self::$executions;
        return parent::execute($params);
    }
}
