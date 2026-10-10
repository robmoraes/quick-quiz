<?php

namespace App\Exception;

use RuntimeException;

final class ThemeTagsUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Theme tags are unavailable with the current quiz persistence provider.');
    }
}
