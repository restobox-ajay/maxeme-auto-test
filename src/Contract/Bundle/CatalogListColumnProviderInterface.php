<?php

declare(strict_types=1);

namespace App\Contract\Bundle;

/**
 * Tells the catalog controller which extra custom-field-backed columns to show in a given
 * catalog page's List View, without the controller ever hardcoding bundle-specific slugs. Same
 * point-naming convention as CatalogFacetProviderInterface ("customer_catalog_index:{categoryId}"),
 * and same "highest priority wins" semantics as TemplateOverrideResolver/CatalogViewConfigResolver
 * — a page's list column set is one coherent setting, not something multiple bundles should each
 * partially contribute to.
 */
interface CatalogListColumnProviderInterface
{
    public function getPoint(): string;

    public function getPriority(): int;

    public function getSource(): string;

    /** @return list<array{slug: string, label: string}> */
    public function getColumns(): array;
}
