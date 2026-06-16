<?php

namespace App\Tests\Service;

use App\Service\ManagerVersion;
use PHPUnit\Framework\TestCase;

final class ManagerVersionTest extends TestCase
{
    public function testExposesTrimmedVersion(): void
    {
        self::assertSame('1.2.3', (new ManagerVersion(' 1.2.3 '))->value());
    }

    public function testFallsBackWhenVersionIsEmpty(): void
    {
        self::assertSame('0.4.0', (new ManagerVersion(''))->value());
    }
}
