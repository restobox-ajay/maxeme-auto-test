<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Movement;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\QuantityScale;
use InventoryDepthBundle\Entity\InventoryAdjustmentReason;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovement;
use InventoryDepthBundle\Entity\InventoryMovementGroup;

/**
 * One operation, ready to apply: the reason, the actor, the reference, and the quantities to move.
 *
 * Built by whoever is asking (an admin form, a command, a later bundle) and handed to
 * StockMovementService::apply() whole, so that a group and its movements are never half-written.
 * Deliberately a plain value object with no entity manager and no persistence of its own.
 */
final class MovementRequest
{
    /** @var list<array{product: ProductCore, from: ?DetailKey, to: ?DetailKey, quantity: string, reverses: ?InventoryMovement}> */
    private array $lines = [];

    private function __construct(
        public readonly string $type,
        public readonly string $clientOperationId,
        public readonly ?string $reason,
        public readonly ?string $actor,
        public readonly ?string $reference,
        public readonly \DateTimeImmutable $occurredAt,
        /**
         * The stored reason row, when one classifies this operation (#585).
         *
         * Beside `$reason`, which stays the free-text note, and null for everything that is not an
         * adjustment — an opening balance, a transfer, a cycle count. See
         * InventoryMovementGroup::$adjustmentReason for why the two coexist rather than one
         * replacing the other.
         */
        public readonly ?InventoryAdjustmentReason $adjustmentReason = null,
    ) {
    }

    /**
     * $clientOperationId is the idempotency key. A caller with a natural one (a form submission
     * token, an import batch id) should pass it; anything else gets a random one, which makes the
     * operation exactly-once per constructed request rather than per intent.
     *
     * **An empty string is treated as "no key given", not as a key.** It is not a hypothetical: a
     * caller that reads the key out of a request field gets `''` when the field is absent, and one
     * empty key stored is enough to make every later empty-key operation find that first group and
     * return having written nothing — silent data loss, reported as success.
     */
    public static function of(
        string $type,
        ?string $clientOperationId = null,
        ?string $reason = null,
        ?string $actor = null,
        ?string $reference = null,
        ?\DateTimeImmutable $occurredAt = null,
        ?InventoryAdjustmentReason $adjustmentReason = null,
    ): self {
        $clientOperationId = $clientOperationId === null ? '' : trim($clientOperationId);

        return new self(
            \in_array($type, InventoryMovementGroup::types(), true) ? $type : InventoryMovementGroup::TYPE_ADJUSTMENT,
            $clientOperationId !== '' ? $clientOperationId : bin2hex(random_bytes(16)),
            $reason,
            $actor,
            $reference,
            $occurredAt ?? new \DateTimeImmutable(),
            $adjustmentReason,
        );
    }

    /**
     * `$from === null` means entering the system; `$to === null` means leaving it.
     *
     * `$reverses` names the movement this line undoes, and is null for everything except a reversal
     * (#585). It is on the LINE and not on the request because a group carries many lines and a
     * reversal is per entry — see InventoryMovement::$reversesMovement, which is also where the
     * bound it makes possible is written down. Nothing here checks that bound: this is a value
     * object with no database, and ReversalPlanner is what reads `remaining` before building the
     * line at all.
     */
    public function move(ProductCore $product, ?DetailKey $from, ?DetailKey $to, string|int|float $quantity, ?InventoryMovement $reverses = null): self
    {
        // A decimal quantity since `App\Doctrine\Type\QuantityType` became a decimal type. It used
        // to be `int $quantity`, which is what made every caller round on the way in: 0.4 of a drum
        // reached here as 0, was refused as "must be positive", and a real part-receipt could not be
        // recorded at all. `string|int|float` so the callers that genuinely count whole things — a
        // serial, a binned unit — are unchanged.
        $quantity = QuantityScale::canonical($quantity);

        if ((float) $quantity <= 0.0) {
            throw new \InvalidArgumentException('A movement quantity must be positive; use the from/to sides to express direction.');
        }
        if ($from === null && $to === null) {
            throw new \InvalidArgumentException('A movement needs at least one side.');
        }

        $this->lines[] = ['product' => $product, 'from' => $from, 'to' => $to, 'quantity' => $quantity, 'reverses' => $reverses];

        return $this;
    }

