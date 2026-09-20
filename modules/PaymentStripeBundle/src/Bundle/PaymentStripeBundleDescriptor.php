<?php

declare(strict_types=1);

namespace PaymentStripeBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class PaymentStripeBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Stripe';
    }

    public function getType(): string
    {
        return 'Payment';
    }

    public function getSource(): string
    {
        return 'PaymentStripeBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_payment_stripe';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/PaymentStripeBundle/README.md';
    }
}
