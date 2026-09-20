<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\InvoiceInventoryReservation;
use App\Enum\InvoiceStatus;

/**
 * Single source of truth for "which inventory bucket does an INVOICE status belong to, if any"
 * (#539 stage 3) — the invoice's half of the plan's authoritative status→bucket table, and the
 * counterpart to OrderInventoryBucketResolver.
 *
 * | Status      | Bucket     | Why                                                              |
 * |-------------|------------|------------------------------------------------------------------|
 * | `Draft`     | *none*     | written but not issued, entirely inert                            |
 * | `On Hold`   | *none*     | issued, waiting on an up-front payment that never arrived         |
 * | `Pending`   | `pending`  | issued, awaiting fulfilment                                       |
 * | `Processing`| `approved` | being fulfilled                                                   |
 * | `Completed` | `shipped`  | fulfilled; held indefinitely, since nothing decrements Starting   |
 * | `Cancelled` | *none*     | withdrawn; the quantity returns to the order's `sales_hold`       |
 *
 * `approved` -> `shipped` at Completed (2026-09-15,
 * `docs/plans/2026-09-15-shipment-approved-to-shipped-bucket.md`) is a relabeling, not a release:
 * `InventoryReservationReconciler::reconcile()` already recomputes an invoice's held quantity to
 * whatever this method returns for its CURRENT status on every write, so changing this one mapping
 * is the entire mechanism — no separate "transfer" code was needed. The two together never exceed
 * what was originally approved for a given invoice, and both are released by import/recount only
 * (see `ProductImportService::stampApprovedShippedReservationBaselines()`, one flag each).
 *
 * `On Hold` holding nothing is the whole point of the case existing: an abandoned card checkout has
 * an invoice, and it must not reserve stock. It still COUNTS as invoiced, which is what zeroes the
 * order's sales hold — two independent rules meeting at "an abandoned checkout holds nothing",
 * rather than an exclusion clause bolted onto one of them.
 *
 * Exact-match only, unlike the order resolver: Invoice::$status is a plain string column, but nothing
 * predates the enum behind it, so there are no 'approved'/'  Approved  ' spellings to absorb here.
 */
final class InvoiceInventoryBucketResolver
{
    /**
     * Public so InventoryRecalcCommand can pre-filter its invoice query without duplicating the
     * list, exactly as OrderInventoryBucketResolver::salesHoldStatuses() serves the order side.
     *
     * @return list<string>
     */
    public static function pendingStatuses(): array
    {
        return ['Pending'];
    }

    /**
     * Approved is released by import/recount only — this app has no mechanism that decrements
     * Starting Inventory on shipment (see ProductImportService::stampApprovedShippedReservationBaselines()).
     *
     * @return list<string>
     */
    public static function approvedStatuses(): array
    {
        return ['Processing'];
    }

    /**
     * Same release rule as `approved` — see that method's docblock. Split out because `Completed`
     * now targets its own bucket, not because the rule differs.
     *
     * @return list<string>
     */
    public static function shippedStatuses(): array
    {
        return ['Completed'];
    }

    /** @return 'pending'|'approved'|'shipped'|null */
    public static function bucketForStatus(string $status): ?string
    {
        return match (InvoiceStatus::tryFrom(trim($status))) {
            InvoiceStatus::Pending => InvoiceInventoryReservation::BUCKET_PENDING,
            InvoiceStatus::Processing => InvoiceInventoryReservation::BUCKET_APPROVED,
            InvoiceStatus::Completed => InvoiceInventoryReservation::BUCKET_SHIPPED,
            default => null,
        };
    }
}
