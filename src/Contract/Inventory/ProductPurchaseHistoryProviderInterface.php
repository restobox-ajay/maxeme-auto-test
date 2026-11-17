<?php

declare(strict_types=1);

namespace App\Contract\Inventory;

use App\Entity\ProductCore;

/**
 * "Every time this product was bought": a part's purchase history, shown on the product's stock
 * page. Each row is one receipt of stock: when it came in, from which vendor, how many, at what
 * cost. Asked for in client feedback thread 13631.
 *
 * It follows the same seam as {@see ProductVendorProviderInterface}. The data belongs to other
 * code (ProcurementBundle's goods receipts, Maxeme's imported legacy restocks), and the stock page
 * (InventoryDepthBundle) may not reference those classes. Each one answers through this interface,
 * and {@see \App\Service\Inventory\ProductPurchaseHistoryResolver} merges what they return.
 *
 * @phpstan-type PurchaseRow array{
 *     date: \DateTimeImmutable,
 *     sku: ?string,
 *     vendor: ?string,
 *     reference: string,
 *     url: ?string,
 *     quantity: string,
 *     unitCost: ?string,
 *     totalCost: ?string,
 * }
 */
interface ProductPurchaseHistoryProviderInterface
{
    /**
     * The owning bundle, checked against App\Repository\BundleStatusRepository. Null for code
     * that is part of the application rather than a module that can be switched off.
     */
    public function getSource(): ?string;

    /**
     * Newest first, at most $limit rows.
     *
     * @return list<PurchaseRow>
     */
    public function purchaseHistory(ProductCore $product, int $limit): array;
}
