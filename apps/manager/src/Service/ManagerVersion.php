<?php

namespace App\Service;

final class ManagerVersion
{
    private readonly string $version;

    public function __construct(string $version)
    {
        $version = trim($version);
        $this->version = $version !== '' ? $version : '0.3.0';
    }

    public function value(): string
    {
        return $this->version;
    }
}
