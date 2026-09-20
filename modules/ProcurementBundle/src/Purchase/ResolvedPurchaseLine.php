<?php

declare(strict_types=1);

namespace ProcurementBundle\Purchase;

use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use ProcurementBundle\Entity\PurchasedDocumentLine;

/**
 * One posted row, resolved — what {@see PurchaseSideLineReconciler} hands back for a caller to
 * write onto its OWN concrete line entity (PurchaseOrderLine/VendorBillLine), the same split of
 * responsibility {@see \App\Service\Document\ResolvedSellSideLine} uses on the sell side and for
 * the same reason: the two entities share no common setter surface.
 *
 * $existingId is null for a brand-new line, non-null for a posted row that matched an existing one
 * of THIS document's own lines by id — never the cross-document attribution
 * (`VendorBillLine::$purchaseOrderLine`), which is a different axis the reconciler does not model
 * at all; see {@see \ProcurementBundle\Controller\Admin\VendorBillController} for how that is
 * resolved alongside this.
 *
 * $writeQuantity/$writeUnitCost say whether the CALLER should even call the setter — both columns
 * are wider than the form's boxes can express (quantity NUMERIC(14,4) against a 2dp box, unit cost
 * NUMERIC(18,6) against a 4dp box), so re-writing an untouched, unpackaged line would truncate a
 * stored residue an RFQ conversion or a packaging ladder had put there. False only for an EXISTING
 * line, with no unit involved now or before, whose resolved figure doesn't actually differ from
 * what is already stored.
 */
final class ResolvedPurchaseLine
{
    public function __construct(
        public readonly ?int $existingId,
        /**
         * The OTHER document's line this row is attributed to, when there is one — see
         * PurchaseSideLineReconciler::resolveAttributedLine(). Always null for PurchaseOrder's own
         * lines; a VendorBillLine's own {@see \ProcurementBundle\Entity\PurchaseOrderLine} for
         * VendorBill's. The reconciler never writes this attribution itself — surfaced only so the
         * caller can, the same split every other field on this class already makes.
         */
        public readonly ?PurchasedDocumentLine $attributedLine,
        public readonly ?ProductCore $product,
        public readonly string $name,
        public readonly ?string $sku,
        public readonly ?string $vendorSku,
        public readonly ?string $unit,
        public readonly ?string $weight,
        public readonly ?string $location,
        public readonly ?string $taxCode,
        public readonly ?string $batch,
        public readonly string $unitCost,
        public readonly string $subtotal,
        public readonly int $sortOrder,
        public readonly float $enteredQuantity,
        public readonly float $baseQuantity,
        public readonly ?UnitOfMeasure $lineUnit,
        public readonly ?UnitOfMeasure $baseUnit,
        public readonly bool $writeQuantity,
        public readonly bool $writeUnitCost,
    ) {
    }
}
