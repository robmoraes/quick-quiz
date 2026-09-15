<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\AdminApiAuthenticationSubscriber;
use App\Security\AdminApiTokenAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AdminApiAuthenticationSubscriberTest extends TestCase
{
    public function testIgnoresRequestsOutsideAdministrativeNamespace(): void
    {
        $event = $this->event(Request::create('/login'));
        $subscriber = new AdminApiAuthenticationSubscriber(new AdminApiTokenAuthenticator(''));

        $subscriber($event);

        self::assertFalse($event->hasResponse());
    }

    public function testFailsClosedWhenTokenIsNotConfigured(): void
    {
        $event = $this->event(Request::create('/api/admin/quiz/catalog'));
        $subscriber = new AdminApiAuthenticationSubscriber(new AdminApiTokenAuthenticator(''));

        $subscriber($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(503, $event->getResponse()?->getStatusCode());
        self::assertSame(
            'admin_api_disabled',
            json_decode((string) $event->getResponse()?->getContent(), true)['error']['code'],
        );
    }

    public function testRejectsInvalidBearerToken(): void
    {
        $request = Request::create('/api/admin/quiz/catalog');
        $request->headers->set('Authorization', 'Bearer '.str_repeat('b', 32));
        $event = $this->event($request);
        $subscriber = new AdminApiAuthenticationSubscriber(
            new AdminApiTokenAuthenticator(str_repeat('a', 32)),
        );

        $subscriber($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode());
        self::assertSame('Bearer', $event->getResponse()?->headers->get('WWW-Authenticate'));
    }

    public function testAllowsValidBearerToken(): void
    {
        $token = str_repeat('a', 32);
        $request = Request::create('/api/admin/quiz/catalog');
        $request->headers->set('Authorization', 'Bearer '.$token);
        $event = $this->event($request);
        $subscriber = new AdminApiAuthenticationSubscriber(new AdminApiTokenAuthenticator($token));

        $subscriber($event);

        self::assertFalse($event->hasResponse());
    }

    private function event(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
