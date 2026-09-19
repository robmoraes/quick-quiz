<?php

namespace App\Exception;

use RuntimeException;

final class TopicTagsUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Topic tags are unavailable with the current quiz persistence provider.');
    }
}
