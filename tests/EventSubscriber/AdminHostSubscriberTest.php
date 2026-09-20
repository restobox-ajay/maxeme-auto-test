<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\AdminHostSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class AdminHostSubscriberTest extends TestCase
{
    private function event(string $uri, bool $isMainRequest = true): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create($uri, 'GET');

        return new RequestEvent(
            $kernel,
            $request,
            $isMainRequest ? KernelInterface::MAIN_REQUEST : KernelInterface::SUB_REQUEST,
        );
    }

    public function testRedirectsAdminHostRootToAdminPath(): void
    {
        $subscriber = new AdminHostSubscriber('admin.example.com');

        $event = $this->event('https://admin.example.com/');
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin', $response->getTargetUrl());
    }

    public function testRedirectsAdminHostEmptyPathToAdminPath(): void
    {
        $subscriber = new AdminHostSubscriber('admin.example.com');

        // Request::create() always normalizes an empty path to "/", so hit onKernelRequest
        // directly with a Request double reporting a blank path to cover the '' branch.
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('https://admin.example.com/');
        $request->server->set('REQUEST_URI', '');

        $event = new RequestEvent($kernel, $request, KernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    public function testDoesNotRedirectAdminHostNonRootPath(): void
    {
        $subscriber = new AdminHostSubscriber('admin.example.com');

        $event = $this->event('https://admin.example.com/admin/orders');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testHidesAdminPathOnCustomerHost(): void
    {
        $subscriber = new AdminHostSubscriber('admin.example.com');

        $this->expectException(NotFoundHttpException::class);

        $subscriber->onKernelRequest($this->event('https://shop.example.com/admin'));
    }

    public function testHidesNestedAdminPathOnCustomerHost(): void
    {
        $subscriber = new AdminHostSubscriber('admin.example.com');

        $this->expectException(NotFoundHttpException::class);

        $subscriber->onKernelRequest($this->event('https://shop.example.com/admin/orders'));
    }

    public function testAllowsNonAdminPathOnCustomerHost(): void
    {
        $subscriber = new AdminHostSubscriber('admin.example.com');

        $event = $this->event('https://shop.example.com/catalog');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testAllowsAdminPathOnConfiguredAdminHost(): void
    {
        $subscriber = new AdminHostSubscriber('admin.example.com');

        $event = $this->event('https://admin.example.com/admin/orders');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresSubRequests(): void
    {
        $subscriber = new AdminHostSubscriber('admin.example.com');

        // Would otherwise throw NotFoundHttpException as a main request.
        $event = $this->event('https://shop.example.com/admin', isMainRequest: false);
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testHostComparisonIsCaseInsensitive(): void
    {
        $subscriber = new AdminHostSubscriber('Admin.Example.COM');

        $event = $this->event('https://ADMIN.EXAMPLE.COM/');
        $subscriber->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    public function testDefensiveFallbackTreatsAdminDotPrefixedHostAsAdminWhenNoConfigMatches(): void
    {
        $subscriber = new AdminHostSubscriber('other-admin.example.com');

        $event = $this->event('https://admin.example.com/');
        $subscriber->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    public function testBlankConfiguredHostStillHidesAdminPathViaFallback(): void
    {
        $subscriber = new AdminHostSubscriber('   ');

        $this->expectException(NotFoundHttpException::class);

        $subscriber->onKernelRequest($this->event('https://shop.example.com/admin'));
    }

    public function testGetSubscribedEventsRegistersEarlyRequestListener(): void
    {
        $events = AdminHostSubscriber::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 100], $events['kernel.request']);
    }
}
