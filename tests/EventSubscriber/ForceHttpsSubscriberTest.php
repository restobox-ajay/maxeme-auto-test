<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ForceHttpsSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class ForceHttpsSubscriberTest extends TestCase
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

    public function testRedirectsPlainHttpToHttpsInProd(): void
    {
        $subscriber = new ForceHttpsSubscriber('prod');

        $event = $this->event('http://admin.example.com/admin/orders?page=2');
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://admin.example.com/admin/orders?page=2', $response->getTargetUrl());
        self::assertSame(308, $response->getStatusCode());
    }

    public function testRedirectsCustomerHostToo(): void
    {
        $subscriber = new ForceHttpsSubscriber('prod');

        $event = $this->event('http://shop.example.com/catalog');
        $subscriber->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    public function testDoesNotRedirectAlreadySecureRequestInProd(): void
    {
        $subscriber = new ForceHttpsSubscriber('prod');

        $event = $this->event('https://shop.example.com/catalog');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testDoesNotRedirectInDev(): void
    {
        $subscriber = new ForceHttpsSubscriber('dev');

        $event = $this->event('http://shop.example.com/catalog');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testDoesNotRedirectInTest(): void
    {
        $subscriber = new ForceHttpsSubscriber('test');

        $event = $this->event('http://shop.example.com/catalog');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresSubRequests(): void
    {
        $subscriber = new ForceHttpsSubscriber('prod');

        $event = $this->event('http://shop.example.com/catalog', isMainRequest: false);
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testGetSubscribedEventsRegistersAnEarlyRequestListener(): void
    {
        $events = ForceHttpsSubscriber::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 110], $events['kernel.request']);
    }
}
