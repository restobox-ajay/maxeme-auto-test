<?php

declare(strict_types=1);

namespace ShippingCanadaPostBundle\Shipping;

use App\Contract\Shipping\ShippingOption;
use App\Contract\Shipping\ShippingOptionInterface;
use App\Entity\AbstractSalesDocument;

final class CanadaPostShippingCalculator implements ShippingOptionInterface
{
    public const GROUND_BASE     = 10.0;
    public const GROUND_PER_UNIT = 2.5;
    public const GROUND_DAYS     = 5;
    public const EXPRESS_BASE     = 21.84;
    public const EXPRESS_PER_UNIT = 4.5;
    public const EXPRESS_DAYS     = 2;

    public function supports(AbstractSalesDocument $document): bool
    {
        $hasItems = false;
        foreach ($document->getCartItems() as ['product' => $product, 'qty' => $qty]) {
            if ($qty <= 0) {
                continue;
            }
            if ($product->getShippingClass() === 'bulk') {
                return false;
            }
            $hasItems = true;
        }

        return $hasItems;
    }

    public function getOptions(AbstractSalesDocument $document): array
    {
        $totalQty = 0;
        foreach ($document->getCartItems() as ['qty' => $qty]) {
            $totalQty += $qty;
        }

        $taxClass = $document->getHighestTaxClass();

        return [
            new ShippingOption(
                id:           'canada-post-ground',
                label:        'Canada Post - Ground',
                description:  'Standard delivery by Canada Post.',
                amount:       round(self::GROUND_BASE + self::GROUND_PER_UNIT * $totalQty, 2),
                deliveryDays: self::GROUND_DAYS,
                taxClass:     $taxClass,
            ),
            new ShippingOption(
                id:           'canada-post-express',
                label:        'Canada Post - Express',
                description:  'Express delivery by Canada Post.',
                amount:       round(self::EXPRESS_BASE + self::EXPRESS_PER_UNIT * $totalQty, 2),
                deliveryDays: self::EXPRESS_DAYS,
                taxClass:     $taxClass,
            ),
        ];
    }
}
