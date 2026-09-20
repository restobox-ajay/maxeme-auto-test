<?php

declare(strict_types=1);

namespace Number1ProductImportBundle\Menu;

use App\Contract\Hook\InjectionPointMenuItemInterface;

final class ProductImportMenuItem implements InjectionPointMenuItemInterface
{
    public function getLabel(): string { return 'Number1 Product CSV Import'; }
    public function getRoute(): string { return 'admin_bundle_number1_product_import_index'; }
    public function getSection(): string { return 'Number1 Integration'; }
}
