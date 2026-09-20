<?php

declare(strict_types=1);

namespace CartHoldBundle\EventSubscriber;

use App\Event\CartSyncedEvent;
use CartHoldBundle\Service\CartHoldService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reacts to CartSyncedEvent (dispatched from AbstractCustomerController::syncCartHold() on
 * every cart mutation and cart/checkout view) by syncing hold rows to match. This is the
 * decoupling point that lets core have zero compile-time reference to this bundle: core only
 * dispatches a plain event it defines itself, and this bundle is the only thing that knows the
 * event means anything — deleting this bundle just means the event goes unheard, cart keeps
 * working with no holds reserved.
 */
final class CartSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CartHoldService $cartHoldService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CartSyncedEvent::class => 'onCartSynced',
        ];
    }

    public function onCartSynced(CartSyncedEvent $event): void
    {
        $this->cartHoldService->syncForCurrentCart($event->cart, $event->region, $event->sessionId);
    }
}
