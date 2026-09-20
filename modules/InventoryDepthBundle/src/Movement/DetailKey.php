<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Movement;

use App\Entity\Warehouse;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;

/**
 * One side of a movement, named rather than resolved: which warehouse, bin, lot, serial and status
 * the quantity is coming from or going to.
 *
 * A key is not a row. `StockMovementService` turns it into one through
 * InventoryDetailRepository::findOrCreate(), which is what makes "move 5 into a bin nothing has
 * ever been in" and "move 5 into a bin that already holds 12" the same call.
 */
final class DetailKey
{
    public readonly string $status;

    public function __construct(
        public readonly Warehouse $warehouse,
        public readonly ?WarehouseLocation $location = null,
        public readonly ?InventoryLot $lot = null,
        public readonly ?string $serial = null,
        string $status = InventoryDetail::STATUS_AVAILABLE,
        /**
         * Whether the row this key resolves to is carrying a placeholder identity somebody is
         * expected to come back and replace (#573).
         *
         * **Not part of the identity**, which is why it sits beside the five fields above rather
         * than among them: two keys differing only in this resolve to the same row, and
         * InventoryDetailRepository::findOrCreate() neither reads it nor keys on it.
         * StockMovementService stamps it onto the destination row after resolving, and only ever
         * upwards — a movement never declares somebody else's to-do finished.
         */
        public readonly bool $expectResolution = false,
        /**
         * The last usable day, for a row with no lot to carry it (#795).
         *
         * **Part of the identity when there is no lot**, unlike `$expectResolution` above: two
         * lot-less rows at the same bin with different expiry dates are two different batches, the
         * same way two different lot rows already are, so `findOrCreate()`/`findExisting()` both
         * read it. Meaningless — and refused by `InventoryDetail::setExpiry()` — once `$lot` is set,
         * which is why StockMovementService only ever stamps it when `$lot === null`.
         */
        public readonly ?\DateTimeImmutable $expiry = null,
    ) {
        // Coerced HERE, at the boundary, and not left to InventoryDetail::setStatus().
        //
        // The two must agree, because a key is used first to LOOK UP a row and then to build one if
        // the lookup missed. Coercing only on the entity means an unrecognised status searches for
        // rows with that status (finding none, since nothing can hold it), then stores the new row
        // as `available` — a SECOND available row for a combination that already had one, which is
        // exactly the duplicate InventoryDetailRepository::findOrCreate() exists to prevent. Against
        // a migrated database that trips uniq_inventory_detail mid-transaction; against the schema
        // both test suites build from metadata, where that index cannot exist, it silently succeeds.
        //
        // Reachable from the adjustment form, which takes the status straight from the POST body.
        $this->status = \in_array($status, InventoryDetail::statuses(), true)
            ? $status
            : InventoryDetail::STATUS_AVAILABLE;
    }

    /**
     * The same key with its location dropped, which is what a terminal status requires: sold,
     * scrapped and lost rows keep their warehouse but never a bin, so "which site shipped it" and
     * "where was it lost" stay answerable while the row itself stays single.
     */
    public function forStatus(string $status): self
    {
        $terminal = \in_array($status, InventoryDetail::terminalStatuses(), true);

        return new self(
            $this->warehouse,
            $terminal ? null : $this->location,
            $this->lot,
            $this->serial,
            $status,
            $this->expectResolution,
            $this->expiry,
        );
    }

    /**
     * The key as it will actually be stored — currently that means dropping the location if the
     * status is terminal. Applied on both sides of every movement, so a caller cannot accidentally
     * create a second `sold` row for the same lot by naming a bin on it.
     */
    public function normalized(): self
    {
        return $this->forStatus($this->status);
    }

    public function describe(): string
    {
        $parts = [$this->warehouse->getName()];
        $parts[] = $this->location?->getCode() ?? '(no bin)';

        if ($this->lot !== null) {
            $parts[] = $this->lot->getLabel();
        } elseif ($this->expiry !== null) {
            $parts[] = 'exp ' . $this->expiry->format('Y-m-d');
        }
        if ($this->serial !== null) {
            $parts[] = 'S/N ' . $this->serial;
        }

        return sprintf('%s [%s]', implode(' / ', $parts), $this->status);
    }
}
