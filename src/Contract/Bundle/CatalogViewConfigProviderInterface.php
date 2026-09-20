<?php

declare(strict_types=1);

namespace App\Contract\Bundle;

/**
 * Tells the catalog controller which view modes (grid/list) a given catalog page allows, and
 * which one is the default, without the controller ever hardcoding bundle-specific category
 * ids. Same point-naming convention as TemplateOverrideProviderInterface
 * ("customer_catalog_index:{categoryId}"), and same "highest priority wins" semantics as
 * TemplateOverrideResolver — a page's view-mode config is one coherent setting, not something
 * multiple bundles should be able to each partially contribute to.
 */
interface CatalogViewConfigProviderInterface
{
    public function getPoint(): string;

    public function getPriority(): int;

    public function getSource(): string;

    /** @return array{gridAvailable: bool, listAvailable: bool, defaultView: string} */
    public function getViewConfig(): array;
}
