<?php

declare(strict_types=1);

namespace ProcurementBundle\Purchase;

use App\Contract\Tax\TaxContext;
use App\Entity\ProductAvailableUnit;
use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use App\Service\Product\ProductPicker;
use App\Service\QuantityScale;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchasedDocumentLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Repository\VendorPriceRepository;

/**
 * Turns a buy-side document's posted `lines[]` rows into {@see ResolvedPurchaseLine}s — the one
 * place "what does this row mean" is decided for PurchaseOrder and VendorBill, extracted from
 * `PurchaseOrderController::requestedLines()`/`save()` (the more complete of the two: it is the
 * only one that already upserts by id, resolves the vendor rate card and protects stored precision
 * from an untouched box). PurchaseOrder moves onto this call first with zero behavior change;
 * VendorBill moves onto it in turn, converting its own delete-and-rebuild the same way Invoice's
 * did on the sell side — see the plan this class was built against.
 *
 * What this class does NOT do: construct or mutate a `PurchaseOrderLine`/`VendorBillLine`, or
 * decide which OTHER document's line a row is attributed to (`VendorBillLine::$purchaseOrderLine`)
 * — that cross-document attribution is a different axis from "which of THIS document's own lines
 * did this row match", resolved by `resolveAttributedLine()` and threaded into every other hook so
 * VendorBill's field-fallback rules (name/sku/product all prefer the PO line they bill) can read
 * it, but never written by this class itself.
 *
 * ## Same process, same shape, room for a document to differ where it genuinely must
 *
 * `reconcile()` IS the process: skip a non-positive-quantity row, match-by-id (with double-claim
 * protection — a second row naming an already-claimed id is treated as new rather than fighting
 * the first for the same line), unit resolution, boxUntouched semantics for BOTH quantity and unit
 * cost, and the write-only-when-actually-changed guard that protects a stored precision this form's
 * boxes cannot round-trip. `resolveUnitCost()`/`resolveName()`/`resolveSnapshotFields()`/
 * `resolveTaxCode()`/`resolveAttributedLine()` are the seams a document's own rule diverges on.
 */
class PurchaseSideLineReconciler
{
    public function __construct(
        // Protected rather than private: VendorBillLineReconciler::resolveAttributedLine() is the
        // one hook that needs to look up an entity of its own (the PurchaseOrderLine a row names).
        protected readonly EntityManagerInterface $em,
        private readonly VendorPriceRepository $vendorPrices,
    ) {
    }

