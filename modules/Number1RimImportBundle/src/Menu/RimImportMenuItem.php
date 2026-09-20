<?php

declare(strict_types=1);

namespace Number1RimImportBundle\Menu;

use App\Contract\Hook\InjectionPointMenuItemInterface;

final class RimImportMenuItem implements InjectionPointMenuItemInterface
{
    public function getLabel(): string { return 'Number1 Rim API Import'; }
    public function getRoute(): string { return 'admin_bundle_number1_rim_import_index'; }
    public function getSection(): string { return 'Number1 Integration'; }
}
