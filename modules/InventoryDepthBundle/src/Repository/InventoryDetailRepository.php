<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Repository;

use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\QuantityScale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;

/**
 * @extends ServiceEntityRepository<InventoryDetail>
 */
class InventoryDetailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryDetail::class);
    }

    /**
     * The only way a detail row is created. Uniqueness of
     * `(product, warehouse, location, lot, serial, status)` is upheld here by lookup rather than by
     * the database, because the index that expresses it needs `COALESCE(...)` to collapse NULLs and
     * Doctrine's mapping layer cannot emit that — so it exists in a migrated database but not in
     * the schema either test suite builds from entity metadata. Enforcing it in the one place rows
     * are made is what makes the two schemas behave identically.
     */
    public function findOrCreate(
        ProductCore $product,
        Warehouse $warehouse,
        ?WarehouseLocation $location,
        ?InventoryLot $lot,
        ?string $serial,
        string $status,
        ?\DateTimeImmutable $expiry = null,
    ): InventoryDetail {
        $row = $this->findExisting($product, $warehouse, $location, $lot, $serial, $status, $expiry);
        if ($row instanceof InventoryDetail) {
            return $row;
        }

        $row = (new InventoryDetail())
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setLocation($location)
            ->setLot($lot)
            ->setSerial(($serial === null || $serial === '') ? null : $serial)
            ->setStatus($status);

        // Only when there is no lot: InventoryDetail::setExpiry() refuses a date alongside one, and
        // a lot row's expiry already lives on the lot set above.
        if ($lot === null) {
            $row->setExpiry($expiry);
        }

        $this->getEntityManager()->persist($row);

        return $row;
    }

    /**
     * The same lookup without the create.
     *
     * Used by StockMovementService::assertSourcesCanCover() to replay a request against what is
     * actually on disk before opening a transaction. That check is why `apply()` can still resolve
     * both sides through findOrCreate(): a source that does not exist, or does not hold enough, has
     * already been refused by then, so the create branch is only ever reached for a genuine
     * destination.
     */
    public function findExisting(
        ProductCore $product,
        Warehouse $warehouse,
        ?WarehouseLocation $location,
        ?InventoryLot $lot,
        ?string $serial,
        string $status,
        ?\DateTimeImmutable $expiry = null,
    ): ?InventoryDetail {
        $serial = ($serial === null || $serial === '') ? null : $serial;
        // Meaningless once there is a lot — that row's expiry lives on the lot, and d.expiry is
        // always NULL for it (InventoryDetail::setExpiry() refuses the alternative), so a lot
        // lookup never keys on this and cannot be split by a caller passing one anyway.
        $expiry = $lot === null ? $expiry : null;

        $existing = $this->createQueryBuilder('d')
            ->andWhere('d.product = :product')->setParameter('product', $product)
            ->andWhere('d.warehouse = :warehouse')->setParameter('warehouse', $warehouse)
            ->andWhere($location === null ? 'd.location IS NULL' : 'd.location = :location')
            ->andWhere($lot === null ? 'd.lot IS NULL' : 'd.lot = :lot')
            ->andWhere($serial === null ? 'd.serial IS NULL' : 'd.serial = :serial')
            ->andWhere('d.status = :status')->setParameter('status', $status)
            ->andWhere($expiry === null ? 'd.expiry IS NULL' : 'd.expiry = :expiry')
            ->setMaxResults(1);

        if ($location !== null) {
            $existing->setParameter('location', $location);
        }
        if ($lot !== null) {
            $existing->setParameter('lot', $lot);
        }
        if ($serial !== null) {
            $existing->setParameter('serial', $serial);
        }
        if ($expiry !== null) {
            // A plain 'Y-m-d' string, not the DateTimeImmutable itself — the same choice
            // ReceivingService::resolveLot() makes against `inventory_lot.expiry`, and for the same
            // reason: Doctrine's default parameter-type inference for a bare DateTimeInterface binds
            // it as a datetime, which a `date_immutable` column never equals however genuinely equal
            // the two dates are.
            $existing->setParameter('expiry', $expiry->format('Y-m-d'));
        }

        $row = $existing->getQuery()->getOneOrNullResult();

        return $row instanceof InventoryDetail ? $row : null;
    }

    /**
     * Takes `$quantity` off `$detail` if — and only if — it is there, as one conditional statement.
     *
     * `UPDATE ... WHERE id = :id AND quantity >= :n` returning 0 affected rows is what turns a
     * concurrent over-draw into "someone got there first" instead of a `CHECK (quantity >= 0)`
     * violation in a picker's face. Two pickers each taking 20 from a row of 25: one succeeds, the
     * other is told the stock moved, and the row never passes through −15.
     *
     * Returns false when the row did not hold enough. The caller must not have written anything it
     * cannot undo before calling this.
     */
    public function decrement(InventoryDetail $detail, string|int|float $quantity): bool
    {
        // A decimal quantity since `QuantityType` became a decimal type. Bound as the canonical
        // string the column holds, so `NUMERIC(14, 4)`'s own comparison does the `>= :qty` test at
        // the column's precision rather than against a truncated integer.
        $quantity = QuantityScale::canonical($quantity);

        if ((float) $quantity <= 0.0 || $detail->getId() === null) {
            return false;
        }

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE inventory_detail SET quantity = quantity - :qty, updated_at = :now WHERE id = :id AND quantity >= :qty',
            ['qty' => $quantity, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $detail->getId()],
        );

        if ($affected !== 1) {
            return false;
        }

        // Read the value back rather than computing it as (what we had in memory − what we took).
        //
        // The subtraction looks equivalent and is not. The managed entity was loaded before this
        // transaction began — assertSourcesCanCover() does the first read — and Doctrine does not
        // refresh the scalar fields of an already-managed entity on a later query, so the in-memory
        // number can be older than the row. The UPDATE above is relative and therefore correct
        // whatever the row held; the value we put on the entity then becomes an ABSOLUTE write at
        // the next flush, because setQuantity() puts the field in the changeset. A stale absolute
        // write over a correct relative one is a lost update: it silently erases whatever another
        // request committed to the row in between.
        //
        // One primary-key SELECT inside the transaction, which is also the only thing that makes
        // this method's guarantee — "the row never passes through a negative" — survive the flush
        // that follows it.
        $current = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT quantity FROM inventory_detail WHERE id = :id',
            ['id' => $detail->getId()],
        );

        $detail->setQuantity((string) $current)->touch();

        return true;
    }

    /**
     * Available, quantity-positive rows with no lot but a real expiry of their own (#795) — a
     * serialised or untracked product whose date lives on `d.expiry` rather than on a batch.
     *
     * `ExpireLotsCommand`'s counterpart to `InventoryLotRepository::expiringThrough()`. Deliberately
     * a separate query rather than folding this into that one: a lot row and a lot-less row are
     * never both real for the same detail row (`InventoryDetail::setExpiry()` refuses to let them
     * be), so this is genuinely a disjoint set, not an overlapping one, and returning `InventoryDetail`
     * rows directly rather than lots is the correct shape here — there is no lot to group them by.
     *
     * @return list<InventoryDetail>
     */
    public function expiringWithNoLot(\DateTimeImmutable $through): array
    {
        /** @var list<InventoryDetail> $rows */
        $rows = $this->createQueryBuilder('d')
            ->andWhere('d.lot IS NULL')
            ->andWhere('d.expiry IS NOT NULL')
            ->andWhere('d.expiry <= :through')->setParameter('through', $through->format('Y-m-d'))
            ->andWhere('d.status = :status')->setParameter('status', InventoryDetail::STATUS_AVAILABLE)
            ->andWhere('d.quantity > 0')
            ->orderBy('d.expiry', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The rows actually holding this lot's available stock in one warehouse, in bin pick order
     * (lowest `sort_key` first — undated, because a lot's own expiry is already fixed, so FEFO has
     * nothing left to break the tie with; bin order is the only remaining ambiguity a lot can still
     * be split across).
     *
     * ShipmentService::ship() is the caller (`docs/plans/2026-09-14-shipment-dispatch.md`): a
     * shipment line inherits its lot from the invoice line rather than choosing one, but the lot's
     * own stock may still be split across bins, and picking a BIN for an already-chosen lot is a
     * mechanical step, not a second business decision — the same "no UI, no pick list, no operator
     * choice" reasoning AutomaticSourcePicker::plan() already rests on, scoped one dimension
     * narrower to a single lot instead of a whole product.
     *
     * Scoped to `$warehouse` because a shipment ships from one warehouse and must not draw a lot's
     * stock sitting in another one out from under it.
     *
     * @return list<InventoryDetail>
     */
    public function availableRowsForLot(InventoryLot $lot, Warehouse $warehouse): array
    {
        /** @var list<InventoryDetail> $rows */
        $rows = $this->createQueryBuilder('d')
            ->leftJoin('d.location', 'loc')
            ->addSelect('CASE WHEN loc.sortKey IS NULL THEN 0 ELSE loc.sortKey END AS HIDDEN pickOrder')
            ->andWhere('d.lot = :lot')->setParameter('lot', $lot)
            ->andWhere('d.warehouse = :warehouse')->setParameter('warehouse', $warehouse)
            ->andWhere('d.status = :status')->setParameter('status', InventoryDetail::STATUS_AVAILABLE)
            ->andWhere('d.quantity > 0')
            ->orderBy('pickOrder', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The single row holding this available serial in this warehouse, or null when it is not
     * sitting `available` there — already shipped, sitting in another warehouse, or naming no real
     * row at all.
     *
     * A serial identifies one physical unit (rule 3), so unlike `availableRowsForLot()` there is
     * never more than one row to find, and nothing to sum across bins.
     */
    public function findAvailableSerial(ProductCore $product, Warehouse $warehouse, string $serial): ?InventoryDetail
    {
        $row = $this->createQueryBuilder('d')
            ->andWhere('d.product = :product')->setParameter('product', $product)
            ->andWhere('d.warehouse = :warehouse')->setParameter('warehouse', $warehouse)
            ->andWhere('d.serial = :serial')->setParameter('serial', $serial)
            ->andWhere('d.status = :status')->setParameter('status', InventoryDetail::STATUS_AVAILABLE)
            ->andWhere('d.quantity > 0')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row instanceof InventoryDetail ? $row : null;
    }

    /**
     * Every serial currently sitting `available` for this product in this warehouse, oldest first —
     * the manual picker `ShipmentAllocationPlanner` offers for a serial-tracked line
     * (`docs/plans/2026-09-14-shipment-dispatch.md`'s step-2 revision). Never auto-assigned: the
     * owner's ruling is that a serial is always a human's dropdown pick, unlike a lot's quantity
     * split, which defaults to FEFO.
     *
     * Oldest-received-first (`d.id ASC`) rather than expiry-ordered like `pickableRows()` — a
     * serialised unit's own expiry, if any, rides on its lot, and this query has no lot to sort by;
     * insertion order is the only ordering available and it is at least stable.
     *
     * @return list<string>
     */
    public function availableSerialsFor(ProductCore $product, Warehouse $warehouse): array
    {
        /** @var list<string> $serials */
        $serials = $this->createQueryBuilder('d')
            ->select('d.serial')
            ->andWhere('d.product = :product')->setParameter('product', $product)
            ->andWhere('d.warehouse = :warehouse')->setParameter('warehouse', $warehouse)
            ->andWhere('d.status = :status')->setParameter('status', InventoryDetail::STATUS_AVAILABLE)
            ->andWhere('d.serial IS NOT NULL')
            ->andWhere('d.quantity > 0')
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return $serials;
    }

    /**
     * What SHOULD be sitting in available detail rows for one (product, warehouse), computed
     * purely from `product_inventory`'s own bucket columns — the left-hand side of the invariant
     * `StockController::byProduct()`'s reconciliation column checks against `availableTotal()`, and
     * the same figure `InventoryModeSwitcher::toDimensional()` uses to open exactly the gap a
     * warehouse's detail rows are missing rather than the whole `quantity` again on top of
     * whatever real movement activity (Stock Found, a write-off, ...) already shaped before the
     * product ever switched — see that method's own docblock for why the difference matters.
     *
     * Not rounded to the product's own unit precision — round at MAX_DECIMALS. StockController
     * rounds its own copy for display; a caller computing a gap to actually open a movement with
     * wants the exact figure.
     */
    public function heldAvailableTotal(ProductCore $product, Warehouse $warehouse, ProductInventory $inventory): string
    {
        $held = bcadd($inventory->getQuantity(), $inventory->getReceivedQuantity(), QuantityScale::MAX_DECIMALS);
        $held = bcadd($held, $inventory->getTransferInQuantity(), QuantityScale::MAX_DECIMALS);
        $held = bcsub($held, $inventory->getTransferOutQuantity(), QuantityScale::MAX_DECIMALS);
        $held = bcsub($held, $inventory->getWriteOffQuantity(), QuantityScale::MAX_DECIMALS);
        $held = bcadd($held, $inventory->getQuarantineQuantity(), QuantityScale::MAX_DECIMALS);

        return bcsub($held, $this->soldTotal($product, $warehouse), QuantityScale::MAX_DECIMALS);
    }

    /**
     * `SUM(quantity) WHERE status = 'available'` for one product in one warehouse — the right-hand
     * side of the invariant, and the number StockMovementService writes back into
     * `product_inventory.quantity`.
     */
    public function availableTotal(ProductCore $product, Warehouse $warehouse): string
    {
        return QuantityScale::canonical((string) $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.quantity), 0)')
            ->andWhere('d.product = :product')->setParameter('product', $product)
            ->andWhere('d.warehouse = :warehouse')->setParameter('warehouse', $warehouse)
            ->andWhere('d.status = :status')->setParameter('status', InventoryDetail::STATUS_AVAILABLE)
            ->getQuery()
            ->getSingleScalarResult());
    }

    /**
     * `SUM(quantity) WHERE lot = :lot AND status = 'available'` — the identical subtraction
     * availableTotal() already does for a whole product+warehouse, scoped one dimension narrower to
     * a single InventoryLot (2026-09-14 lot/serial/expiry plan).
     *
     * No warehouse filter: a lot's identity is its row id, not a (product, warehouse) pair — nothing
     * on InventoryLot names a warehouse, and its detail rows may in principle span more than one, so
     * "how much of this lot is left" sums across every warehouse it is actually sitting in rather
     * than silently answering for only one of them.
     */
    public function availableForLot(InventoryLot $lot): string
    {
        return QuantityScale::canonical((string) $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.quantity), 0)')
            ->andWhere('d.lot = :lot')->setParameter('lot', $lot)
            ->andWhere('d.status = :status')->setParameter('status', InventoryDetail::STATUS_AVAILABLE)
            ->getQuery()
            ->getSingleScalarResult());
    }

    /**
     * Every non-zero row this lot is currently on, whatever its status — "where is it now" for a
     * recall trace (#725), not just what is sellable.
     *
     * Deliberately wider than {@see self::availableForLot()}: a recall needs to find units sitting in
     * quarantine or on hold too, not only the sellable ones, so nothing here filters by status.
     * Zero-quantity rows ARE excluded — a row a lot has been fully drawn down from is history, not
     * a place it currently is.
     *
     * @return list<InventoryDetail>
     */
    public function rowsForLot(InventoryLot $lot): array
    {
        /** @var list<InventoryDetail> $rows */
        $rows = $this->createQueryBuilder('d')
            ->innerJoin('d.warehouse', 'w')->addSelect('w')
            ->leftJoin('d.location', 'loc')->addSelect('loc')
            ->andWhere('d.lot = :lot')->setParameter('lot', $lot)
            ->andWhere('d.quantity <> 0')
            ->orderBy('w.name', 'ASC')
            ->addOrderBy('d.status', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Units written off: damaged, expired, scrapped or lost (#581).
     *
     * Stock the business no longer has or can no longer sell, and will not get back. Still inside
     * `quantity` because that figure comes from the client's own system and it has not been told,
     * so availability has to take it off.
     *
     * A cached sum, like every other bucket, so it cannot drift from the rows that define it.
     */
    public function writeOffTotal(ProductCore $product, Warehouse $warehouse): string
    {
        return $this->statusTotal($product, $warehouse, InventoryDetail::writeOffStatuses());
    }

    /**
     * Units held pending a human decision: quarantined, or returned and not yet inspected (#581).
     *
     * Physically present and countable, unlike a write-off, but not sellable until somebody rules
     * on them — and they may well come back. A return is quarantine in substance: goods on the
     * shelf that nobody has yet said are fit to sell again.
     */
    public function quarantineTotal(ProductCore $product, Warehouse $warehouse): string
    {
        return $this->statusTotal($product, $warehouse, InventoryDetail::quarantineStatuses());
    }

    /**
     * Units billed to a customer: `sold` (#581).
     *
     * In NO bucket, deliberately — the invoice holds them in `pending`/`approved`. This exists for
     * the one caller that has to state the whole invariant rather than compute availability:
     * app:inventory-depth:detail-check.
     */
    public function soldTotal(ProductCore $product, Warehouse $warehouse): string
    {
        return $this->statusTotal($product, $warehouse, InventoryDetail::soldStatuses());
    }

    /**
     * How many units are physically standing in one bin, whatever their status (#590).
     *
     * Every status counts, not just `available`: a quarantined carton and a damaged one are still
     * in the bin, and closing the bin under them strands them exactly as surely as closing it under
     * sellable stock. Rows at 0 are history and excluded. Rows that are `sold`, `scrapped` or `lost`
     * have already dropped their location by rule 3, so they cannot reach this sum at all.
     *
     * This is the number bin closure refuses on, and the number the bin list shows in its Contents
     * column so the refusal is never a surprise.
     */
    public function unitsInLocation(WarehouseLocation $location): string
    {
        return QuantityScale::canonical((string) $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.quantity), 0)')
            ->andWhere('d.location = :location')->setParameter('location', $location)
            ->andWhere('d.quantity > 0')
            ->getQuery()
            ->getSingleScalarResult());
    }

    /**
     * unitsInLocation() for a whole page of bins in one query, keyed by `warehouse_location.id`.
     *
     * A bin absent from the result holds nothing. One query rather than one per row because the bin
     * list renders up to 500 of them.
     *
     * @param list<WarehouseLocation> $locations
     *
     * @return array<int, int>
     */
    public function unitsByLocation(array $locations): array
    {
        $ids = [];
        foreach ($locations as $location) {
            $id = $location->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var list<array{loc: int|string, units: int|string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.location) AS loc, COALESCE(SUM(d.quantity), 0) AS units')
            ->andWhere('d.location IN (:ids)')->setParameter('ids', $ids)
            ->andWhere('d.quantity > 0')
            ->groupBy('d.location')
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['loc']] = (int) $row['units'];
        }

        return $totals;
    }

    /**
     * @param list<string> $statuses
     */
    private function statusTotal(ProductCore $product, Warehouse $warehouse, array $statuses): string
    {
        return QuantityScale::canonical((string) $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.quantity), 0)')
            ->andWhere('d.product = :product')->setParameter('product', $product)
            ->andWhere('d.warehouse = :warehouse')->setParameter('warehouse', $warehouse)
            ->andWhere('d.status IN (:statuses)')->setParameter('statuses', $statuses)
            ->getQuery()
            ->getSingleScalarResult());
    }

    /**
     * `SUM(quantity) WHERE status = 'in_transit'` for one product at one warehouse — the units this
     * warehouse has put on a truck and nobody has receipted yet (#587).
     *
     * ## Read the note below first: this defines NO bucket
     *
     * A method by this name used to exist and was deleted by #584 precisely because it defined
     * `product_inventory.transfer_out_quantity`, which is the bug that issue fixed. It is back for
     * the one caller the deletion note anticipated — WarehouseOpsBundle's transfer drift check,
     * which compares these rows against the transfer DOCUMENTS and reports where they disagree.
     * Nothing here is cached anywhere, and nothing may cache it: the moment a bucket is set from
     * this sum, the bucket falls back to zero the instant a receipt consumes the rows, and #584
     * happens again.
     *
     * Owned by the warehouse that DISPATCHED the stock, not the one expecting it: that is where
     * TransferOrderService::dispatch() puts the row, and it is what keeps in-flight units locatable
     * rather than nowhere.
     */
    public function inTransitTotal(ProductCore $product, Warehouse $warehouse): string
    {
        return $this->statusTotal($product, $warehouse, [InventoryDetail::STATUS_IN_TRANSIT]);
    }

    // There WAS deliberately no inTransitTotal() here between #584 and #587, and the reasoning is
    // worth keeping because it is what the method above must never be used for again.
    //
    // It existed for one caller: StockMovementService, which wrote `product_inventory
    // .transfer_out_quantity` as a cached sum of the `in_transit` rows. That definition is the bug
    // #584 fixed. The rows are consumed by the receipt, so the bucket dropped back to zero when the
    // goods landed and the source warehouse sprang back to full availability for stock standing in
    // another building.
    //
    // `transfer_out` and `transfer_in` are now cumulative and derived from `transfer_order_line` by
    // WarehouseOpsBundle — the document, not the rows the document happened to leave behind.
    //
    // The `in_transit` rows themselves are unchanged and still the record of what is physically on
    // a truck. What followed from their no longer feeding a bucket is that nothing cross-checked
    // them against the transfer documents, which is the gap #587 closes — from WarehouseOpsBundle,
    // which owns both sides, and by REPORTING rather than by caching this sum into anything.

    /**
     * The rows an operator picks from BY NAME when the adjustment screen has to ask which goods
     * these are (#585).
     *
     * Two cases reach here, and they are the two the reason-first screen cannot answer for itself:
     *
     *  - the product's TrackingPolicy has `track_out = true`, so a human names the lot or the serial
     *    the units come off. The alternative — letting AutomaticSourcePicker choose — is precisely
     *    what a product declares tracked-outbound to prevent;
     *  - the reason takes stock out of a status that is not `available` (releasing a hold), where
     *    there is no picker at all and never should be. A hold is always against particular goods,
     *    and "release five of the quarantined ones, you pick which" is not a thing a warehouse says.
     *
     * Same ordering as pickableRows(): earliest expiry first, then lowest bin sort_key, undated
     * batches last. Not because anything picks automatically here, but because the first row an
     * operator sees should be the one they most likely mean — a spoilage entry whose most urgent
     * batch was eleventh in the list would be a worse screen than the movement editor it replaces.
     *
     * Rows at quantity 0 are excluded: they are history, and history is not a source. Rows in OTHER
     * warehouses are included, with the warehouse in the label, because an adjustment names goods
     * rather than a site and filtering to one warehouse first would be a second question the reason
     * never asked.
     *
     * @return list<InventoryDetail>
     */
    public function namedSourceRows(ProductCore $product, string $status): array
    {
        /** @var list<InventoryDetail> $rows */
        $rows = $this->createQueryBuilder('d')
            ->leftJoin('d.lot', 'l')->addSelect('l')
            ->leftJoin('d.location', 'loc')->addSelect('loc')
            ->innerJoin('d.warehouse', 'w')->addSelect('w')
            ->addSelect('COALESCE(l.expiry, d.expiry) AS HIDDEN effectiveExpiry')
            ->addSelect('CASE WHEN COALESCE(l.expiry, d.expiry) IS NULL THEN 1 ELSE 0 END AS HIDDEN undated')
            ->addSelect('CASE WHEN loc.sortKey IS NULL THEN 0 ELSE loc.sortKey END AS HIDDEN pickOrder')
            ->andWhere('d.product = :product')->setParameter('product', $product)
            ->andWhere('d.status = :status')->setParameter('status', $status)
            ->andWhere('d.quantity > 0')
            ->orderBy('undated', 'ASC')
            ->addOrderBy('effectiveExpiry', 'ASC')
            ->addOrderBy('pickOrder', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Rows that can answer a withdrawal of this product from this warehouse, in the order the
     * automatic picker takes them: **earliest expiry first, then lowest bin sort_key**. Lots with no
     * expiry sort last — an undated batch is never more urgent than a dated one.
     *
     * No UI, no pick list, no operator choice. Efficient picking is #552; this only needs deduction
     * not to lie.
     *
     * @return list<InventoryDetail>
     */
    public function pickableRows(ProductCore $product, Warehouse $warehouse): array
    {
        /** @var list<InventoryDetail> $rows */
        $rows = $this->createQueryBuilder('d')
            ->leftJoin('d.lot', 'l')
            ->leftJoin('d.location', 'loc')
            // DQL cannot ORDER BY an expression that is not in the SELECT — nor, it turns out, by a
            // function call at all ("Expected known function" out of the DQL parser for anything past
            // a bare state field path) — so every sort key, COALESCE included, is selected HIDDEN and
            // ordered by alias.
            ->addSelect('COALESCE(l.expiry, d.expiry) AS HIDDEN effectiveExpiry')
            ->addSelect('CASE WHEN COALESCE(l.expiry, d.expiry) IS NULL THEN 1 ELSE 0 END AS HIDDEN undated')
            ->addSelect('CASE WHEN loc.sortKey IS NULL THEN 0 ELSE loc.sortKey END AS HIDDEN pickOrder')
            ->andWhere('d.product = :product')->setParameter('product', $product)
            ->andWhere('d.warehouse = :warehouse')->setParameter('warehouse', $warehouse)
            ->andWhere('d.status = :status')->setParameter('status', InventoryDetail::STATUS_AVAILABLE)
            ->andWhere('d.quantity > 0')
            // An undated batch is never more urgent than a dated one, so it sorts last.
            ->orderBy('undated', 'ASC')
            ->addOrderBy('effectiveExpiry', 'ASC')
            ->addOrderBy('pickOrder', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
