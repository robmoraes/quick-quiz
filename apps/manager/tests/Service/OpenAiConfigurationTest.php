<?php

namespace App\Tests\Service;

use App\Service\OpenAiConfiguration;
use PHPUnit\Framework\TestCase;

final class OpenAiConfigurationTest extends TestCase
{
    public function testRequiresApiKeyAndModel(): void
    {
        self::assertFalse((new OpenAiConfiguration('', ''))->isConfigured());
        self::assertFalse((new OpenAiConfiguration('test-key', ''))->isConfigured());
        self::assertFalse((new OpenAiConfiguration('', 'gpt-test'))->isConfigured());
        self::assertTrue((new OpenAiConfiguration('test-key', 'gpt-test'))->isConfigured());
    }

    public function testExposesTrimmedModel(): void
    {
        self::assertSame('gpt-test', (new OpenAiConfiguration('test-key', ' gpt-test '))->model());
    }
}
