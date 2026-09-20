<?php

declare(strict_types=1);

namespace App\Contract\Inventory;

use App\Entity\CreditMemo;
use App\Enum\CreditMemoVoidDisposition;

/**
 * What a restocking credit note actually moved, and how a void records what became of it (item 38).
 *
 * Core owns the credit note, its void transition and the screen that asks the question. It does not
 * own bins, lots, serials or movements, and it cannot: `CreditMemo` and `CreditMemoController` are
 * core, the ledger is `modules/InventoryDepthBundle`, and core may not depend on a bundle. So it
 * hands the two halves of the question over here — same seam, same reason, as
 * {@see DimensionalInventoryProviderInterface} and {@see DimensionalImportProviderInterface}.
 *
 * With no provider registered — the bundle deleted, or switched Inactive on App Management —
 * `App\Service\Inventory\CreditMemoRestockResolver` finds none, `restockedUnits()` is never called,
 * and a credit note voids on one post exactly as it always has. That is correct rather than a
 * degradation: with no depth layer installed nothing wrote a `returned` row in the first place, so
 * there is no stock for the void to account for.
 *
 * getSource() names the owning bundle so the Active/Inactive kill-switch applies to the provider
 * without the provider checking its own status — the same contract every other provider here has.
 */
interface CreditMemoRestockLedgerInterface
{
    /** The owning bundle's source, checked against App\Repository\BundleStatusRepository. */
    public function getSource(): string;

    /**
     * What issuing $memo actually put into the ledger — one row per movement it wrote.
     *
     * Read from the MOVEMENTS rather than from the note's lines, and the difference is the point: a
     * line whose product is on simple inventory moved nothing and correctly appears nowhere here,
     * so a note whose products are all simple returns `[]` and voids with no question asked. An
     * empty array is therefore the answer to "did this note move stock" as well, which is why there
     * is no separate boolean — one fact, read in one place, from the ledger where it is true.
     *
     * @return list<array{product: \App\Entity\ProductCore, quantity: int, warehouse: ?\App\Entity\Warehouse, location: ?string}>
     */
    public function restockedUnits(CreditMemo $memo): array;

    /**
     * Records what became of those goods, as one movement group attributed to $memo.
     *
     * Called INSIDE the caller's transaction and after `CreditMemo::void()`, so a refusal from the
     * movement layer takes the void down with it: the note and the ledger are both written or
     * neither is.
     *
     * @throws \DomainException when the stock entry cannot be made, carrying the movement layer's
     *                          own sentence so it reaches the screen rather than a 500
     */
    public function recordVoidDisposition(CreditMemo $memo, CreditMemoVoidDisposition $disposition, ?string $actor): void;

    /**
     * The sentence recorded against a note already voided, for its detail screen to state what
     * became of the goods — so nobody has to go and find the product to learn it. Null when this
     * note's void wrote nothing.
     */
    public function voidOutcome(CreditMemo $memo): ?string;
}
