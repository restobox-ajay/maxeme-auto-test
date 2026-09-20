<?php

declare(strict_types=1);

namespace ShippingArrangementBundle\Menu;

use App\Contract\Shipping\ShippingMenuItemInterface;

final class ArrangementShippingMenuItem implements ShippingMenuItemInterface
{
    public function getLabel(): string { return 'Existing Arrangement'; }
    public function getRoute(): string { return 'admin_bundle_shipping_arrangement_index'; }
}
