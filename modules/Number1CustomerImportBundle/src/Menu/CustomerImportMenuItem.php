<?php

declare(strict_types=1);

namespace Number1CustomerImportBundle\Menu;

use App\Contract\Hook\InjectionPointMenuItemInterface;

final class CustomerImportMenuItem implements InjectionPointMenuItemInterface
{
    public function getLabel(): string
    {
        return 'Number1 Customer CSV Import';
    }

    public function getRoute(): string
    {
        return 'admin_bundle_customer_import_index';
    }

    public function getSection(): string
    {
        return 'Number1 Integration';
    }
}
