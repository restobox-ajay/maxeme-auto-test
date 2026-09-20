<?php

namespace App\EventSubscriber;

use App\Entity\ProductCategory;
use App\Entity\Redirect;
use App\Repository\RedirectRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Applies admin-configured URL redirects (see RedirectController / "Redirection" in Settings)
 * on the customer side of the app only — mirrors AdminHostSubscriber's admin host/path guard so a
 * redirect can never fire for an admin URL. Matching is exact-path only (no wildcards).
 */
final class RedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RedirectRepository $redirectRepo,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $adminHost,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Just below AdminHostSubscriber's priority 100, so its admin host/path handling
            // (redirect/404) always takes effect first. Still ahead of the router (default
            // priority ~32) so a legacy path with no matching route can be redirected instead
            // of falling through to a 404.
            KernelEvents::REQUEST => ['onKernelRequest', 90],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $host = strtolower($request->getHost());
        $path = $request->getPathInfo();

        if ($this->isAdminHost($host) || $this->isAdminPath($path)) {
            return;
        }

        $redirect = $this->redirectRepo->findOneBySourcePath($path);
        if (!$redirect instanceof Redirect) {
            return;
        }

        $target = $this->resolveDestination($redirect);
        if ($target === null || $target === $path) {
            return;
        }

        $target = $this->applyQueryHandling($redirect, $request, $target);
        if ($target === null) {
            return;
        }

        $event->setResponse(new RedirectResponse($target, $redirect->getRedirectType()));
    }

    private function resolveDestination(Redirect $redirect): ?string
    {
        if ($redirect->getDestinationType() === Redirect::DESTINATION_TYPE_CATEGORY) {
            $category = $redirect->getDestinationCategory();

            return $category instanceof ProductCategory
                ? $this->urlGenerator->generate('customer_catalog', ['ProductSearch' => ['category_id' => $category->getId()]])
                : null;
        }

        $url = $redirect->getDestinationUrl();

        return $url !== null && $url !== '' ? $url : null;
    }

    private function applyQueryHandling(Redirect $redirect, Request $request, string $target): ?string
    {
        $queryString = $request->getQueryString();

        if ($queryString === null || $queryString === '') {
            return $target;
        }

        return match ($redirect->getQueryHandling()) {
            // The source URL itself carries no query parameters (matching is path-only), so
            // "must match" is only satisfiable by a request with none either.
            Redirect::QUERY_HANDLING_MATCH => null,
            Redirect::QUERY_HANDLING_STRIP => $target,
            default => $target . (str_contains($target, '?') ? '&' : '?') . $queryString,
        };
    }

    private function isAdminPath(string $path): bool
    {
        return $path === '/admin' || str_starts_with($path, '/admin/');
    }

    private function isAdminHost(string $host): bool
    {
        $configured = strtolower(trim($this->adminHost));
        if ($configured !== '' && $host === $configured) {
            return true;
        }

        return str_starts_with($host, 'admin.');
    }
}
