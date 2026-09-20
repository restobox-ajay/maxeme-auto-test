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
 * The one document of the three whose line-building code ran with no persisted identity to
 * protect until now — `InvoiceController::applyLineRows()` deleted and rebuilt every line on
 * every save, so its own rules were written with no "existing line" case in mind at all. Moving
 * onto the shared reconciler is what gives Invoice upsert-by-id in the first place; these
 * overrides reproduce the handful of rules its old rebuild-from-scratch code had that the base
 * class (lifted from Order, which always had identity to protect) does not share.
 */
final class InvoiceLineReconciler extends SellSideLineReconciler
{
    /**
     * A product row always takes the catalog's name — a typed `name` is never read at all once a
     * product is attached, reproducing `applyLineRows()`'s own unconditional
     * `$line->setName($product->getName())` exactly rather than letting Order's "a typed name
     * always wins" rule apply here too.
     *
     * @param array<string, mixed> $row
     */
    protected function resolveName(array $row, ?ProductCore $product, ?CostedDocumentLine $existing, bool $productJustAttached): string
    {
        if ($product !== null) {
            return $product->getName();
        }

        return $this->nullableString($row['name'] ?? null) ?? 'Custom line';
    }

    /** Every blank location backs onto the document's region, existing line or not — see the base class. */
    protected function resolveLocation(?string $postedLocation, bool $isExistingLine, ?string $documentFulfillmentRegion): ?string
    {
        return $postedLocation ?? $this->nullableString($documentFulfillmentRegion);
    }

    /**
     * `applyLineRows()`'s own guard: a typed cost is only read when it is_numeric — a pasted,
     * unparsable figure is not silently coerced to 0 (which the base class's plain `(float)` cast
     * would do), it falls back to the catalog exactly as a genuinely blank box does.
     */
    protected function resolveCost(?ProductCore $product, ?CostedDocumentLine $existing, bool $productJustAttached, string $costRaw, array $defaults): string
    {
        if ($costRaw !== '' && is_numeric($costRaw)) {
            return $this->decimal((float) $costRaw);
        }

        return $this->decimal($defaults['cost']);
    }

    /**
     * A typed tax code is normalized through TaxContext::mapTaxCode(), matching the base — but a
     * blank one falls back to the product's RAW `getSalesTaxCode()`, not the mapped default
     * `productLineDefaults()` uses everywhere else: `applyLineRows()`'s own snapshot block calls
     * `setTaxCode($product->getSalesTaxCode())` directly, never through TaxContext, and that is
     * reproduced here rather than unified.
     *
     * @param array<string, mixed> $row
     * @param array{cost: float, price: float, taxCode: ?string, weight: ?string, unit: ?string} $defaults
     *
     * @return array{sku: ?string, weight: ?string, unit: ?string, taxCode: ?string}
     */
    protected function resolveSnapshotFields(?ProductCore $product, ?CostedDocumentLine $existing, bool $productJustAttached, array $row, array $defaults): array
    {
        $fields = parent::resolveSnapshotFields($product, $existing, $productJustAttached, $row, $defaults);
        $fields['taxCode'] = $this->nullableString($row['tax_code'] ?? null) ?? $product?->getSalesTaxCode();

        return $fields;
    }

    /**
     * A blank price falls back to the product's `getOriginalPrice()` — already per base unit, so
     * unlike a typed figure it is never divided by the row's packaging factor (the same rule
     * {@see EstimateLineReconciler}'s own catalog seed follows, and `applyLineRows()`'s own
     * comment states directly) — not the lowest-id `ProductPricing` row `productLineDefaults()`
     * otherwise falls back to. A blank on a product-less custom line is `0`, the same figure
     * `applyLineRows()` used for the same case.
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

        if ($existing !== null && $existing->getPrice() !== null && LineDenomination::boxUntouched($row['price'] ?? null, $row['price_rendered'] ?? null)) {
            $price = (string) $existing->getPrice();
            $lineSubtotal = SalesDocumentMoney::intermediate($baseQuantity * (float) $price);

            return new ResolvedLinePrice($price, $price, $this->decimal($lineSubtotal), priced: true);
        }

        if ($priceRaw !== '') {
            $typed = (string) $lineWarnings->priceForRow($priceRaw, $rowIndex);
            $price = LineDenomination::toBasePrice($typed, $lineUnit, $baseUnit);
            $priceForColumn = $this->lineRate($price, $lineUnit);
        } else {
            $price = $product !== null ? (string) ($product->getOriginalPrice() ?? '0') : '0';
            $priceForColumn = $this->lineRate($price, $lineUnit);
        }

        $lineSubtotal = SalesDocumentMoney::intermediate($baseQuantity * (float) $price);

        return new ResolvedLinePrice($price, $priceForColumn, $this->decimal($lineSubtotal), priced: true);
    }
}
