<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\DenominatedLine;
use App\Entity\ProductCore;

/**
 * A product row on a buy-side document — {@see PurchaseOrderLine} or {@see VendorBillLine}, the two
 * {@see \ProcurementBundle\Purchase\PurchaseSideLineReconciler} reconciles. Mirrors
 * {@see \App\Entity\CostedDocumentLine} on the sell side: one buy-side money field (`unitCost`)
 * rather than the sell side's cost/price pair, and no direct `DenominatedLine` extension here since
 * both entities already implement that separately — this interface only adds what
 * `DenominatedLine` does not already cover.
 */
interface PurchasedDocumentLine extends DenominatedLine
{
    public function getId(): ?int;

    public function getProduct(): ?ProductCore;

    public function getName(): string;

    public function getSku(): ?string;

    public function getVendorSku(): ?string;

    public function getUnitCost(): string;

    public function getUnit(): ?string;

    public function getWeight(): ?string;

    public function getLocation(): ?string;

    public function getTaxCode(): ?string;

    public function getBatch(): ?string;
}
