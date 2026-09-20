<?php

declare(strict_types=1);

namespace ProcurementBundle\Purchase;

use App\Contract\Tax\TaxContext;
use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use App\Service\Uom\LineDenomination;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\PurchasedDocumentLine;
use ProcurementBundle\Entity\Vendor;

/**
 * The one document of the two whose line-building code ran with no persisted identity to protect
 * until now — `VendorBillController::save()` deleted and rebuilt every line on every save, so its
 * own rules were written with no "existing line" case in mind at all, the same starting point
 * Invoice was at on the sell side. These overrides reproduce the rules its old rebuild-from-scratch
 * code had that the base class (lifted from PurchaseOrder, which always had identity to protect)
 * does not share — chiefly that a bill line's product/name/sku prefer the PURCHASE ORDER LINE it is
 * attributed to over anything of its own, and that a blank cost is `0`, never a rate-card guess: a
 * bill states what the vendor actually charged.
 */
final class VendorBillLineReconciler extends PurchaseSideLineReconciler
{
    /**
     * The purchase order line a bill row bills against, when the row names one AND it actually
     * belongs to the order this bill is against — an id from another order, or a tampered post,
     * resolves to null and the row is treated as an unattributed charge instead.
     *
     * @param array<string, mixed> $row
     */
    protected function resolveAttributedLine(array $row, ?PurchaseOrder $order): ?PurchasedDocumentLine
    {
        $orderLineId = (int) ($row['purchase_order_line_id'] ?? 0);
        $orderLine = $orderLineId > 0 ? $this->em->find(PurchaseOrderLine::class, $orderLineId) : null;

        return $orderLine instanceof PurchaseOrderLine && $order !== null && $orderLine->getPurchaseOrder()->getId() === $order->getId()
            ? $orderLine
            : null;
    }

    /**
     * The attributed PO line's product wins outright — a bill line attributed to a purchase order
     * line is for that line's product, whatever the form happened to carry — through the shared
     * picker's no-JS box otherwise.
     *
     * @param array<string, mixed> $row
     */
    protected function resolveProduct(array $row, ?PurchasedDocumentLine $existing, ?PurchasedDocumentLine $attributedLine): ?ProductCore
    {
        return $attributedLine?->getProduct() ?? parent::resolveProduct($row, $existing, $attributedLine);
    }

    /**
     * A typed name wins, else this bill's own EXISTING line keeps its stored name (the same
     * gap-filling priority every other converted document gives an existing line over anything
     * else), else the attributed PO line's, else the product's, else 'Unnamed charge'.
     *
     * @param array<string, mixed> $row
     */
    protected function resolveName(array $row, ?ProductCore $product, ?PurchasedDocumentLine $existing, ?PurchasedDocumentLine $attributedLine): string
    {
        $typed = trim((string) ($row['name'] ?? ''));

        return $typed !== '' ? $typed : ($existing?->getName() ?? $attributedLine?->getName() ?? $product?->getName() ?? 'Unnamed charge');
    }

    /**
     * Same fields as the base, but preferring the ATTRIBUTED order line over this bill's own
     * existing entity, and with no `location` at all — a bill line has no such field.
     *
     * @param array<string, mixed> $row
     *
     * @return array{sku: ?string, vendorSku: ?string, unit: ?string, weight: ?string, location: ?string}
     */
    protected function resolveSnapshotFields(array $row, ?ProductCore $product, ?PurchasedDocumentLine $existing, ?PurchasedDocumentLine $attributedLine, ?string $vendorSkuFromRateCard): array
    {
        return [
            'sku' => $this->nullable((string) ($row['sku'] ?? '')) ?? $existing?->getSku() ?? $attributedLine?->getSku() ?? $product?->getSku(),
            'vendorSku' => $this->nullable((string) ($row['vendor_sku'] ?? '')) ?? $existing?->getVendorSku() ?? $attributedLine?->getVendorSku(),
            'unit' => $this->nullable((string) ($row['unit'] ?? '')) ?? $existing?->getUnit(),
            'weight' => $this->nullable((string) ($row['weight'] ?? '')) ?? $existing?->getWeight(),
            'location' => null,
        ];
    }

    /**
     * The tax class is a property of the GOODS, so a typed value wins whatever it says — unlike
     * PurchaseOrder, no `E`/`G`/`S` validation gates it — and it otherwise falls back to the
     * product's own `salesTaxCode`, mapped exactly as the sell side reads the same field.
     *
     * @param array<string, mixed> $row
     */
    protected function resolveTaxCode(array $row, ?ProductCore $product): ?string
    {
        $taxCode = $this->nullable((string) ($row['tax_code'] ?? '')) ?? $product?->getSalesTaxCode();

        return $taxCode !== null ? TaxContext::mapTaxCode($taxCode) : null;
    }

    /**
     * A blank cost is `0` — a bill states what the vendor actually charged, never a guess from the
     * rate card the way a purchase order's own blank line does.
     *
     * @param array<string, mixed> $row
     */
    protected function resolveUnitCost(array $row, ?ProductCore $product, ?PurchasedDocumentLine $existing, ?UnitOfMeasure $lineUnit, ?UnitOfMeasure $baseUnit, ?Vendor $vendor): ResolvedPurchaseCost
    {
        $raw = trim((string) ($row['unit_cost'] ?? ''));

        if ($existing !== null && $raw !== '' && LineDenomination::boxUntouched($row['unit_cost'] ?? null, $row['unit_cost_rendered'] ?? null)) {
            return new ResolvedPurchaseCost($existing->getUnitCost());
        }
        if ($raw !== '') {
            return new ResolvedPurchaseCost(LineDenomination::toBasePrice($raw, $lineUnit, $baseUnit));
        }

        return new ResolvedPurchaseCost($existing?->getUnitCost() ?? '0');
    }
}
