<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\CostedDocumentLine;
use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use App\Service\SalesDocumentLineWarnings;
use App\Service\SalesDocumentMoney;
use App\Service\Uom\LineDenomination;

/**
 * The one document of the three that can go out with a line nobody has costed or priced yet — a
 * quote is allowed to say "TBD", and Order/Invoice never reach that state. Everything else about
 * reconciling a line (matching, units, boxUntouched, orphan removal) is exactly
 * {@see SellSideLineReconciler}'s — this overrides only the two seams that class exists to expose.
 */
final class EstimateLineReconciler extends SellSideLineReconciler
{
    /**
     * A row naming a real existing line is never blank, no matter how little of it was resubmitted —
     * a product-less custom line whose name field simply was not posted back must survive, not be
     * silently dropped and orphan-removed. Matches
     * `EstimateController::applyLinesFromRequest()`'s own `$existing === null &&`-gated check exactly;
     * Order/Invoice have no such carve-out (see the base class).
     *
     * @param array<string, mixed> $row
     */
    protected function isBlankLine(array $row, ?CostedDocumentLine $existing): bool
    {
        return $existing === null && parent::isBlankLine($row, $existing);
    }

    /**
     * A just-attached product's name always wins (the re-snapshot every other catalog field also
     * takes). Otherwise a TYPED name only applies to a blank-product row — a row that already
     * points at an unchanged product ignores a typed name entirely, exactly as
     * `EstimateController::applyLinesFromRequest()` always has. Failing both, the line keeps
     * whatever name it already stored, falling back to 'Custom line' only when that is genuinely
     * empty too (a brand new, product-less, nameless row).
     *
     * @param array<string, mixed> $row
     */
    protected function resolveName(array $row, ?ProductCore $product, ?CostedDocumentLine $existing, bool $productJustAttached): string
    {
        if ($productJustAttached && $product !== null) {
            return $product->getName();
        }

        $typedName = $this->nullableString($row['name'] ?? null) ?? '';
        if ($product === null && $typedName !== '') {
            return $typedName;
        }

        $currentName = $existing?->getName() ?? '';

        return $currentName !== '' ? $currentName : 'Custom line';
    }

    /**
     * Three steps, in order, matching `EstimateController::applyLinesFromRequest()`'s own two
     * separate blocks exactly: start from whatever the line already stored (null for a new one);
     * a just-attached product re-snapshots every field UNCONDITIONALLY, the same moment cost does;
     * then, only for a field the post actually carries at all (`array_key_exists` — a narrower POST
     * must not read as "clear this"), a posted value wins, else the catalog, else whatever the first
     * two steps left. Tax code deliberately reads the product's RAW `getSalesTaxCode()`, not the
     * base class's `TaxContext::mapTaxCode()`-normalized default — reproducing the controller's own
     * exact rule, not quietly changing it.
     *
     * @param array<string, mixed> $row
     * @param array{cost: float, price: float, taxCode: ?string, weight: ?string, unit: ?string} $defaults
     *
     * @return array{sku: ?string, weight: ?string, unit: ?string, taxCode: ?string}
     */
    protected function resolveSnapshotFields(?ProductCore $product, ?CostedDocumentLine $existing, bool $productJustAttached, array $row, array $defaults): array
    {
        return [
            'sku' => $this->cascadedSnapshotField($existing?->getSku(), $product?->getSku(), $productJustAttached, $row, 'sku'),
            'weight' => $this->cascadedSnapshotField($existing?->getWeight(), $product?->getWeight(), $productJustAttached, $row, 'weight'),
            'unit' => $this->cascadedSnapshotField($existing?->getUnit(), $product?->getUnit(), $productJustAttached, $row, 'unit'),
            'taxCode' => $this->cascadedSnapshotField($existing?->getTaxCode(), $product?->getSalesTaxCode(), $productJustAttached, $row, 'tax_code'),
        ];
    }

    /** @param array<string, mixed> $row */
    private function cascadedSnapshotField(?string $existingValue, ?string $productValue, bool $productJustAttached, array $row, string $rowKey): ?string
    {
        $value = $existingValue;
        if ($productJustAttached) {
            $value = $productValue;
        }
        if (\array_key_exists($rowKey, $row)) {
            $value = $this->nullableString($row[$rowKey] ?? null) ?? $productValue ?? $value;
        }

        return $value;
    }

    /**
     * A blank cost on a line whose product did NOT just change stays whatever the line already
     * stored — never re-derived from today's catalog. Only when the product was just attached (a
     * brand new row, or an existing one just re-pointed at a different product) does the catalog
     * cost apply, the same moment a name/SKU/weight/tax-code re-snapshot happens for the same
     * reason. A blank on a genuinely new row with no product at all is 0 — there is no catalog to
     * ask and no existing line to preserve.
     */
    protected function resolveCost(?ProductCore $product, ?CostedDocumentLine $existing, bool $productJustAttached, string $costRaw, array $defaults): string
    {
        if ($costRaw !== '') {
            return $this->decimal((float) $costRaw);
        }

        if ($productJustAttached) {
            return $this->decimal($defaults['cost']);
        }

        if ($existing !== null) {
            return $existing->getCost();
        }

        return $this->decimal(0.0);
    }

    /**
     * A blank price is a deliberate "TBD" — never backfilled from the catalog the way SKU/weight/
     * unit/tax code are — with exactly one exception: the moment a product is first attached to the
     * row, seeded from the catalog's own price so a no-JS save (or any scripted post) does not mint
     * a permanently-unpriced line the browser's own product-picker would have priced. Clearing it on
     * any LATER save still means TBD; only the just-attached moment seeds it.
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

        $priceCameFromTheCatalog = false;
        if ($priceRaw === '' && $productJustAttached && $product !== null) {
            $priceRaw = (string) ($product->getOriginalPrice() ?? '');
            $priceCameFromTheCatalog = true;
        }

        if ($existing !== null && $existing->getPrice() !== null && LineDenomination::boxUntouched($row['price'] ?? null, $row['price_rendered'] ?? null)) {
            $price = (string) $existing->getPrice();
            $lineSubtotal = SalesDocumentMoney::intermediate($baseQuantity * (float) $price);

            return new ResolvedLinePrice($price, $price, $this->decimal($lineSubtotal), priced: true);
        }

        if ($priceRaw !== '') {
            $typed = (string) $lineWarnings->priceForRow($priceRaw, $rowIndex);
            // The catalog's own figure is already per base unit and must not be divided by the
            // row's packaging factor — the same rule EstimateController::applyLinesFromRequest()
            // stated; only a figure the admin actually typed goes through the unit conversion.
            $price = $priceCameFromTheCatalog ? $typed : LineDenomination::toBasePrice($typed, $lineUnit, $baseUnit);
            $priceForColumn = $this->lineRate($price, $lineUnit);
            $lineSubtotal = SalesDocumentMoney::intermediate($baseQuantity * (float) $price);

            return new ResolvedLinePrice($price, $priceForColumn, $this->decimal($lineSubtotal), priced: true);
        }

        return new ResolvedLinePrice(null, null, null, priced: false);
    }
}
