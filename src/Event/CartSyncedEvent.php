<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Cart;
use App\Entity\FulfillmentRegion;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched whenever a customer's cart is mutated or viewed (add/update/remove/clear, cart
 * page view, checkout page view), carrying whatever a hold-reservation feature needs to sync:
 * the current Cart (nullable — a brand new session may have none yet), the customer's
 * currently-resolved region (nullable — pricing/region resolution can fail to find one), and
 * the session id (always present, even with no Cart row, so a listener can still release any
 * stale holds for this session).
 *
 * This is the sanctioned decoupling point between core and CartHoldBundle: core only depends on
 * this plain event class and EventDispatcherInterface, never on the bundle's own classes, so
 * the bundle can be removed entirely without breaking compilation — it just stops reacting to
 * this event, cart itself keeps working with no holds reserved.
 */
final class CartSyncedEvent extends Event
{
    public function __construct(
        public readonly ?Cart $cart,
        public readonly ?FulfillmentRegion $region,
        public readonly string $sessionId,
    ) {
    }
}
