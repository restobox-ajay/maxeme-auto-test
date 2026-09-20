<?php

namespace App\EventSubscriber;

use App\Entity\CustomerUser;
use App\Service\AppSettings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Gates guest (non-logged-in) access to the /product catalog routes behind the
 * guest_catalog_visible AppSetting. Independent of, and coarser than, Section 6.8's
 * per-region guestVisible/guestPriceList rules — this blocks the catalog entirely
 * regardless of any region's guest visibility.
 */
final class GuestCatalogAccessSubscriber implements EventSubscriberInterface
{
    private const GATED_ROUTES = ['customer_catalog', 'customer_product_detail'];

    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Default priority (0): must run after the security firewall's own listener
            // resolves the token, so getUser() below is reliable.
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!in_array($route, self::GATED_ROUTES, true)) {
            return;
        }

        if ($this->security->getUser() instanceof CustomerUser) {
            return;
        }

        if (($this->appSettings->get('guest_catalog_visible', 'Yes') ?? 'Yes') !== 'No') {
            return;
        }

        $target = trim((string) ($this->appSettings->get('guest_catalog_default_url', '') ?? ''));
        if ($target === '') {
            $target = $this->urlGenerator->generate('customer_login');
        }

        $event->setResponse(new RedirectResponse($target));
    }
}
