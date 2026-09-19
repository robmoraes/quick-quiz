<?php

namespace App\Exception;

use RuntimeException;

final class QuizPublicationException extends RuntimeException
{
    public function __construct(public readonly int $revision)
    {
        parent::__construct(sprintf('Quiz revision %d was saved but publication failed; retry publication before reloading the Quiz API.', $revision));
    }
}
