<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\ProductCategory;
use App\Entity\Redirect;
use App\EventSubscriber\RedirectSubscriber;
use App\Repository\RedirectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RedirectSubscriberTest extends TestCase
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

    private function subscriber(RedirectRepository $redirectRepo, ?UrlGeneratorInterface $urlGenerator = null): RedirectSubscriber
    {
        return new RedirectSubscriber(
            $redirectRepo,
            $urlGenerator ?? $this->createStub(UrlGeneratorInterface::class),
            'admin.example.com',
        );
    }

    private function urlRedirect(string $sourcePath, string $destinationUrl, int $type = 301, string $queryHandling = Redirect::QUERY_HANDLING_PASS): Redirect
    {
        return (new Redirect())
            ->setSourcePath($sourcePath)
            ->setDestinationType(Redirect::DESTINATION_TYPE_URL)
            ->setDestinationUrl($destinationUrl)
            ->setRedirectType($type)
            ->setQueryHandling($queryHandling);
    }

    public function testDoesNothingWhenNoRedirectMatchesSourcePath(): void
    {
        $redirectRepo = $this->createStub(RedirectRepository::class);
        $redirectRepo->method('findOneBySourcePath')->willReturn(null);

        $subscriber = $this->subscriber($redirectRepo);
        $event = $this->event('https://shop.example.com/no-match');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresSubRequests(): void
    {
        $redirectRepo = $this->createMock(RedirectRepository::class);
        $redirectRepo->expects(self::never())->method('findOneBySourcePath');

        $subscriber = $this->subscriber($redirectRepo);
        $event = $this->event('https://shop.example.com/old-page', isMainRequest: false);
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testSkipsOnAdminHost(): void
    {
        $redirectRepo = $this->createMock(RedirectRepository::class);
        $redirectRepo->expects(self::never())->method('findOneBySourcePath');

        $subscriber = $this->subscriber($redirectRepo);
        $event = $this->event('https://admin.example.com/old-page');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testSkipsOnAdminPath(): void
    {
        $redirectRepo = $this->createMock(RedirectRepository::class);
        $redirectRepo->expects(self::never())->method('findOneBySourcePath');

        $subscriber = $this->subscriber($redirectRepo);
        $event = $this->event('https://shop.example.com/admin/old-page');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testRedirectsOnExactPathMatchWithConfiguredStatusCode(): void
    {
        $redirect = $this->urlRedirect('/old-page', '/new-page', 302);
        $redirectRepo = $this->createStub(RedirectRepository::class);
        $redirectRepo->method('findOneBySourcePath')->willReturn($redirect);

        $subscriber = $this->subscriber($redirectRepo);
        $event = $this->event('https://shop.example.com/old-page');
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/new-page', $response->getTargetUrl());
        self::assertSame(302, $response->getStatusCode());
    }

    public function testPassThroughModeAppendsQueryStringToDestination(): void
    {
        $redirect = $this->urlRedirect('/old-page', '/new-page', 301, Redirect::QUERY_HANDLING_PASS);
        $redirectRepo = $this->createStub(RedirectRepository::class);
        $redirectRepo->method('findOneBySourcePath')->willReturn($redirect);

        $subscriber = $this->subscriber($redirectRepo);
        $event = $this->event('https://shop.example.com/old-page?utm_source=test');
        $subscriber->onKernelRequest($event);

        self::assertSame('/new-page?utm_source=test', $event->getResponse()->getTargetUrl());
    }

    public function testStripModeDropsQueryString(): void
    {
        $redirect = $this->urlRedirect('/old-page', '/new-page', 301, Redirect::QUERY_HANDLING_STRIP);
        $redirectRepo = $this->createStub(RedirectRepository::class);
        $redirectRepo->method('findOneBySourcePath')->willReturn($redirect);

        $subscriber = $this->subscriber($redirectRepo);
        $event = $this->event('https://shop.example.com/old-page?utm_source=test');
        $subscriber->onKernelRequest($event);

        self::assertSame('/new-page', $event->getResponse()->getTargetUrl());
    }

    public function testMatchModeSkipsRedirectWhenQueryStringIsPresent(): void
    {
        $redirect = $this->urlRedirect('/old-page', '/new-page', 301, Redirect::QUERY_HANDLING_MATCH);
        $redirectRepo = $this->createStub(RedirectRepository::class);
        $redirectRepo->method('findOneBySourcePath')->willReturn($redirect);

        $subscriber = $this->subscriber($redirectRepo);
        $event = $this->event('https://shop.example.com/old-page?utm_source=test');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testMatchModeRedirectsWhenNoQueryStringIsPresent(): void
    {
        $redirect = $this->urlRedirect('/old-page', '/new-page', 301, Redirect::QUERY_HANDLING_MATCH);
        $redirectRepo = $this->createStub(RedirectRepository::class);
        $redirectRepo->method('findOneBySourcePath')->willReturn($redirect);

        $subscriber = $this->subscriber($redirectRepo);
        $event = $this->event('https://shop.example.com/old-page');
        $subscriber->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    public function testResolvesCategoryDestinationViaUrlGenerator(): void
    {
        $category = (new ProductCategory())->setName('Tires');
        $reflection = new \ReflectionProperty(ProductCategory::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($category, 42);

        $redirect = (new Redirect())
            ->setSourcePath('/no1-tire')
            ->setDestinationType(Redirect::DESTINATION_TYPE_CATEGORY)
            ->setDestinationCategory($category)
            ->setRedirectType(301)
            ->setQueryHandling(Redirect::QUERY_HANDLING_PASS);

        $redirectRepo = $this->createStub(RedirectRepository::class);
        $redirectRepo->method('findOneBySourcePath')->willReturn($redirect);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('customer_catalog', ['ProductSearch' => ['category_id' => 42]])
            ->willReturn('/product/index?ProductSearch%5Bcategory_id%5D=42');

        $subscriber = $this->subscriber($redirectRepo, $urlGenerator);
        $event = $this->event('https://shop.example.com/no1-tire');
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/product/index?ProductSearch%5Bcategory_id%5D=42', $response->getTargetUrl());
    }

    public function testSkipsWhenCategoryDestinationHasNoCategoryAssigned(): void
    {
        $redirect = (new Redirect())
            ->setSourcePath('/dangling-category')
            ->setDestinationType(Redirect::DESTINATION_TYPE_CATEGORY)
            ->setRedirectType(301)
            ->setQueryHandling(Redirect::QUERY_HANDLING_PASS);

        $redirectRepo = $this->createStub(RedirectRepository::class);
        $redirectRepo->method('findOneBySourcePath')->willReturn($redirect);

        $subscriber = $this->subscriber($redirectRepo);
        $event = $this->event('https://shop.example.com/dangling-category');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testSkipsWhenResolvedDestinationEqualsRequestPath(): void
    {
        $redirect = $this->urlRedirect('/self-loop', '/self-loop');
        $redirectRepo = $this->createStub(RedirectRepository::class);
        $redirectRepo->method('findOneBySourcePath')->willReturn($redirect);

        $subscriber = $this->subscriber($redirectRepo);
        $event = $this->event('https://shop.example.com/self-loop');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testGetSubscribedEventsRegistersBelowAdminHostSubscriberPriority(): void
    {
        $events = RedirectSubscriber::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 90], $events['kernel.request']);
    }
}