    public function receive(ProductCore $product, DetailKey $into, string|int|float $quantity): self
    {
        return $this->move($product, null, $into, $quantity);
    }

    public function remove(ProductCore $product, DetailKey $from, string|int|float $quantity): self
    {
        return $this->move($product, $from, null, $quantity);
    }

    /** @return list<array{product: ProductCore, from: ?DetailKey, to: ?DetailKey, quantity: string, reverses: ?InventoryMovement}> */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * How much `available` stock this request puts into — or takes out of — one (product, warehouse),
     * read off the request itself.
     *
     * This is the number `product_inventory.received_quantity` accumulates (#572), and the whole
     * point is where it comes from: **the movement's own lines**, not the detail rows afterwards.
     * A receipt of 50 knows it is 50 while it is still in the caller's hand, so `received += 50` is
     * a fact being recorded. Re-reading the detail sum and subtracting `quantity` gives the same
     * answer for that one case and is a different thing entirely — a residual, which quietly absorbs
     * every OTHER change to the detail rows as though this app had received it. An import writing a
     * bin row, or the sentinel row being re-plugged, would land in `received` and be indistinguishable
     * from a delivery.
     *
     * Only the `available` sides count, on both ends, which is what makes the cases fall out of one
     * rule rather than several:
     *
     *  - a receipt (`from = null`, `to` available) — enters the system, `+quantity`
     *  - a bin-to-bin move (both sides available, same warehouse) — `+quantity − quantity = 0`, so it
     *    must not, and cannot, move the bucket
     *  - a withdrawal (available out, nothing in) — `−quantity`, because the units left the ledger
     *    with no destination and so no bucket recorded them; without this they would stay sellable
     *
     * A transfer's two ends are two different warehouses and are answered separately, because this
     * is asked per warehouse.
     *
     * WITH ONE SUBTRACTION (#581): a crossing whose other side is a status already accounted for
     * elsewhere does not move this bucket at all. Stock going `available → damaged` is subtracted
     * by `write_off`, `available → in_transit` by the transfer order's `transfer_out`,
     * `available → sold` by the invoice that billed it. Moving `received` as well would take the
     * same units off availability twice, and the reverse crossing — a quarantine released back to
     * the shelf, or a transfer landing on the destination's shelf — would put them back twice.
     * InventoryDetail::statusesAccountedForElsewhere() is the list, and the reasoning for each
     * entry is there.
     *
     * The counterparty usually has to be in the SAME warehouse to count as accounted for, because
     * the buckets are per (product, warehouse) — a `write_off` at another site takes nothing off
     * this site's availability. #581 added that test on purpose and it is still load-bearing.
     *
     * THE ONE EXCEPTION IS `in_transit`, and #584 is why. It used to read the other way: a transfer
     * arriving from another warehouse moved the destination's `received`, on the reasoning that the
     * far side was the SOURCE's `transfer_out` falling back to zero and nothing at the destination
     * had recorded the arrival. That was true while `transfer_out` meant "on a truck right now",
     * summed from the `in_transit` rows — and it was the entanglement #584 removed, because it made
     * a WarehouseOpsBundle fact land in a bucket ProcurementBundle's flag gates.
     *
     * The transfer buckets are now cumulative and derived from `transfer_order_line`:
     * `transfer_out` at the warehouse the document names FROM, `transfer_in` at the one it names
     * TO. So the arrival IS recorded at the destination, by `transfer_in`, and `received` must stay
     * out of it or the units are counted twice — the exact double-count this method exists to
     * prevent, in the one crossing that spans two warehouses.
     *
     * The condition is therefore not "drop the same-warehouse test" but "which statuses are
     * accounted for at BOTH ends regardless of warehouse". Exactly one is:
     * InventoryDetail::statusesAccountedForAtBothEnds(), where the reasoning per status lives.
     */
    public function receivedDelta(ProductCore $product, Warehouse $warehouse): string
    {
        // Summed as a float and spelt at the column's scale on the way out, for the reason
        // ProductInventory::getAvailableQuantity() gives: the terms are all figures the column can
        // hold, so the only thing the canonical form removes is the 1e-16 residue of a binary sum.
        $delta = 0.0;

        foreach ($this->lines as $line) {
            if (!self::same($line['product'], $product)) {
                continue;
            }

            $from = $line['from'];
            $to = $line['to'];

            if ($from instanceof DetailKey && $from->status === InventoryDetail::STATUS_AVAILABLE && self::same($from->warehouse, $warehouse)
                && !self::accountedForElsewhere($to, $warehouse)) {
                $delta -= (float) $line['quantity'];
            }

            if ($to instanceof DetailKey && $to->status === InventoryDetail::STATUS_AVAILABLE && self::same($to->warehouse, $warehouse)
                && !self::accountedForElsewhere($from, $warehouse)) {
                $delta += (float) $line['quantity'];
            }
        }

        return QuantityScale::canonical($delta);
    }

    /**
     * How much this request adds to — or removes from — `quarantine`, read off the request itself.
     *
     * Simple-inventory bucket parity plan (2026-09-15): `quarantine` and `write_off` used to be
     * recomputed wholesale from `SUM(inventory_detail)` on every call to `syncCoreTotal()` — which
     * made the bucket a pure derivation of the detail rows rather than an independent figure, and
     * meant it existed only for a product with detail rows to derive from at all. This is the same
     * shape as `receivedDelta()` instead: a plain crossing count, `+quantity` into the status set,
     * `−quantity` out of it (including a line that moves straight between `quarantine` and
     * `write_off`'s own status sets, which correctly debits one and credits the other). Unlike
     * `receivedDelta()` there is no "accounted for elsewhere" test — quarantine and write-off ARE
     * the statuses being accounted for here, not a pool something else already claimed.
     */
    public function quarantineDelta(ProductCore $product, Warehouse $warehouse): string
    {
        return $this->statusSetDelta($product, $warehouse, InventoryDetail::quarantineStatuses());
    }

    /** @see quarantineDelta() */
    public function writeOffDelta(ProductCore $product, Warehouse $warehouse): string
    {
        return $this->statusSetDelta($product, $warehouse, InventoryDetail::writeOffStatuses());
    }

    /** @param list<string> $statuses */
    private function statusSetDelta(ProductCore $product, Warehouse $warehouse, array $statuses): string
    {
        $delta = 0.0;

        foreach ($this->lines as $line) {
            if (!self::same($line['product'], $product)) {
                continue;
            }

            $from = $line['from'];
            $to = $line['to'];

            if ($from instanceof DetailKey && self::same($from->warehouse, $warehouse) && \in_array($from->status, $statuses, true)) {
                $delta -= (float) $line['quantity'];
            }

            if ($to instanceof DetailKey && self::same($to->warehouse, $warehouse) && \in_array($to->status, $statuses, true)) {
                $delta += (float) $line['quantity'];
            }
        }

        return QuantityScale::canonical($delta);
    }

    /**
     * Is the other side of a crossing a status that some other bucket — or the invoice, or the
     * transfer order — already records?
     *
     * A null side is stock entering or leaving the ledger outright, which nothing else records.
     *
     * Two rules, because there are two kinds of accounting (#584):
     *
     *  - a status recorded by a per-warehouse bucket counts only when the counterparty row is in
     *    THIS warehouse, since that is the only row whose bucket grew;
     *  - a status recorded by a document at both ends counts wherever the counterparty row sits.
     *
     * The second is checked first and without the warehouse test, which is the whole behavioural
     * change: an arriving transfer's far side is `in_transit` at the SOURCE warehouse, and it is
     * accounted for there and here — `transfer_out` and `transfer_in` are two readings of one
     * document, not two warehouses' independent bookkeeping.
     */
    private static function accountedForElsewhere(?DetailKey $side, Warehouse $warehouse): bool
    {
        if (!$side instanceof DetailKey) {
            return false;
        }

        if (in_array($side->status, InventoryDetail::statusesAccountedForAtBothEnds(), true)) {
            return true;
        }

        return self::same($side->warehouse, $warehouse)
            && in_array($side->status, InventoryDetail::statusesAccountedForElsewhere(), true);
    }

    /**
     * Identity first, then id — so this still answers correctly for a caller holding a re-fetched
     * copy of the same row, and does not fall for two unsaved entities that both have a null id.
     */
    private static function same(ProductCore|Warehouse $a, ProductCore|Warehouse $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return $a::class === $b::class && $a->getId() !== null && $a->getId() === $b->getId();
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
