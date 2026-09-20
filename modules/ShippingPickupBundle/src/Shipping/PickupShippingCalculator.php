<?php

declare(strict_types=1);

namespace ShippingPickupBundle\Shipping;

use App\Contract\Shipping\ShippingOption;
use App\Contract\Shipping\ShippingOptionInterface;
use App\Entity\AbstractSalesDocument;

final class PickupShippingCalculator implements ShippingOptionInterface
{
    public function supports(AbstractSalesDocument $document): bool
    {
        return true;
    }

    public function getOptions(AbstractSalesDocument $document): array
    {
        return [
            new ShippingOption(
                id:           'pickup',
                label:        'Pickup',
                description:  'Pick up your order at our warehouse.',
                amount:       0.0,
                deliveryDays: null,
                taxClass:     $document->getHighestTaxClass(),
            ),
        ];
    }
}
