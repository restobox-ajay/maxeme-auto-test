<?php

declare(strict_types=1);

namespace CartHoldBundle\EventSubscriber;

use App\Service\CartService;
use CartHoldBundle\Service\CartHoldService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * There's no fast/frequent cron for cart hold — expired holds are instead swept lazily,
 * on page load, the moment any admin or customer reaches a page whose inventory numbers
 * would otherwise be stale. Runs at kernel.request default priority (like
 * GuestCatalogAccessSubscriber): the security firewall's own listener runs at a higher
 * priority than default on this event, so by the time this fires, authentication is already
 * resolved and it's still early enough (before the controller executes) that whatever the
 * page renders afterward reflects the swept state.
 */
final class CartHoldSweepSubscriber implements EventSubscriberInterface
{
    private const CUSTOMER_ROUTES = [
        'customer_catalog', 'customer_product_detail',
        'customer_cart', 'customer_cart_add', 'customer_cart_update', 'customer_cart_remove', 'customer_cart_clear',
        'customer_checkout', 'customer_checkout_submit', 'customer_checkout_set_address',
        'customer_checkout_set_billing_address', 'customer_checkout_set_shipping',
        'customer_checkout_set_payment_method', 'customer_checkout_set_coupon', 'customer_checkout_remove_coupon',
    ];

    private const ADMIN_ROUTES = [
        'admin_product_detail_index', 'admin_product_price_index',
        'admin_product_inventory_create', 'admin_product_inventory_update',
        'admin_inventory_index', 'admin_inventory_update',
    ];

    public function __construct(
        private readonly CartHoldService $cartHoldService,
        private readonly CartService $cartService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->cartHoldService->isEnabled()) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route');
        $isCustomerRoute = in_array($route, self::CUSTOMER_ROUTES, true);
        if (!$isCustomerRoute && !in_array($route, self::ADMIN_ROUTES, true)) {
            return;
        }

        $this->cartHoldService->releaseExpired();

        if (!$isCustomerRoute) {
            return;
        }

        $sessionId = $event->getRequest()->getSession()->getId();
        $evicted = $this->cartHoldService->reconcileExpiredForSession($this->cartService, $sessionId);
        if ($evicted !== []) {
            $event->getRequest()->getSession()->getFlashBag()->add(
                'warning',
                'Some items in your cart were released because the hold timer ran out. Please check your cart.'
            );
        }
    }
}
