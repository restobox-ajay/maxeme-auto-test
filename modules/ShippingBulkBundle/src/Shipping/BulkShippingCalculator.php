<?php

declare(strict_types=1);

namespace ShippingBulkBundle\Shipping;

use App\Contract\Shipping\ShippingOption;
use App\Contract\Shipping\ShippingOptionInterface;
use App\Entity\AbstractSalesDocument;

final class BulkShippingCalculator implements ShippingOptionInterface
{
    public const RATES = [
        'BC' => 250.0,
        'AB' => 500.0,
    ];
    public const RATE_DEFAULT = 1000.0;
    public const DELIVERY_DAYS = 14;

    public function supports(AbstractSalesDocument $document): bool
    {
        foreach ($document->getCartItems() as ['product' => $product, 'qty' => $qty]) {
            if ($qty > 0 && $product->getShippingClass() === 'bulk') {
                return true;
            }
        }

        return false;
    }

    public function getOptions(AbstractSalesDocument $document): array
    {
        // getProvince() answers a normalised code, whatever the address row happens to hold — a
        // legacy display name reaching this lookup would match no rate and silently bill the
        // catch-all one.
        $amount = self::RATES[$document->getProvince()] ?? self::RATE_DEFAULT;

        return [
            new ShippingOption(
                id:           'bulk-delivery',
                label:        'Bulk Delivery',
                description:  'Bulk delivery via LTL carrier to your business location.',
                amount:       $amount,
                deliveryDays: self::DELIVERY_DAYS,
                taxClass:     $document->getHighestTaxClass(),
            ),
        ];
    }
}
