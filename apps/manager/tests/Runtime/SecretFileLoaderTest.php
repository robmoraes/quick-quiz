<?php

namespace App\Tests\Runtime;

use App\Runtime\SecretFileLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecretFileLoaderTest extends TestCase
{
    private const SECRET = 'QUICKQUIZ_TEST_SECRET';
    private const SECRET_FILE = self::SECRET.'__FILE';

    protected function tearDown(): void
    {
        unset($_ENV[self::SECRET], $_ENV[self::SECRET_FILE], $_SERVER[self::SECRET], $_SERVER[self::SECRET_FILE]);
        putenv(self::SECRET);
        putenv(self::SECRET_FILE);
    }

    public function testFileValueTakesPriorityAndTrailingNewlinesAreRemoved(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'quickquiz-secret-');
        self::assertNotFalse($file);
        file_put_contents($file, "from-file\r\n");

        try {
            $this->setEnvironment(self::SECRET, 'from-environment');
            $this->setEnvironment(self::SECRET_FILE, $file);

            SecretFileLoader::load();

            self::assertSame('from-file', getenv(self::SECRET));
            self::assertSame('from-file', $_ENV[self::SECRET]);
            self::assertSame('from-file', $_SERVER[self::SECRET]);
        } finally {
            unlink($file);
        }
    }

    public function testEmptyFileVariableKeepsEnvironmentFallback(): void
    {
        $this->setEnvironment(self::SECRET, 'from-environment');
        $this->setEnvironment(self::SECRET_FILE, '');

        SecretFileLoader::load();

        self::assertSame('from-environment', getenv(self::SECRET));
    }

    public function testEmptySecretFileFailsLoading(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'quickquiz-secret-');
        self::assertNotFalse($file);

        try {
            $this->setEnvironment(self::SECRET_FILE, $file);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('secret file is empty');

            SecretFileLoader::load();
        } finally {
            unlink($file);
        }
    }

    public function testMissingSecretFileFailsLoading(): void
    {
        $this->setEnvironment(self::SECRET_FILE, sys_get_temp_dir().'/quickquiz-missing-secret');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(self::SECRET_FILE);

        SecretFileLoader::load();
    }

    private function setEnvironment(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }
}
