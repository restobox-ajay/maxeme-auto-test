<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\EventSubscriber\InactiveAccountLogoutSubscriber;
use App\Security\AccountStatusResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\PutsRawEntityStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\UserInterface;

final class InactiveAccountLogoutSubscriberTest extends TestCase
{
    use PutsRawEntityStatus;

    private TokenStorage $tokenStorage;
    private InactiveAccountLogoutSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->tokenStorage = new TokenStorage();

        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $route): string => '/generated/' . $route,
        );

        $this->subscriber = new InactiveAccountLogoutSubscriber(
            $this->tokenStorage,
            new AccountStatusResolver(),
            $router,
        );
    }

    private function event(Request $request, bool $isMainRequest = true): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new RequestEvent(
            $kernel,
            $request,
            $isMainRequest ? KernelInterface::MAIN_REQUEST : KernelInterface::SUB_REQUEST,
        );
    }

    private function tokenFor(UserInterface $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    public function testIgnoresSubRequests(): void
    {
        $admin = $this->putRawStatus(new AdminUser(), 'Disabled');
        $this->tokenStorage->setToken($this->tokenFor($admin));

        $event = $this->event(Request::create('/admin/orders'), isMainRequest: false);
        $this->subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertNotNull($this->tokenStorage->getToken());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ignoredPathProvider(): iterable
    {
        yield 'profiler' => ['/_profiler/abc'];
        yield 'wdt' => ['/_wdt/abc'];
        yield 'assets' => ['/assets/app.css'];
        yield 'build' => ['/build/app.js'];
    }

    #[DataProvider('ignoredPathProvider')]
    public function testIgnoresConfiguredPathPrefixesEvenForBlockedUser(string $path): void
    {
        $admin = $this->putRawStatus(new AdminUser(), 'Disabled');
        $this->tokenStorage->setToken($this->tokenFor($admin));

        $event = $this->event(Request::create($path));
        $this->subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertNotNull($this->tokenStorage->getToken());
    }

    public function testNoOpWhenNoTokenPresent(): void
    {
        $event = $this->event(Request::create('/admin/orders'));
        $this->subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testNoOpWhenUserIsFullyActive(): void
    {
        $company = (new Company());
        $customer = (new CustomerUser())->setCompany($company);
        $this->tokenStorage->setToken($this->tokenFor($customer));

        $event = $this->event(Request::create('/account'));
        $this->subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertNotNull($this->tokenStorage->getToken());
    }

    public function testBlockedAdminIsLoggedOutAndRedirectedToAdminLogin(): void
    {
        $admin = $this->putRawStatus(new AdminUser(), 'Disabled');
        $this->tokenStorage->setToken($this->tokenFor($admin));

        $event = $this->event(Request::create('/admin/orders'));
        $this->subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/generated/admin_login', $response->getTargetUrl());
        self::assertNull($this->tokenStorage->getToken());

        $cookieNames = array_map(
            static fn ($cookie) => $cookie->getName() . ':' . $cookie->getPath(),
            $response->headers->getCookies(),
        );
        self::assertContains('REMEMBERME:/admin', $cookieNames);
        self::assertContains('remember_me:/admin', $cookieNames);
    }

    public function testBlockedCustomerIsLoggedOutAndRedirectedToCustomerLogin(): void
    {
        $company = $this->putRawStatus(new Company(), 'Suspended');
        $customer = (new CustomerUser())->setCompany($company);
        $this->tokenStorage->setToken($this->tokenFor($customer));

        $event = $this->event(Request::create('/account'));
        $this->subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/generated/customer_login', $response->getTargetUrl());

        $cookieNames = array_map(
            static fn ($cookie) => $cookie->getName() . ':' . $cookie->getPath(),
            $response->headers->getCookies(),
        );
        self::assertContains('REMEMBERME:/', $cookieNames);
        self::assertContains('remember_me:/', $cookieNames);
    }

    public function testBlockedUserWithStartedSessionHasSessionInvalidated(): void
    {
        $admin = $this->putRawStatus(new AdminUser(), 'Disabled');
        $this->tokenStorage->setToken($this->tokenFor($admin));

        $request = Request::create('/admin/orders');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set('foo', 'bar');
        $request->setSession($session);

        $event = $this->event($request);
        $this->subscriber->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertFalse($session->has('foo'));
    }

    public function testBlockedUserExpectingJsonGetsJsonResponse(): void
    {
        $admin = $this->putRawStatus(new AdminUser(), 'Disabled');
        $this->tokenStorage->setToken($this->tokenFor($admin));

        $request = Request::create('/admin/orders');
        $request->headers->set('Accept', 'application/json');

        $event = $this->event($request);
        $this->subscriber->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);
        self::assertFalse($payload['ok']);
        self::assertSame('Your admin account is disabled.', $payload['message']);
        self::assertSame('/generated/admin_login', $payload['redirect']);
    }

    public function testBlockedUserViaXmlHttpRequestGetsJsonResponse(): void
    {
        $company = (new Company());
        $customer = $this->putRawStatus((new CustomerUser())->setCompany($company), 'Disabled');
        $this->tokenStorage->setToken($this->tokenFor($customer));

        $request = Request::create('/account');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $event = $this->event($request);
        $this->subscriber->onKernelRequest($event);

        self::assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    public function testGetSubscribedEventsRegistersLateRequestListener(): void
    {
        $events = InactiveAccountLogoutSubscriber::getSubscribedEvents();

        self::assertSame(['onKernelRequest', -10], $events['kernel.request']);
    }
}
