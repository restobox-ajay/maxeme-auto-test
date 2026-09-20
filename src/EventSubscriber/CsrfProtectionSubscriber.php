<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Csrf\Attribute\CsrfExempt;
use App\Security\Csrf\Csrf;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * THE verification point for CSRF. Rejects state-changing requests without a valid token.
 *
 * Runs on kernel.controller rather than kernel.request so controller attributes are resolvable and
 * the firewall has already run.
 *
 * Deny-by-default: every state-changing request needs a valid token unless explicitly exempted.
 */
final class CsrfProtectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Csrf $csrf,
        private readonly FirewallMap $firewallMap,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // GET/HEAD/OPTIONS/TRACE do not change state.
        if ($request->isMethodSafe()) {
            return;
        }

        // API / stateless firewalls are exempt on principle, not by URL convention. CSRF is only a
        // threat where the browser attaches credentials ambiently; a stateless firewall
        // (security.yaml -> firewalls.api) authenticates from an explicit per-request credential
        // such as an API key header, which an attacker's page cannot make the browser send. Keying
        // off `stateless: true` means any future API firewall is covered automatically, while an
        // API mounted on a session firewall correctly stays protected.
        if ($this->firewallMap->getFirewallConfig($request)?->isStateless() ?? false) {
            return;
        }

        if ($this->isExempt($event)) {
            return;
        }

        if ($this->csrf->isValidRequest($request)) {
            return;
        }

        $response = $this->rejection($request);
        $event->setController(static fn (): Response => $response);
    }

    private function isExempt(ControllerEvent $event): bool
    {
        if ($event->getAttributes(CsrfExempt::class) !== []) {
            return true;
        }

        $controller = $event->getController();
        $object = is_array($controller) ? ($controller[0] ?? null) : $controller;

        return is_object($object)
            && !$object instanceof \Closure
            && (new \ReflectionClass($object))->getAttributes(CsrfExempt::class) !== [];
    }

    /**
     * A browser form POST gets flash-and-redirect, matching what the app's hand-written CSRF
     * failures already do. Sessions last 8h while remember_me lasts 14 days, so a form left open
     * past its session is submitted by someone silently re-authenticated into a new session with no
     * matching token; a bare 403 would dead-end a still-authenticated admin and bin what they typed.
     *
     * Anything not asking for HTML is a programmatic caller (fetch, curl, an API client) and gets
     * JSON. Redirecting those is actively harmful: fetch() follows a 302 transparently and hands
     * back a 200 HTML page, so the admin's inline grid handlers would read `success` off an HTML
     * document, see undefined, and fail silently while discarding the edit.
     *
     * What this must never do is end the session. Refusing the REQUEST is the whole job; the caller
     * stays exactly as authenticated as they were. Nothing here touches TokenStorageInterface,
     * invalidates or migrates the session, or clears a cookie, and CsrfRejectionKeepsTheSessionCest
     * pins that — a bare 403 assertion would pass just as happily on a request that had also been
     * logged out.
     *
     * Worth stating because the opposite gets reported (#449). Tokens are session-backed
     * (framework.yaml pins SessionTokenStorage), so session lifetime IS token lifetime and "stale
     * token" and "expired session" are the same event. An admin whose 8h session lapsed while a form
     * sat open is bounced to /admin/login with their session cookie cleared — but by the firewall,
     * at kernel.request, before this subscriber on kernel.controller ever looks at a token. The
     * cookie clearing is AbstractSessionListener's: ExceptionListener skips setTargetPath() for
     * non-safe methods, so a rejected POST leaves the new session empty, and an empty session whose
     * cookie was sent gets cleared. None of that is reachable from here.
     */
    private function rejection(Request $request): Response
    {
        $message = 'Your session expired or the form was stale. Please reload the page and try again.';

        if ($this->expectsJson($request)) {
            return new JsonResponse([
                'success' => false,
                'ok' => false,
                'message' => $message,
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', $message);
            }
        } catch (SessionNotFoundException) {
            // No session to flash into; the redirect below still keeps the caller out.
        }

        return new RedirectResponse($this->safeReturnUrl($request));
    }

    /** Never bounce a rejected request onward to an attacker-supplied Referer. */
    private function safeReturnUrl(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');

        return $referer !== '' && str_starts_with($referer, $request->getSchemeAndHttpHost() . '/')
            ? $referer
            : '/';
    }

    private function expectsJson(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json')
            || ($accept !== '' && !str_contains($accept, 'text/html'));
    }
}
