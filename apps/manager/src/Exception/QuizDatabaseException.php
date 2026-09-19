<?php

namespace App\Exception;

use RuntimeException;

final class QuizDatabaseException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Quiz database is unavailable.');
    }
}
