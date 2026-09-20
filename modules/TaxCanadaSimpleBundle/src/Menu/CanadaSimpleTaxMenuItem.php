<?php

declare(strict_types=1);

namespace TaxCanadaSimpleBundle\Menu;

use App\Contract\Tax\TaxMenuItemInterface;

final class CanadaSimpleTaxMenuItem implements TaxMenuItemInterface
{
    public function getLabel(): string
    {
        return 'Canada (non-BC)';
    }

    public function getRoute(): string
    {
        return 'admin_bundle_tax_canada_simple_config';
    }

    public function getSource(): string
    {
        return 'TaxCanadaSimpleBundle';
    }
}
