<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #312's port of Customer\CheckoutController::resolveCheckoutCoupon()'s two hand-built error
 * strings: an unrecognised code, and a code whose minSubtotal the cart hasn't reached. The lookup
 * itself (matching the posted code against the store's configured coupons) stays in the
 * controller — resolveCheckoutCoupon() passes in whatever it found (or null) as the validated
 * value, with the code and cart subtotal carried as constraint options for the messages.
 */
#[\Attribute]
final class ValidCouponCode extends Constraint
{
    public function __construct(
        public readonly string $couponCode,
        public readonly float $subtotal,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
