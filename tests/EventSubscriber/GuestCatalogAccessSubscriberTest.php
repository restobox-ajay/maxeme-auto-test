<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\AppSetting;
use App\Entity\CustomerUser;
use App\EventSubscriber\GuestCatalogAccessSubscriber;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class GuestCatalogAccessSubscriberTest extends TestCase
{
    /** @param list<AppSetting> $rows */
    private function appSettings(array $rows): AppSettings
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($rows);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new AppSettings($em, new ArrayAdapter());
    }

    private function makeSetting(string $key, ?string $value): AppSetting
    {
        return (new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($value);
    }

    private function security(?CustomerUser $user): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/customer/login');

        return $urlGenerator;
    }

    private function event(?string $route, bool $isMainRequest = true): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('https://shop.example.com/whatever');
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        return new RequestEvent(
            $kernel,
            $request,
            $isMainRequest ? KernelInterface::MAIN_REQUEST : KernelInterface::SUB_REQUEST,
        );
    }

    public function testAllowsGuestByDefaultWhenSettingMissing(): void
    {
        $subscriber = new GuestCatalogAccessSubscriber(
            $this->appSettings([]),
            $this->security(null),
            $this->urlGenerator(),
        );

        $event = $this->event('customer_catalog');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testAllowsGuestWhenSettingExplicitlyYes(): void
    {
        $subscriber = new GuestCatalogAccessSubscriber(
            $this->appSettings([$this->makeSetting('guest_catalog_visible', 'Yes')]),
            $this->security(null),
            $this->urlGenerator(),
        );

        $event = $this->event('customer_product_detail');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testRedirectsGuestToLoginWhenSettingIsNoAndNoDefaultUrlConfigured(): void
    {
        $subscriber = new GuestCatalogAccessSubscriber(
            $this->appSettings([$this->makeSetting('guest_catalog_visible', 'No')]),
            $this->security(null),
            $this->urlGenerator(),
        );

        $event = $this->event('customer_catalog');
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/customer/login', $response->getTargetUrl());
    }

    public function testRedirectsGuestToConfiguredDefaultUrlWhenSettingIsNo(): void
    {
        $subscriber = new GuestCatalogAccessSubscriber(
            $this->appSettings([
                $this->makeSetting('guest_catalog_visible', 'No'),
                $this->makeSetting('guest_catalog_default_url', '/welcome'),
            ]),
            $this->security(null),
            $this->urlGenerator(),
        );

        $event = $this->event('customer_product_detail');
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/welcome', $response->getTargetUrl());
    }

    public function testTreatsBlankDefaultUrlAsUnconfiguredAndFallsBackToLogin(): void
    {
        $subscriber = new GuestCatalogAccessSubscriber(
            $this->appSettings([
                $this->makeSetting('guest_catalog_visible', 'No'),
                $this->makeSetting('guest_catalog_default_url', '   '),
            ]),
            $this->security(null),
            $this->urlGenerator(),
        );

        $event = $this->event('customer_catalog');
        $subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/customer/login', $response->getTargetUrl());
    }

    public function testAllowsLoggedInCustomerEvenWhenSettingIsNo(): void
    {
        $subscriber = new GuestCatalogAccessSubscriber(
            $this->appSettings([$this->makeSetting('guest_catalog_visible', 'No')]),
            $this->security(new CustomerUser()),
            $this->urlGenerator(),
        );

        $event = $this->event('customer_catalog');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresNonGatedRoutes(): void
    {
        $subscriber = new GuestCatalogAccessSubscriber(
            $this->appSettings([$this->makeSetting('guest_catalog_visible', 'No')]),
            $this->security(null),
            $this->urlGenerator(),
        );

        $event = $this->event('some_other_route');
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresRequestsWithNoRoute(): void
    {
        $subscriber = new GuestCatalogAccessSubscriber(
            $this->appSettings([$this->makeSetting('guest_catalog_visible', 'No')]),
            $this->security(null),
            $this->urlGenerator(),
        );

        $event = $this->event(null);
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresSubRequests(): void
    {
        $subscriber = new GuestCatalogAccessSubscriber(
            $this->appSettings([$this->makeSetting('guest_catalog_visible', 'No')]),
            $this->security(null),
            $this->urlGenerator(),
        );

        $event = $this->event('customer_catalog', isMainRequest: false);
        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testGetSubscribedEventsRegistersRequestListener(): void
    {
        $events = GuestCatalogAccessSubscriber::getSubscribedEvents();

        self::assertSame('onKernelRequest', $events['kernel.request']);
    }
}
