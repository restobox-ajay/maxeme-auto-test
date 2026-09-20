<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Contract\Tax\TaxContext;
use App\Entity\CostedDocumentLine;
use App\Entity\DenominatedLine;
use App\Entity\ProductAvailableUnit;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use App\Entity\UnitOfMeasure;
use App\Service\QuantityScale;
use App\Service\SalesDocumentLineWarnings;
use App\Service\SalesDocumentMoney;
use App\Service\TextInput;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a sell-side document's posted `lines[]` rows into {@see ResolvedSellSideLine}s — the one
 * place "what does this row mean" is decided for SalesOrder, Invoice and Estimate, extracted from
 * `OrderController::edit()`'s own line loop (the most complete of the three: it is the only one
 * that already resolves cost/price defaults, lot/serial, batch and a restock ETA together). Order,
 * Estimate and Invoice all move onto this one call in turn — see the plan this class was built
 * against — rather than each keeping its own copy of the same decisions, which is how Invoice's
 * save mechanics drifted from the other two without anyone noticing for a week.
 *
 * What this class does NOT do: construct or mutate a `SalesOrderLine`/`InvoiceLine`/`EstimateLine`.
 * The three share no common setter surface ({@see \App\Entity\DocumentLine} is read-only on purpose), so the
 * caller applies each resolved row onto its own concrete entity type — a short, obviously-correct
 * mapping, and the one piece that is allowed to differ per document without being a hook.
 *
 * ## Same process, same shape, room for a document to differ where it genuinely must
 *
 * This class IS the process: match-by-id, unit resolution, boxUntouched semantics, orphan removal —
 * identical for all three, never overridden. `resolveCost()`/`resolvePrice()` are the two seams
 * where a document's own rule actually diverges (see {@see EstimateLineReconciler}, whose quote-only
 * "TBD" price state Order and Invoice never reach) — protected, fixed signatures, a subclass per
 * document overrides only what it must and inherits everything else. Every other divergence found
 * while building this (money shape, save mechanics, stock-blocking, attribution) lives one level up,
 * in the controller that calls this class, not here.
 */
