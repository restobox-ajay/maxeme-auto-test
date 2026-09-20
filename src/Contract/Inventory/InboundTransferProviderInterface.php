<?php

declare(strict_types=1);

namespace App\Contract\Inventory;

use App\Entity\ProductCore;
use App\Entity\Warehouse;

/**
 * "How much of this is already dispatched toward this warehouse, but not yet received" — the
 * reorder engine's missing term (#706): `IncomingStockReconciler` only ever counted open purchase
 * orders, so a warehouse with a transfer already on the truck still read as short and got a
 * purchase order raised on top of stock already on its way.
 *
 * Shaped exactly like {@see ProductVendorProviderInterface}: the owning bundle
 * (WarehouseOpsBundle) holds the transfer data, and InventoryDepthBundle's reorder engine may not
 * hold a compile-time reference to it — see
 * `InventoryDepthBundle\Tests\Unit\ThisBundleNamesNoOtherBundleTest`.
 *
 * {@see \App\Service\Inventory\InboundTransferResolver} is the one core service holding the tagged
 * iterator; absent or Inactive means it answers 0, same as "nothing in transit" — which is exactly
 * right, since with WarehouseOpsBundle off there is no transfer screen for anything to be in
 * transit on.
 */
interface InboundTransferProviderInterface
{
    /** The owning bundle's source, checked against App\Repository\BundleStatusRepository. */
    public function getSource(): string;

    /**
     * Dispatched, not yet received, toward this warehouse, for this product — summed across every
     * open (dispatched but not received) transfer that names it.
     */
    public function inTransitQuantity(ProductCore $product, Warehouse $warehouse): int;
}
