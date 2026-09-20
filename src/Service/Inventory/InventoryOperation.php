<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Contract\Inventory\InventoryOperationGroupInterface;

/**
 * One open inventory operation: what is being done, on whose behalf, and — if stock physically
 * moved — which movement group recorded the move (#582).
 *
 * This is the half of an `inventory_bucket_change_log` row that a Doctrine changeset cannot supply.
 * The changeset knows a bucket went from 3 to 7; it has no idea whether that was a goods receipt, a
 * cart hold expiring or an import rebaselining a count, and the numbers alone cannot tell those
 * apart after the fact. So the answer comes from the operation that was open at the time — see
 * InventoryOperationContext for how "at the time" is defined, which is subtler than it looks.
 *
 * ## Why the group is attached rather than passed in
 *
 * The operation is opened BEFORE the group exists in the two places that create one.
 * StockMovementService::apply() opens a transaction, then builds the group inside it;
 * TransferOrderService::receive() gets its group back from a nested apply() call. Requiring the
 * group up front would mean either opening the operation late — after the first bucket write, which
 * defeats the point — or threading it through, which is exactly the call-site bookkeeping this
 * whole mechanism exists to delete.
 *
 * ## Immutable except for the group, on purpose
 *
 * `action` and `triggeredBy` describe the operation and are settled the moment it opens. The group
 * is the one fact that is genuinely discovered part-way through, so it is the one thing that can be
 * attached later — and only ever upwards, from null to a group, never swapped for a different one.
 */
final class InventoryOperation
{
    private ?InventoryOperationGroupInterface $group = null;

    /**
     * @param string $action       the short machine-readable category written to
     *                             `inventory_bucket_change_log.action`, e.g. 'order_reconciled' or
     *                             'movement_receipt'. Describes the OPERATION, never the bucket:
     *                             one operation may move several buckets and they all carry this.
     * @param string|null $triggeredBy who to credit, or null to let the context answer from the
     *                             security token / console command / 'System'. Passed explicitly
     *                             only where the operation already carries an actor of its own,
     *                             e.g. a movement request's `actor` field.
     */
    public function __construct(
        private readonly string $action,
        private readonly ?string $triggeredBy = null,
    ) {
    }

    public function action(): string
    {
        return $this->action;
    }

    public function triggeredBy(): ?string
    {
        return $this->triggeredBy;
    }

    /**
     * Names the physical operation this bucket change belongs to.
     *
     * Idempotent and one-way: attaching twice keeps the first group. A nested operation that
     * attaches its own group does not overwrite its parent's, because it never sees it — the
     * context walks outwards and stops at the first one it finds.
     */
    public function attachGroup(?InventoryOperationGroupInterface $group): self
    {
        if ($group !== null && $this->group === null) {
            $this->group = $group;
        }

        return $this;
    }

    public function group(): ?InventoryOperationGroupInterface
    {
        return $this->group;
    }
}
