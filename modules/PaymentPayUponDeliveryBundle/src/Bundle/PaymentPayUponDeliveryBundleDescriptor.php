<?php

declare(strict_types=1);

namespace PaymentPayUponDeliveryBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class PaymentPayUponDeliveryBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Pay Upon Delivery';
    }

    public function getType(): string
    {
        return 'Payment';
    }

    public function getSource(): string
    {
        return 'PaymentPayUponDeliveryBundle';
    }

    public function getEditRoute(): ?string
    {
        return null;
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/PaymentPayUponDeliveryBundle/README.md';
    }
}
