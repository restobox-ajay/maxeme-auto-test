<?php

declare(strict_types=1);

namespace TaxBCBundle\Menu;

use App\Contract\Tax\TaxMenuItemInterface;

final class BCTaxMenuItem implements TaxMenuItemInterface
{
    public function getLabel(): string
    {
        return 'BC (GST/PST)';
    }

    public function getRoute(): string
    {
        return 'admin_bundle_tax_bc_config';
    }

    public function getSource(): string
    {
        return 'TaxBCBundle';
    }
}
