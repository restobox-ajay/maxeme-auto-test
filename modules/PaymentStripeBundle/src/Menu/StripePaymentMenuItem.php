<?php

declare(strict_types=1);

namespace PaymentStripeBundle\Menu;

use App\Contract\Payment\PaymentMenuItemInterface;

final class StripePaymentMenuItem implements PaymentMenuItemInterface
{
    public function getLabel(): string
    {
        return 'Stripe';
    }

    public function getRoute(): string
    {
        return 'admin_bundle_payment_stripe';
    }
}
