<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Hook;

use App\Contract\Inventory\InboundTransferProviderInterface;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use WarehouseOpsBundle\Repository\TransferOrderLineRepository;

/**
 * This module's answer to core's `InboundTransferResolver`, discovered purely by tag — the reorder
 * engine (InventoryDepthBundle) never imports this class or any other of this module's (#706). See
 * {@see \App\Contract\Inventory\InboundTransferProviderInterface}'s own docblock, the same seam
 * ProcurementBundle\Hook\ProductPurchaseActivityProvider already uses.
 */
final class InboundTransferProvider implements InboundTransferProviderInterface
{
    public function __construct(
        private readonly TransferOrderLineRepository $lines,
    ) {
    }

    public function getSource(): string
    {
        return 'WarehouseOpsBundle';
    }

    public function inTransitQuantity(ProductCore $product, Warehouse $warehouse): int
    {
        return $this->lines->inFlightToTotal($product, $warehouse);
    }
}
