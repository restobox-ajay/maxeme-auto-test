<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\Menu;

use App\Contract\Hook\InjectionPointMenuItemInterface;

final class CategoryProductPageMenuItem implements InjectionPointMenuItemInterface
{
    public function getLabel(): string { return 'Category Product Pages'; }
    public function getRoute(): string { return 'admin_bundle_number1_category_product_page_index'; }
    public function getSection(): string { return 'Number1 Integration'; }
}
