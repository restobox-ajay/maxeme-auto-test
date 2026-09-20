<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Pick;

use InventoryDepthBundle\Entity\InventoryMovementGroup;
use WarehouseOpsBundle\Entity\PickTask;

/**
 * What one confirmation did: the three facts of a short pick, plus the groups that recorded the two
 * of them that moved stock (#552).
 *
 * The two groups are returned rather than merely written so a test can assert that a short pick
 * produced BOTH — one movement and one adjustment — which is the acceptance criterion the plan
 * states, and so the flash message can say what was written without going back to the database.
 *
 * Three of these numbers are records of something that happened. Two — `$unattributed` and
 * `$unmoved` — are records of something that deliberately did NOT happen, and they exist for the
 * same reason: the safe answer to "we cannot tell where this belongs" is to leave the stock alone
 * and say so out loud, and a number nobody returns is a number no screen can say.
 */
final class PickConfirmation
{
    /**
     * @param string $missing      written off: counted missing IN THE PLACE THE PICKER NAMED
     * @param string $outstanding  what the order still needs — neither found nor written off
     * @param string $unattributed the slice of $outstanding that the system still shows as on hand
     *                          in this warehouse, but which was NOT written off because nothing
     *                          named a bin — neither the picker nor the task — so nothing said
     *                          which shelf was being counted (#589)
     * @param string $unmoved      units the picker says they carried away that the named place
     *                          could not supply: they typed 20 against a bin the system shows
     *                          holding 5. The five moved and the other fifteen did not, because
     *                          sourcing them from another bin would be recording a count taken at
     *                          one shelf against another (#591). The order goes on owing them.
     * @param PickSource|null $source where this confirmation was recorded against, after the
     *                          picker's answer, the task's suggested bin and silence have been
     *                          resolved in that order — so a flash or a test can name the place
     *                          rather than infer it
     */
    public function __construct(
        public readonly PickTask $task,
        /** Decimal strings since the quantity columns widened — a pick is a quantity, not a count of things. */
        public readonly string $picked,
        public readonly string $missing,
        public readonly string $outstanding,
        public readonly ?InventoryMovementGroup $pickGroup,
        public readonly ?InventoryMovementGroup $adjustmentGroup,
        public readonly string $unattributed = '0.0000',
        public readonly string $unmoved = '0.0000',
        public readonly ?PickSource $source = null,
    ) {
    }
}
