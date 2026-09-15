<?php

namespace App\Tests\Security;

use App\Exception\AdminApiException;
use App\Security\AdminApiTokenAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdminApiTokenAuthenticatorTest extends TestCase
{
    #[DataProvider('disabledTokens')]
    public function testFailsClosedWhenConfiguredTokenIsMissingOrTooShort(string $configured): void
    {
        $authenticator = new AdminApiTokenAuthenticator($configured);

        try {
            $authenticator->assertAuthorized($this->request('Bearer '.str_repeat('a', 32)));
            self::fail('Expected disabled administration API.');
        } catch (AdminApiException $error) {
            self::assertSame(503, $error->status);
            self::assertSame('admin_api_disabled', $error->errorCode);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function disabledTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => [str_repeat('a', 31)];
    }

    #[DataProvider('invalidAuthorizationHeaders')]
    public function testRejectsMissingMalformedOrIncorrectCredentials(?string $authorization): void
    {
        $authenticator = new AdminApiTokenAuthenticator(str_repeat('a', 32));

        try {
            $authenticator->assertAuthorized($this->request($authorization));
            self::fail('Expected unauthorized request.');
        } catch (AdminApiException $error) {
            self::assertSame(401, $error->status);
            self::assertSame('unauthorized', $error->errorCode);
        }
    }

    /** @return iterable<string,array{?string}> */
    public static function invalidAuthorizationHeaders(): iterable
    {
        yield 'missing' => [null];
        yield 'query-style value' => [str_repeat('a', 32)];
        yield 'basic' => ['Basic '.str_repeat('a', 32)];
        yield 'wrong bearer' => ['Bearer '.str_repeat('b', 32)];
        yield 'bearer with spaces' => ['Bearer token with spaces'];
    }

    public function testAcceptsExactBearerToken(): void
    {
        $token = str_repeat('a', 32);
        $authenticator = new AdminApiTokenAuthenticator($token);

        $authenticator->assertAuthorized($this->request('Bearer '.$token));

        self::assertTrue(true);
    }

    private function request(?string $authorization): Request
    {
        $request = Request::create('/api/admin/quiz/catalog');
        if ($authorization !== null) {
            $request->headers->set('Authorization', $authorization);
        }

        return $request;
    }
}