    /**
     * @param array<int, PurchasedDocumentLine> $existingLines keyed by id, e.g. `PurchaseOrderLine::getId()`
     * @param list<array<string, mixed>> $rows raw `lines[]` rows
     * @param ?PurchaseOrder $order the purchase order THIS document is against, if any — read only
     *     by `resolveAttributedLine()`; PurchaseOrder's own call never needs it
     *
     * @return array{lines: list<ResolvedPurchaseLine>, keptIds: array<int, true>, subtotal: string}
     */
    final public function reconcile(array $existingLines, array $rows, ?Vendor $vendor, ?PurchaseOrder $order = null): array
    {
        $resolved = [];
        $keptIds = [];
        $claimed = [];
        $subtotal = '0';
        $sortOrder = 0;

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            // #635: `qty`, not `quantity`/`quantity_ordered` — the shared _purchase_line_row.html.twig
            // posts every buy-side document's quantity box under this one name. A blank or
            // non-positive figure is a spare row, not an error.
            $quantityRaw = trim((string) ($row['qty'] ?? ''));
            if ($quantityRaw === '' || QuantityScale::compare($quantityRaw, 0) <= 0) {
                continue;
            }

            $existingId = (int) ($row['id'] ?? 0);
            $existing = $existingId > 0 && !isset($claimed[$existingId]) ? ($existingLines[$existingId] ?? null) : null;
            $isExistingLine = $existing !== null;
            if ($isExistingLine) {
                $claimed[$existingId] = true;
                $keptIds[$existingId] = true;
            }

            $attributedLine = $this->resolveAttributedLine($row, $order);

            $product = $this->resolveProduct($row, $existing, $attributedLine);

            $lineUnit = $this->lineUnitFor($row['unit_id'] ?? null, $product);
            $baseUnit = LineDenomination::baseUnitOf($product);
            $factorToBase = LineDenomination::factorToBase($lineUnit, $baseUnit);

            if ($isExistingLine && LineDenomination::boxUntouched($row['qty'] ?? null, $row['qty_rendered'] ?? null)) {
                $baseQuantity = (float) $existing->getQuantityBase();
                $enteredQuantity = $factorToBase > 0 ? $baseQuantity / $factorToBase : $baseQuantity;
            } else {
                $enteredQuantity = (float) $quantityRaw;
                $baseQuantity = $enteredQuantity * $factorToBase;
            }
            $baseQuantityCanonical = QuantityScale::canonical($baseQuantity);

            $cost = $this->resolveUnitCost($row, $product, $existing, $lineUnit, $baseUnit, $vendor);
            $unitCostForColumn = $this->lineRate($cost->unitCost, $lineUnit);
            $subtotalForRow = self::lineSubtotal($baseQuantityCanonical, $cost->unitCost);
            $subtotal = bcadd($subtotal, $subtotalForRow, 2);

            $snapshot = $this->resolveSnapshotFields($row, $product, $existing, $attributedLine, $cost->vendorSkuFromRateCard);

            // Written only when they actually change — both columns are wider than this form's
            // boxes can express (quantity NUMERIC(14,4) against a 2dp box, unit cost NUMERIC(18,6)
            // against a 4dp box), so re-writing an untouched, unpackaged line would truncate a
            // stored residue an RFQ conversion or a packaging ladder had put there. A unit
            // selection changes what needs writing even when the BASE figure has not moved, so it
            // always goes through when either the posted row or the stored line names one.
            $currentlyHasUnit = $isExistingLine && $existing->getUnitOfMeasure() !== null;
            $unitInvolved = $lineUnit !== null || $currentlyHasUnit;
            $writeQuantity = !$isExistingLine || $unitInvolved
                || QuantityScale::compare($existing->getQuantityBase(), $baseQuantityCanonical) !== 0;
            $writeUnitCost = !$isExistingLine || $unitInvolved
                || self::costChanged($existing->getUnitCost(), $unitCostForColumn);

            $resolved[] = new ResolvedPurchaseLine(
                existingId: $isExistingLine ? $existingId : null,
                attributedLine: $attributedLine,
                product: $product,
                name: $this->resolveName($row, $product, $existing, $attributedLine),
                sku: $snapshot['sku'],
                vendorSku: $snapshot['vendorSku'],
                unit: $snapshot['unit'],
                weight: $snapshot['weight'],
                location: $snapshot['location'],
                taxCode: $this->resolveTaxCode($row, $product),
                batch: $this->nullable((string) ($row['batch'] ?? '')),
                unitCost: $unitCostForColumn,
                subtotal: $subtotalForRow,
                sortOrder: $sortOrder++,
                enteredQuantity: $enteredQuantity,
                baseQuantity: (float) $baseQuantityCanonical,
                lineUnit: $lineUnit,
                baseUnit: $baseUnit,
                writeQuantity: $writeQuantity,
                writeUnitCost: $writeUnitCost,
            );
        }

