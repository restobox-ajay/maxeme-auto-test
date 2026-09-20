<?php

declare(strict_types=1);

namespace ShippingCanadaPostBundle\Menu;

use App\Contract\Shipping\ShippingMenuItemInterface;

final class CanadaPostShippingMenuItem implements ShippingMenuItemInterface
{
    public function getLabel(): string { return 'Canada Post'; }
    public function getRoute(): string { return 'admin_bundle_shipping_canada_post'; }
}
