<?php

declare(strict_types=1);

namespace App\Maxeme\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The core platform's pages and the active modules' pages that the Maxeme mockup does not have
 * answer "Page not found" (maxeme.hidden_admin_pages). Their menus were already hidden; this also
 * closes them to a typed or old URL. Nothing is deleted: removing a path from the list opens the
 * page again.
 *
 *   prefixes: the path and everything under it ("/admin/order" also closes "/admin/order/create",
 *             not "/admin/orders")
 *   exact:    that path only (a module's hub page, whose sub-pages the shop uses)
 *   except:   paths under a prefix that stay open (the vendor pages under Procurement)
 */
final class HiddenAdminPageSubscriber implements EventSubscriberInterface
{
    /**
     * @param array{prefixes?: list<string>, exact?: list<string>, except?: list<string>} $hidden
     */
    public function __construct(
        #[Autowire(param: 'maxeme.hidden_admin_pages')]
        private readonly array $hidden,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Before the router and the firewall: a hidden page is "not found" signed in or not.
        return [KernelEvents::REQUEST => ['onKernelRequest', 40]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = rtrim($event->getRequest()->getPathInfo(), '/');
        if (self::isHidden($path, $this->hidden)) {
            throw new NotFoundHttpException(sprintf('No page at "%s".', $path));
        }
    }

    /**
     * @param array{prefixes?: list<string>, exact?: list<string>, except?: list<string>} $hidden
     */
    public static function isHidden(string $path, array $hidden): bool
    {
        foreach ($hidden['except'] ?? [] as $open) {
            if (self::under($path, $open)) {
                return false;
            }
        }
        if (in_array($path, $hidden['exact'] ?? [], true)) {
            return true;
        }
        foreach ($hidden['prefixes'] ?? [] as $prefix) {
            if (self::under($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function under(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }
}
