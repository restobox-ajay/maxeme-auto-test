<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\OrderInventoryReservation;
use App\Entity\Warehouse;
use App\Enum\SalesOrderStatus;

/**
 * Single source of truth for "which inventory bucket does a SALES ORDER status belong to, if any" —
 * shared by InventoryReservationReconciler (incremental reconciliation) and InventoryRecalcCommand
 * (hourly recompute from source of truth), so the two paths can never disagree.
 *
 * The invoice's side of the same question is InvoiceInventoryBucketResolver. Together the two are
 * the plan's authoritative status→bucket table, expressed once each.
 *
 * ## One rule, not six (#539 stage 3)
 *
 * An order holds `sales_hold` on its UNINVOICED quantity — (ordered − invoiced) — for any status
 * from Approved onward, and nothing at all for Draft and Void. `Invoiced` and `Closed` therefore
 * need no case of their own: they still name the bucket, and their uninvoiced quantity is zero by
 * definition, so the hold falls to zero on its own. Expressing that as "no bucket for Invoiced" as
 * well would be a second rule that could disagree with the first — and it is the quantity, not the
 * status, that says how much is still owed.
 *
 * Void is excluded rather than falling out of the quantity rule, because a voided order's lines are
 * still there and still uninvoiced: voiding is the deliberate act that says the goods are no longer
 * owed, so it must release outright.
 *
 * A status this enum does not know — the quote-era 'Waiting for Quote'/'Accepted Quotes' strings,
 * the pre-enum 'APPROVED' strays, an order row that predates the stage 2 migration and still says
 * 'Processing' — holds nothing. That is deliberate: a row nobody can now interpret must not quietly
 * reserve stock.
 */
final class OrderInventoryBucketResolver
{
    /**
     * The statuses that hold `sales_hold`, lower-cased.
     *
     * Public so InventoryRecalcCommand can pre-filter its order query to exactly these without
     * duplicating the list. Derived from the enum rather than typed out, so a case added to
     * SalesOrderStatus cannot be forgotten here — it will hold sales hold unless it is Draft or
     * Void, which is the rule above rather than a list to maintain.
     *
     * @return list<string>
     */
    public static function salesHoldStatuses(): array
    {
        return array_values(array_map(
            static fn (SalesOrderStatus $status): string => strtolower($status->value),
            array_filter(
                SalesOrderStatus::cases(),
                static fn (SalesOrderStatus $status): bool => $status->isApprovedOrLater(),
            ),
        ));
    }

    /** @return 'sales_hold'|null */
    public static function bucketForStatus(string $status): ?string
    {
        $enum = SalesOrderStatus::tryFrom(trim($status))
            ?? self::caseInsensitiveCase($status);

        return $enum !== null && $enum->isApprovedOrLater()
            ? OrderInventoryReservation::BUCKET_SALES_HOLD
            : null;
    }

    /**
     * SalesOrder::$status is a plain string column and always has been, so its casing is only as
     * reliable as whatever wrote it — 'approved' and '  Approved  ' both occur. tryFrom() is exact,
     * so the fallback matches on the trimmed, lower-cased value.
     */
    private static function caseInsensitiveCase(string $status): ?SalesOrderStatus
    {
        $normalized = strtolower(trim($status));

        foreach (SalesOrderStatus::cases() as $case) {
            if (strtolower($case->value) === $normalized) {
                return $case;
            }
        }

        return null;
    }

    /**
     * The warehouse a line's stock comes out of.
     *
     * A line's own location wins if set (admin-created/edited documents record region per line);
     * otherwise falls back to the document header's region (checkout/quote-conversion documents only
     * ever set the header field). Shared by InventoryReservationReconciler, InventoryRecalcCommand
     * and AdminOrderStockValidator so every one of them resolves a line identically.
     *
     * Documents name a REGION — `location` and AbstractSalesDocument::$fulfillmentRegion are both
     * region names, which is what a customer picks — and stock lives in a WAREHOUSE, so the map
     * handed in is region-name-to-warehouse (#546). Building it is
     * WarehouseFulfillmentRegionService::warehousesByLowerRegionName(); it is passed in rather than
     * looked up here because callers resolve one line at a time and the map is one query.
     *
     * @param array<string, Warehouse> $warehousesByLowerRegionName keyed by strtolower(trim(region name))
     */
    public static function resolveLineWarehouse(?string $lineLocation, ?string $orderRegionName, array $warehousesByLowerRegionName): ?Warehouse
    {
        $regionName = trim((string) $lineLocation) ?: trim((string) $orderRegionName);
        if ($regionName === '') {
            return null;
        }

        return $warehousesByLowerRegionName[strtolower($regionName)] ?? null;
    }
}
