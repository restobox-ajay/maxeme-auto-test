<?php

declare(strict_types=1);

namespace ShippingFreeBundle\Shipping;

use App\Contract\Shipping\ShippingOption;
use App\Contract\Shipping\ShippingOptionInterface;
use App\Entity\AbstractSalesDocument;

final class FreeShippingCalculator implements ShippingOptionInterface
{
    public function supports(AbstractSalesDocument $document): bool
    {
        return true;
    }

    public function getOptions(AbstractSalesDocument $document): array
    {
        return [
            new ShippingOption(
                id:           'free',
                label:        'Free Shipping',
                description:  '',
                amount:       0.0,
                deliveryDays: null,
                taxClass:     $document->getHighestTaxClass(),
            ),
        ];
    }
}