        return ['lines' => $resolved, 'keptIds' => $keptIds, 'subtotal' => $subtotal];
    }

    /**
     * The OTHER document's line this row is attributed to, when there is one — never a match
     * against THIS document's own lines (that is `$existing`, resolved in `reconcile()` itself).
     * PurchaseOrder has nothing to attribute to and always answers null. Overridden by
     * `VendorBillLineReconciler`, which resolves the `PurchaseOrderLine` a bill row bills against.
     *
     * @param array<string, mixed> $row
     */
    protected function resolveAttributedLine(array $row, ?PurchaseOrder $order): ?PurchasedDocumentLine
    {
        return null;
    }

    /**
     * The product a row names. PurchaseOrder: whatever the row's picker posted, else — on an
     * EXISTING line — the product it already has: a blank field is a gap to fill, never an
     * instruction to clear. Silently detaching a received line from its product would break the
     * `incoming` forecast and leave a goods receipt recording an arrival of something the order no
     * longer names. Overridden by `VendorBillLineReconciler`: the attributed PO line's product wins
     * outright — a bill line attributed to a purchase order line is for that line's product,
     * whatever the form happened to carry.
     *
     * @param array<string, mixed> $row
     */
    protected function resolveProduct(array $row, ?PurchasedDocumentLine $existing, ?PurchasedDocumentLine $attributedLine): ?ProductCore
    {
        $productId = ProductPicker::idFromPostedRow($row, 'product_id');
        $product = $productId > 0 ? $this->em->find(ProductCore::class, $productId) : null;

        return $product instanceof ProductCore ? $product : $existing?->getProduct();
    }

    /**
     * What this row is called. PurchaseOrder: a typed name wins, else the EXISTING line's own
     * stored name, else the product's, else 'Unnamed line' — a saved line keeps its own name ahead
     * of the catalogue's, the same reason its stored cost and sku do (see `resolveSnapshotFields()`
     * and `resolveUnitCost()`): a purchase order printed today and read in three years says what it
     * said when it was sent. Overridden by `VendorBillLineReconciler`, which falls back to the
     * ATTRIBUTED order line's name rather than its own existing entity, and to 'Unnamed charge'.
     *
     * @param array<string, mixed> $row
     */
    protected function resolveName(array $row, ?ProductCore $product, ?PurchasedDocumentLine $existing, ?PurchasedDocumentLine $attributedLine): string
    {
        $typed = trim((string) ($row['name'] ?? ''));

        return $typed !== '' ? $typed : ($existing?->getName() ?? $product?->getName() ?? 'Unnamed line');
    }

    /**
     * SKU, vendor SKU, unit label, weight, delivery location — the catalogue/existing-line snapshot
     * fields every row carries. PurchaseOrder: a typed cell wins, else the EXISTING line's own
     * stored value (never the catalogue's for vendor SKU or location, which have no catalogue
     * source), else the product's for sku/unit/weight. `$vendorSkuFromRateCard` is the vendor
     * price's own SKU, surfaced by `resolveUnitCost()`'s own lookup — it stands in only when
     * neither the row nor the existing line named one, the same gap-filling priority as the rest of
     * this method. Overridden by `VendorBillLineReconciler` to prefer the ATTRIBUTED order line
     * over its own existing entity, and to drop `location` (a bill has no such field).
     *
     * @param array<string, mixed> $row
     *
     * @return array{sku: ?string, vendorSku: ?string, unit: ?string, weight: ?string, location: ?string}
     */
    protected function resolveSnapshotFields(array $row, ?ProductCore $product, ?PurchasedDocumentLine $existing, ?PurchasedDocumentLine $attributedLine, ?string $vendorSkuFromRateCard): array
    {
        return [
            'sku' => $this->nullable((string) ($row['sku'] ?? '')) ?? $existing?->getSku() ?? $product?->getSku(),
            // The rate card wins over the existing line's own stored vendor SKU here — reached only
            // when the SAME lookup also had to fill a blank cost, at which point a fresher rate
            // card entry is exactly the fact this row is filling the gap with.
            'vendorSku' => $this->nullable((string) ($row['vendor_sku'] ?? '')) ?? $vendorSkuFromRateCard ?? $existing?->getVendorSku(),
            'unit' => $this->nullable((string) ($row['unit'] ?? '')) ?? $existing?->getUnit(),
            'weight' => $this->nullable((string) ($row['weight'] ?? '')) ?? $existing?->getWeight(),
            'location' => $this->nullable((string) ($row['location'] ?? '')) ?? $existing?->getLocation(),
        ];
    }

    /**
     * The tax class a posted line is bought under. PurchaseOrder: the row's own choice wins, but
     * only when it is exactly `E`/`G`/`S` — anything else is not a real choice and falls straight
     * through to the product's own `salesTaxCode` (a fact about the PRODUCT, not about the direction
     * of the transaction, which is why reading it here is not borrowing from the sell side).
     * Overridden by `VendorBillLineReconciler`, which accepts any nonblank typed value and maps it
     * through `TaxContext::mapTaxCode()` rather than validating against the three-code vocabulary
     * directly.
     *
     * @param array<string, mixed> $row
     */
    protected function resolveTaxCode(array $row, ?ProductCore $product): ?string
    {
        $posted = strtoupper(trim((string) ($row['tax_code'] ?? '')));
        if (\in_array($posted, ['E', 'G', 'S'], true)) {
            return $posted;
        }

        return $product instanceof ProductCore && $product->getSalesTaxCode() !== null
            ? TaxContext::mapTaxCode($product->getSalesTaxCode())
            : null;
    }

    /**
     * What a row's unit cost resolves to. PurchaseOrder: an untouched box on an existing line with
     * a typed figure keeps the stored per-base rate verbatim (boxUntouched, the same rule the qty
     * box gets); else a typed figure wins, converted into base units; else — #637 — the EXISTING
     * line's own stored cost first (a blank box on a saved row means it was cleared on purpose, not
     * "look this up again"), and only failing that the vendor's own rate card, which may also name
     * the vendor's SKU for the same product. Overridden by `VendorBillLineReconciler`: same
     * boxUntouched/typed precedence, but a genuinely blank cost is `0` — a bill states what the
     * vendor actually charged, never a guess from a rate card.
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

        $unitCost = $existing?->getUnitCost() ?? '';
        if ($unitCost === '' && $product instanceof ProductCore && $vendor instanceof Vendor) {
            $vendorPrice = $this->vendorPrices->findOneActiveFor($vendor, $product);
            if ($vendorPrice !== null) {
                return new ResolvedPurchaseCost($vendorPrice->getUnitCost(), $vendorPrice->getVendorSku());
            }
        }

        return new ResolvedPurchaseCost($unitCost !== '' ? $unitCost : '0');
    }

    private function lineUnitFor(mixed $rawId, ?ProductCore $product): ?UnitOfMeasure
    {
        if ($product === null) {
            return null;
        }

        $id = \is_scalar($rawId) ? (int) $rawId : 0;
        if ($id <= 0 || $id === (int) ($product->getBaseUnit()?->getId() ?? 0)) {
            return null;
        }

        $unit = $this->em->find(UnitOfMeasure::class, $id);
        if (!$unit instanceof UnitOfMeasure) {
            return null;
        }

        $offered = (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(ProductAvailableUnit::class, 'a')
            ->andWhere('a.product = :product')->setParameter('product', $product)
            ->andWhere('a.unit = :unit')->setParameter('unit', $unit)
            ->getQuery()->getSingleScalarResult();

        return $offered > 0 ? $unit : null;
    }

    protected function lineRate(string $value, ?UnitOfMeasure $unit): string
    {
        return number_format((float) $value, $unit === null ? 4 : LineDenomination::RATE_SCALE, '.', '');
    }

    protected function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** A stored/posted unit cost, rounded to the same 4dp `PurchaseOrderLineEditGuard::cost()` compares at, so "did this actually change" agrees with what that guard would say. */
    private static function costChanged(string $stored, string $posted): bool
    {
        return number_format((float) $stored, 4, '.', '') !== number_format((float) $posted, 4, '.', '');
    }

    /** Quantity times unit cost, to the cent. Exact throughout — no float, no scaling to lose track of. */
    private static function lineSubtotal(string $quantity, string $unitCost): string
    {
        return bcround(bcmul($quantity, $unitCost, 10), 2, \RoundingMode::HalfAwayFromZero);
    }
}
