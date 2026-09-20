<?php

declare(strict_types=1);

namespace App\Contract\Bundle;

/**
 * Tells the catalog controller which custom-field slugs to compute facet counts for on a given
 * catalog page, without the controller ever hardcoding bundle-specific slugs. Same point-naming
 * convention as TemplateOverrideProviderInterface ("customer_catalog_index:{categoryId}"), but
 * union semantics like Injection Points (every active matching provider contributes, ascending
 * priority) rather than "highest priority wins" — multiple bundles can each add their own facets
 * to the same category page. See docs/bundles/catalog-filters.md.
 */
interface CatalogFacetProviderInterface
{
    public function getPoint(): string;

    public function getPriority(): int;

    public function getSource(): string;

    /** @return list<array{slug: string, label: string, advanced: bool}> */
    public function getFacets(): array;
}
