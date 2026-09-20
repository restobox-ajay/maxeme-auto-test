<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final class AdminHostSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $adminHost,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Run early, before controllers are resolved.
            KernelEvents::REQUEST => ['onKernelRequest', 100],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Don't interfere with sub-requests.
        if (!$event->isMainRequest()) {
            return;
        }

        $host = strtolower($request->getHost());
        $path = $request->getPathInfo();

        if ($this->isAdminHost($host) && ($path === '' || $path === '/')) {
            $event->setResponse(new RedirectResponse('/admin'));
            return;
        }

        if ($this->isAdminPath($path) && !$this->isAdminHost($host)) {
            // Hide admin routes entirely from the customer domain.
            throw new NotFoundHttpException();
        }
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

        // Defensive fallback: treat any "admin." host as admin if env config is missing/mis-set.
        return str_starts_with($host, 'admin.');
    }
}
