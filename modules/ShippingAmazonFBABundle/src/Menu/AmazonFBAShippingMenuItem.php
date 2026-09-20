<?php

declare(strict_types=1);

namespace ShippingAmazonFBABundle\Menu;

use App\Contract\Shipping\ShippingMenuItemInterface;

final class AmazonFBAShippingMenuItem implements ShippingMenuItemInterface
{
    public function getLabel(): string { return 'Amazon FBA'; }
    public function getRoute(): string { return 'admin_bundle_shipping_amazon_fba'; }
}
