<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Pick;

use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\WarehouseLocation;

/**
 * Where the picker says the units actually came off (#591).
 *
 * ## Why a value object rather than a nullable bin
 *
 * There are three answers to "which shelf did you take these off", not two, and collapsing them
 * loses the one that matters:
 *
 *  - **a bin** — "A-01-03", the ordinary answer;
 *  - **no bin** — the units were sitting in `inventory_detail` with `location_id` NULL, which is a
 *    real place stock lives in this application and the only place it lives when nothing has ever
 *    been binned;
 *  - **not answered** — nothing on the form or the request said, which is what every device and
 *    every POST written before this field existed sends.
 *
 * A plain `?WarehouseLocation` cannot tell the second from the third, and they must lead to
 * different behaviour: "no bin" is a statement to be scoped to, "not answered" is a silence to be
 * handled. PickConfirmationService is where that difference is spent — see its confirm().
 *
 * ## What it is NOT
 *
 * It is not an allocation rule. It filters an already-ordered list of rows; the ORDER still comes
 * from InventoryDetailRepository::pickableRows(), which is #550's picker and stays the one thing
 * that decides which lot goes first. What this adds is the picker's own evidence about WHERE, which
 * that query has never had and cannot infer — it answers "where SHOULD this come from", and a
 * confirmation is a record of where it DID.
 */
final class PickSource
{
    private function __construct(
        public readonly ?WarehouseLocation $bin,
        /** Whether anybody actually said. False is silence, not "no bin" — see the class docblock. */
        public readonly bool $named,
    ) {
    }

    public static function bin(WarehouseLocation $bin): self
    {
        return new self($bin, true);
    }

    /** The picker says these came off stock that sits in no bin at all. */
    public static function unbinned(): self
    {
        return new self(null, true);
    }

    /** Nobody said. Every request written before the confirm form grew the field sends this. */
    public static function unspecified(): self
    {
        return new self(null, false);
    }

    public function isNamed(): bool
    {
        return $this->named;
    }

    /** Reads for a flash message or a test failure: the bin's code, or the honest blank. */
    public function describe(): string
    {
        if (!$this->named) {
            return 'no bin named';
        }

        return $this->bin instanceof WarehouseLocation ? $this->bin->getCode() : 'no bin';
    }

    /**
     * Whether this row sits where the picker says they were standing.
     *
     * Compared by id rather than by identity because the rows come out of a query and the bin comes
     * off a request, and two managed references to one row are not guaranteed to be the same object
     * once an EntityManager has been cleared mid-request.
     */
    public function holds(InventoryDetail $row): bool
    {
        if (!$this->named) {
            return false;
        }

        $rowBin = $row->getLocation();

        if (!$this->bin instanceof WarehouseLocation) {
            return !$rowBin instanceof WarehouseLocation;
        }

        return $rowBin instanceof WarehouseLocation && $rowBin->getId() === $this->bin->getId();
    }
}
