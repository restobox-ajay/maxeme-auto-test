<?php

declare(strict_types=1);

namespace PaymentManualBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class PaymentManualBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Manual Payment Methods';
    }

    public function getType(): string
    {
        return 'Payment';
    }

    public function getSource(): string
    {
        return 'PaymentManualBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_payment_manual_index';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/PaymentManualBundle/README.md';
    }
}
