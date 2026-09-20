<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Forces http:// -> https:// in prod only. Local dev serves plain http:// (Caddy has no
 * cert for the .localhost domains there), and the functional/Codeception test suite boots
 * the kernel directly over http as well — gating on kernel.environment rather than the
 * request's own scheme keeps both of those working unchanged.
 */
final class ForceHttpsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $environment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Ahead of AdminHostSubscriber (100) and RedirectSubscriber (90): a plain-http
            // request should be sent straight to https rather than processed any further.
            KernelEvents::REQUEST => ['onKernelRequest', 110],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->environment !== 'prod' || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->isSecure()) {
            return;
        }

        $url = 'https://' . $request->getHost() . $request->getRequestUri();

        // 308, not 301/302: preserves the original method and body, so a form POSTed to a
        // stale http:// link (bookmark, old link, typo) still submits instead of silently
        // turning into a GET.
        $event->setResponse(new RedirectResponse($url, 308));
    }
}
