<?php

declare(strict_types=1);

namespace WooCommerceBundle\Service;

use App\Entity\PriceList;
use App\Service\WarehouseFulfillmentRegionService;
use WooCommerceBundle\Entity\WooCommerceConnection;

/** The price list a connection's sync checks against: what the admin picked, or the region's guest list if they didn't. */
final class WooCommerceConnectionPriceListResolver
{
    public function __construct(private readonly WarehouseFulfillmentRegionService $warehouseRegions)
    {
    }

    public function resolve(WooCommerceConnection $connection): ?PriceList
    {
        if ($connection->getDefaultPriceList() instanceof PriceList) {
            return $connection->getDefaultPriceList();
        }

        $region = $this->warehouseRegions->regionForWarehouse($connection->getDefaultWarehouse());

        return $region?->getGuestPriceList();
    }
}