class SellSideLineReconciler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QuantityScale $quantityScale,
    ) {
    }

    /**
     * @param array<int, CostedDocumentLine> $existingLines keyed by id, e.g. `SalesOrderLine::getId()`
     * @param list<array<string, mixed>> $rows in `postedLineRows()`'s shape
     *
     * @return array{lines: list<ResolvedSellSideLine>, keptIds: array<int, true>, subtotal: string, allPriced: bool}
     */
    final public function reconcile(
        array $existingLines,
        array $rows,
        ?string $documentFulfillmentRegion,
        SalesDocumentLineWarnings $lineWarnings,
    ): array {
        $resolved = [];
        $keptIds = [];
        $subtotal = 0.0;
        $allPriced = true;
        $rowIndex = 0;

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            // Resolved BEFORE the blank check below (new): whether a row counts as blank can depend
            // on whether it names a real existing line — see EstimateLineReconciler::isBlankLine(),
            // which never drops a row that still points at a persisted line even when the row itself
            // carries no product/name, the same way EstimateController::applyLinesFromRequest() always
            // has. Order/Invoice have no such carve-out: a blank row is blank whether or not it names
            // an id, matching their own original behavior exactly.
            $existingId = (int) ($row['id'] ?? 0);
            $existing = $existingId > 0 ? ($existingLines[$existingId] ?? null) : null;

            if ($this->isBlankLine($row, $existing)) {
                continue;
            }

            $product = null;
            $productId = $this->lineProductId($row);
            if ($productId > 0) {
                $found = $this->em->find(ProductCore::class, $productId);
                $product = $found instanceof ProductCore ? $found : null;
            }

            $defaults = $this->productLineDefaults($product);

            $isExistingLine = $existing !== null;
            if ($isExistingLine) {
                $keptIds[$existingId] = true;
            }

            // Only Estimate's hooks read this (a brand new line counts too, same as a re-pointed
            // existing one — see EstimateLineReconciler) — computed here, once, so every hook that
            // takes it gets the same answer rather than each recomputing its own.
            $productJustAttached = $product !== null && $existing?->getProduct()?->getId() !== $product->getId();

            $lineUnit = $this->lineUnitFor($row['unit_id'] ?? null, $product);
            $baseUnit = LineDenomination::baseUnitOf($product);
            $factorToBase = LineDenomination::factorToBase($lineUnit, $baseUnit);

            if ($isExistingLine && LineDenomination::boxUntouched($row['qty'] ?? null, $row['qty_rendered'] ?? null)) {
                $baseQuantity = (float) $existing->getQuantity();
                $enteredQuantity = $factorToBase > 0 ? $baseQuantity / $factorToBase : $baseQuantity;
            } else {
                $enteredQuantity = $lineWarnings->quantityForRow((float) ($row['qty'] ?? 0), $rowIndex);
                $baseQuantity = $enteredQuantity * $factorToBase;
            }

            $priceResolution = $this->resolvePrice(
                $product,
                $existing,
                $productJustAttached,
                $row,
                $defaults,
                $lineUnit,
                $baseUnit,
                $baseQuantity,
                $lineWarnings,
                $rowIndex,
            );
            if (!$priceResolution->priced) {
                $allPriced = false;
            }
            if ($priceResolution->lineSubtotal !== null) {
                $subtotal = SalesDocumentMoney::intermediate($subtotal + (float) $priceResolution->lineSubtotal);
            }

            $snapshot = $this->resolveSnapshotFields($product, $existing, $productJustAttached, $row, $defaults);

            $costRaw = $this->rawLineAmount($row['cost'] ?? null);
            $cost = $this->resolveCost($product, $existing, $productJustAttached, $costRaw, $defaults);

            $resolved[] = new ResolvedSellSideLine(
                existingId: $isExistingLine ? $existingId : null,
                product: $product,
                name: $this->resolveName($row, $product, $existing, $productJustAttached),
                location: $this->resolveLocation($this->nullableString($row['location'] ?? null), $isExistingLine, $documentFulfillmentRegion),
                sku: $snapshot['sku'],
                weight: $snapshot['weight'],
                unit: $snapshot['unit'],
                taxCode: $snapshot['taxCode'],
                cost: $cost,
                price: $priceResolution->price,
                priceForColumn: $priceResolution->priceForColumn,
                batch: $this->nullableString($row['batch'] ?? null),
                lotId: $this->nullableLotId($row['lot_id'] ?? null),
                serial: $this->nullableString($row['serial'] ?? null),
                restockEta: $this->lineRestockEta($row),
                sortOrder: $rowIndex,
                enteredQuantity: $enteredQuantity,
                baseQuantity: $baseQuantity,
                lineUnit: $lineUnit,
                baseUnit: $baseUnit,
                lineSubtotal: $priceResolution->lineSubtotal,
            );
            $rowIndex++;
        }

        return ['lines' => $resolved, 'keptIds' => $keptIds, 'subtotal' => $this->decimal($subtotal), 'allPriced' => $allPriced];
    }

    /**
     * A blank location's fallback. Order/Estimate: a NEW line falls back to the document's own
     * fulfillment region; an EXISTING line's blank stays null — a deliberate blank on a persisted
     * line must not be quietly backfilled. Overridden by {@see InvoiceLineReconciler}, which backs
     * every blank location onto the document's region regardless of new/existing, matching
     * `InvoiceController::applyLineRows()`'s own rule (its lines had no persisted identity to
     * protect until this reconciler gave them one).
     */
    protected function resolveLocation(?string $postedLocation, bool $isExistingLine, ?string $documentFulfillmentRegion): ?string
    {
        return $postedLocation ?? ($isExistingLine ? null : $this->nullableString($documentFulfillmentRegion));
    }

    /**
     * What a blank posted cost falls back to. Order: always the catalog cost price — existing
     * line or not; a non-numeric typed value is coerced to 0 rather than treated as blank.
     * Overridden by {@see EstimateLineReconciler}, which only takes the catalog figure when the
     * product was just attached and otherwise leaves an existing line's stored cost alone (see
     * $existing->getCost() there): a quote's line is not silently re-costed
     * to today's catalog on every unrelated save.
     */
    protected function resolveCost(?ProductCore $product, ?CostedDocumentLine $existing, bool $productJustAttached, string $costRaw, array $defaults): string
    {
        $cost = $costRaw !== '' ? (float) $costRaw : $defaults['cost'];

        return $this->decimal($cost);
    }

    /**
     * SKU/weight/unit/tax-code — the catalog snapshot fields every line carries. Order/Invoice:
     * whatever the row posted, else the catalog's current figure, unconditionally — a field this
     * row's post did not even carry resolves the same as one posted blank. Overridden by
     * {@see EstimateLineReconciler}, which only touches a field the post actually carries at all
     * (`array_key_exists`) and otherwise leaves the line's own stored value alone: a narrower POST
     * must not read as "clear this."
     *
     * @param array<string, mixed> $row
     * @param array{cost: float, price: float, taxCode: ?string, weight: ?string, unit: ?string} $defaults
     *
     * @return array{sku: ?string, weight: ?string, unit: ?string, taxCode: ?string}
     */
    protected function resolveSnapshotFields(?ProductCore $product, ?CostedDocumentLine $existing, bool $productJustAttached, array $row, array $defaults): array
    {
        return [
            'sku' => $this->nullableString($row['sku'] ?? null) ?? $product?->getSku(),
            'weight' => $this->nullableString($row['weight'] ?? null) ?? $defaults['weight'],
            'unit' => $this->nullableString($row['unit'] ?? null) ?? $defaults['unit'],
            'taxCode' => $this->nullableString($row['tax_code'] ?? null) ?? $defaults['taxCode'],
        ];
    }

    /**
     * What a row's price resolves to, and whether it counts as priced at all. Order/Invoice: always
     * a number — boxUntouched preserves the stored rate, otherwise typed-or-catalog-default, never
     * null, always `priced: true`. Overridden by {@see EstimateLineReconciler} for the one state
     * only a quote reaches: a genuinely unpriced ("TBD") line.
     *
     * @param array<string, mixed> $row
     * @param array{cost: float, price: float, taxCode: ?string, weight: ?string, unit: ?string} $defaults
     */
    protected function resolvePrice(
        ?ProductCore $product,
        ?CostedDocumentLine $existing,
        bool $productJustAttached,
        array $row,
        array $defaults,
        ?UnitOfMeasure $lineUnit,
        ?UnitOfMeasure $baseUnit,
        float $baseQuantity,
        SalesDocumentLineWarnings $lineWarnings,
        int $rowIndex,
    ): ResolvedLinePrice {
        $priceRaw = $this->rawLineAmount($row['price'] ?? null);

        if ($existing !== null && LineDenomination::boxUntouched($row['price'] ?? null, $row['price_rendered'] ?? null)) {
            $price = (string) ($existing->getPrice() ?? '0');
            $priceForColumn = $price;
        } else {
            $price = $priceRaw !== ''
                ? LineDenomination::toBasePrice((string) $lineWarnings->priceForRow($priceRaw, $rowIndex), $lineUnit, $baseUnit)
                : (string) $defaults['price'];
            $priceForColumn = $this->lineRate($price, $lineUnit);
        }

        $lineSubtotal = SalesDocumentMoney::intermediate($baseQuantity * (float) $price);

        return new ResolvedLinePrice($price, $priceForColumn, $this->decimal($lineSubtotal), priced: true);
    }

    /** Writes the resolved entered/base quantity pair through the one call that owns both (#659). */
    final public function applyQuantity(DenominatedLine $line, ResolvedSellSideLine $resolved): void
    {
        if ($resolved->lineUnit !== null) {
            $line->setEnteredQuantity($this->quantityScale->round($resolved->enteredQuantity), $resolved->lineUnit, $resolved->baseUnit);

            return;
        }

        $line->setQuantity($this->quantityScale->round($resolved->baseQuantity));
    }

    /**
     * A row with no product and no typed name is a spare, never-filled-in row — skip it. Order/
     * Invoice: true regardless of $existing, matching their own original behavior exactly. Overridden
     * by {@see EstimateLineReconciler}, which never treats a row naming a real existing line as blank
     * even when that row carries neither a product nor a name (a product-less custom line whose name
     * field simply was not resubmitted) — matching `EstimateController::applyLinesFromRequest()`'s own
     * `$existing === null &&`-gated check.
     */
    protected function isBlankLine(array $row, ?CostedDocumentLine $existing): bool
    {
        return $this->rawLineValue($row['product_id'] ?? null) === ''
            && $this->rawLineValue($row['product_id_manual'] ?? null) === ''
            && $this->rawLineValue($row['name'] ?? null) === '';
    }

    protected function rawLineValue(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }

    private function lineProductId(array $row): int
    {
        $manual = \is_scalar($row['product_id_manual'] ?? null) ? (int) $row['product_id_manual'] : 0;
        if ($manual > 0) {
            return $manual;
        }

        return \is_scalar($row['product_id'] ?? null) ? (int) $row['product_id'] : 0;
    }

    /**
     * What this row is called. Order/Invoice: whatever the row posted, else the product's name,
     * else 'Custom line' — recomputed every save, existing line or not. Overridden by
     * {@see EstimateLineReconciler}, which only re-derives the name from the product on a genuine
     * re-attach and otherwise leaves an existing line's own stored name untouched.
     *
     * @param array<string, mixed> $row
     */
    protected function resolveName(array $row, ?ProductCore $product, ?CostedDocumentLine $existing, bool $productJustAttached): string
    {
        return $this->nullableString($row['name'] ?? null) ?? $product?->getName() ?? 'Custom line';
    }

    /** @return array{cost: float, price: float, taxCode: ?string, weight: ?string, unit: ?string} */
    protected function productLineDefaults(?ProductCore $product): array
    {
        if ($product === null) {
            return ['cost' => 0.0, 'price' => 0.0, 'taxCode' => null, 'weight' => null, 'unit' => null];
        }

        $pricing = $this->em->getRepository(ProductPricing::class)->findOneBy(['product' => $product], ['id' => 'ASC']);

        return [
            'cost' => (float) ($product->getCostPrice() ?? 0),
            'price' => $pricing instanceof ProductPricing ? (float) $pricing->getPrice() : 0.0,
            'taxCode' => TaxContext::mapTaxCode($product->getSalesTaxCode()),
            'weight' => $product->getWeight(),
            'unit' => $product->getUnit(),
        ];
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
        return number_format((float) $value, $unit === null ? 2 : LineDenomination::RATE_SCALE, '.', '');
    }

    protected function rawLineAmount(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }

    protected function nullableString(mixed $value): ?string
    {
        return TextInput::nullableString($value);
    }

    private function lineRestockEta(array $row): ?\DateTimeImmutable
    {
        $raw = \is_scalar($row['restock_eta'] ?? null) ? trim((string) $row['restock_eta']) : '';
        if ($raw === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $parsed === false ? null : $parsed;
    }

    private function nullableLotId(mixed $value): ?int
    {
        $id = \is_scalar($value) ? (int) $value : 0;

        return $id > 0 ? $id : null;
    }

    protected function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
