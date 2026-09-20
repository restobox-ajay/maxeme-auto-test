<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\CsrfProtectionSubscriber;
use App\Security\Csrf\Attribute\CsrfExempt;
use App\Security\Csrf\Csrf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Controller doubles. The attribute must sit on real methods/classes because the subscriber
 * resolves it by reflection, exactly as in production.
 */
final class CsrfTestController
{
    public function mutate(): Response
    {
        return new Response('mutated');
    }

    #[CsrfExempt(reason: 'test: method-level exemption')]
    public function exemptMethod(): Response
    {
        return new Response('exempt');
    }
}

#[CsrfExempt(reason: 'test: class-level exemption')]
final class CsrfExemptTestController
{
    public function mutate(): Response
    {
        return new Response('exempt');
    }
}

final class CsrfProtectionSubscriberTest extends TestCase
{
    private const VALID = 'valid-token';

    private function subscriber(bool $stateless = false): CsrfProtectionSubscriber
    {
        $manager = $this->createStub(CsrfTokenManagerInterface::class);
        $manager->method('isTokenValid')->willReturnCallback(
            static fn ($token) => $token->getId() === Csrf::ID && $token->getValue() === self::VALID
        );

        $map = $this->createStub(FirewallMap::class);
        // FirewallConfig::__construct(name, userChecker, requestMatcher, securityEnabled, stateless, ...)
        $map->method('getFirewallConfig')->willReturn(new FirewallConfig('t', 't', null, true, $stateless));

        return new CsrfProtectionSubscriber(new Csrf($manager), $map);
    }

