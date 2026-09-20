<?php

declare(strict_types=1);

namespace App\Contract\Inventory;

use App\Entity\ProductCore;

/**
 * One more contributor to a product's Recent Activity feed
 * (docs/plans/2026-09-15-product-inventory-hub.md's `StockController::byProduct()` hub page).
 *
 * Exists so the hub — which lives in modules/InventoryDepthBundle, itself deletable — can ask a
 * *sibling* module (the procurement one, for purchase orders and receipts) for its rows without
 * ever holding a compile-time reference to that module's classes. This bundle's own
 * `ThisBundleNamesNoOtherBundleTest` is exactly the failure this seam avoids: a direct import of
 * that module's entity classes would 500 the hub the day that module's folder is deleted, on a
 * screen that has nothing to do with purchasing. Same discipline as
 * {@see LotAvailabilityProviderInterface}, applied to structured feed rows instead of a single
 * number: getSource() names the owning bundle so the App Management Active/Inactive kill-switch
 * applies without the provider checking its own status, and
 * {@see \App\Service\Inventory\ProductActivityFeedResolver} is the one core service that holds the
 * tagged iterator — the hub asks that resolver, never the providers directly.
 */
interface ProductActivityProviderInterface
{
    /** A stable slug identifying this source in the feed's type filter, e.g. 'purchase'. */
    public function getType(): string;

    /** The owning bundle's source, checked against App\Repository\BundleStatusRepository. */
    public function getSource(): string;

    /**
     * This product's last `$limit` rows from this source, newest first. Every field pre-formatted
     * for direct display — the resolver merges rows from several providers by `date` alone, so a
     * provider's own date format must already be the plain 'Y-m-d' string every other source uses.
     *
     * @return list<array{date: string, label: string, quantity: string, url: ?string}>
     */
    public function activityFor(ProductCore $product, int $limit): array;
}
