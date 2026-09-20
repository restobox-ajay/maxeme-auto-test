<?php

declare(strict_types=1);

namespace ShippingBulkBundle\Menu;

use App\Contract\Shipping\ShippingMenuItemInterface;

final class BulkShippingMenuItem implements ShippingMenuItemInterface
{
    public function getLabel(): string { return 'Bulk Delivery'; }
    public function getRoute(): string { return 'admin_bundle_shipping_bulk'; }
}