    /** @param array{0: object, 1: string} $controller */
    private function event(Request $request, array $controller, bool $main = true): ControllerEvent
    {
        return new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            $controller,
            $request,
            $main ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST,
        );
    }

    /** The subscriber rejects by swapping the controller for one returning a 403. */
    private function ran(ControllerEvent $event): bool
    {
        return is_array($event->getController());
    }

    private function response(ControllerEvent $event): Response
    {
        return ($event->getController())();
    }

    private function post(string $uri = '/admin/thing', array $params = []): Request
    {
        return Request::create($uri, 'POST', $params);
    }

    // ── Blocking ────────────────────────────────────────────────────────────

    public function testBlocksPostWithNoToken(): void
    {
        $event = $this->event($this->post(), [new CsrfTestController(), 'mutate']);

        $this->subscriber()->onKernelController($event);

        self::assertFalse($this->ran($event));
    }

    public function testBlocksPostWithWrongToken(): void
    {
        $event = $this->event($this->post(params: ['_token' => 'nope']), [new CsrfTestController(), 'mutate']);

        $this->subscriber()->onKernelController($event);

        self::assertFalse($this->ran($event));
    }

    public static function unsafeMethods(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('unsafeMethods')]
    public function testBlocksEveryUnsafeMethod(string $method): void
    {
        $event = $this->event(Request::create('/admin/thing', $method), [new CsrfTestController(), 'mutate']);

        $this->subscriber()->onKernelController($event);

        self::assertFalse($this->ran($event), $method . ' should be gated');
    }

    // ── Allowing ────────────────────────────────────────────────────────────

    public function testAllowsValidTokenInFormField(): void
    {
        $event = $this->event($this->post(params: [Csrf::FIELD => self::VALID]), [new CsrfTestController(), 'mutate']);

        $this->subscriber()->onKernelController($event);

        self::assertTrue($this->ran($event));
    }

    /** The header path exists for JS-only endpoints, which have no form field to carry a token. */
    public function testAllowsValidTokenInHeader(): void
    {
        $request = $this->post();
        $request->headers->set(Csrf::HEADER, self::VALID);
        $event = $this->event($request, [new CsrfTestController(), 'mutate']);

        $this->subscriber()->onKernelController($event);

        self::assertTrue($this->ran($event));
    }

    public static function safeMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
        yield 'OPTIONS' => ['OPTIONS'];
    }

    #[DataProvider('safeMethods')]
    public function testIgnoresSafeMethods(string $method): void
    {
        $event = $this->event(Request::create('/admin/thing', $method), [new CsrfTestController(), 'mutate']);

        $this->subscriber()->onKernelController($event);

        self::assertTrue($this->ran($event));
    }

    public function testIgnoresSubRequests(): void
    {
        $event = $this->event($this->post(), [new CsrfTestController(), 'mutate'], false);

        $this->subscriber()->onKernelController($event);

        self::assertTrue($this->ran($event));
    }

    // ── Exemptions ──────────────────────────────────────────────────────────

    /** A stateless firewall has no ambient cookie credential to abuse, so CSRF cannot apply. */
    public function testAllowsUntokenedPostOnStatelessFirewall(): void
    {
        $event = $this->event($this->post('/api/v1/orders'), [new CsrfTestController(), 'mutate']);

        $this->subscriber(stateless: true)->onKernelController($event);

        self::assertTrue($this->ran($event), 'API/stateless firewalls must not require a token');
    }

    public function testRespectsMethodLevelExemption(): void
    {
        $event = $this->event($this->post(), [new CsrfTestController(), 'exemptMethod']);

        $this->subscriber()->onKernelController($event);

        self::assertTrue($this->ran($event));
    }

    public function testRespectsClassLevelExemption(): void
    {
        $event = $this->event($this->post('/webhook/stripe'), [new CsrfExemptTestController(), 'mutate']);

        $this->subscriber()->onKernelController($event);

        self::assertTrue($this->ran($event));
    }

    // ── Rejection shape ─────────────────────────────────────────────────────

    public function testRejectionIsJsonForXmlHttpRequest(): void
    {
        $request = $this->post();
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $event = $this->event($request, [new CsrfTestController(), 'mutate']);

        $this->subscriber()->onKernelController($event);

        $response = $this->response($event);
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);
        // The admin JS branches on `success` or `ok`; both must read as failure.
        self::assertFalse($payload['success']);
        self::assertFalse($payload['ok']);
    }

    /**
     * A bare fetch() sends a wildcard Accept and no X-Requested-With. It must get JSON, not a
     * redirect: fetch() follows a 302 transparently and would hand the caller a 200 HTML page, so
     * the admin's inline grid handlers would read `success` off HTML, see undefined, and fail
     * silently while discarding the edit.
     */
    public function testRejectionIsJsonForBareFetch(): void
    {
        $request = $this->post();
        $request->headers->set('Accept', '*/*');
        $event = $this->event($request, [new CsrfTestController(), 'mutate']);

        $this->subscriber()->onKernelController($event);

        self::assertInstanceOf(JsonResponse::class, $this->response($event));
    }

    /** A real browser form POST asks for HTML, so it gets the friendly redirect instead. */
    public function testRejectionRedirectsBackForBrowserFormPost(): void
    {
        $request = Request::create('http://admin.example.com/admin/company/1/update', 'POST');
        $request->headers->set('Accept', 'text/html,application/xhtml+xml');
        $request->headers->set('referer', 'http://admin.example.com/admin/company/1/edit');
        $event = $this->event($request, [new CsrfTestController(), 'mutate']);

        $this->subscriber()->onKernelController($event);

        $response = $this->response($event);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('http://admin.example.com/admin/company/1/edit', $response->getTargetUrl());
    }

    /** Never bounce a rejected request onward to an attacker-supplied Referer. */
    public function testRejectionIgnoresOffSiteReferer(): void
    {
        $request = Request::create('http://admin.example.com/admin/company/1/update', 'POST');
        $request->headers->set('Accept', 'text/html');
        $request->headers->set('referer', 'https://evil.example/hook');
        $event = $this->event($request, [new CsrfTestController(), 'mutate']);

        $this->subscriber()->onKernelController($event);

        self::assertSame('/', $this->response($event)->getTargetUrl());
    }
}
