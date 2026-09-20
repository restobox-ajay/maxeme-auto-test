<?php

declare(strict_types=1);

namespace FeeCouponBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class FeeCouponBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Coupons';
    }

    public function getType(): string
    {
        return 'Fee';
    }

    public function getSource(): string
    {
        return 'FeeCouponBundle';
    }

    /** No config screen of its own — coupons are configured through the checkout_coupons setting. */
    public function getEditRoute(): ?string
    {
        return null;
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/FeeCouponBundle/README.md';
    }
}
