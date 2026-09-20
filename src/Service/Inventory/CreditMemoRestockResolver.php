<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Contract\Inventory\CreditMemoRestockLedgerInterface;
use App\Entity\CreditMemo;
use App\Enum\CreditMemoVoidDisposition;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Voiding a credit note, with whatever stock its restock moved accounted for (item 38).
 *
 * ## The defect this closes
 *
 * A credit note that says the goods came back records them as `returned` stock when it is issued.
 * Voiding it did nothing about that: the note went Void and the units stayed in the building,
 * counted in `quarantine_quantity`, with no live document behind them. Inventory you do not have,
 * arrived at silently.
 *
 * ## Why the guard is here and not in `CreditMemo::void()`
 *
 * Because "did this note move stock" is a question about the LEDGER, and an entity has no entity
 * manager and no movement service — the same reason `CreditMemo::issue()` does not perform the
 * restock either, and the same split the buy side's own debit-memo stock service makes on
 * the buy side. `void()` keeps its own refusals (already void, money applied or refunded against
 * it) and they still run FIRST, before any stock is touched.
 *
 * ## Why not the event the ISSUE side uses
 *
 * `CreditMemoIssuedEvent` is dispatched after the controller's flush, which is safe there because
 * the restock is a receipt: goods arriving from outside the ledger cannot be refused for want of
 * stock. Undoing it can be refused — the `returned` row may have been moved on by somebody else —
 * and a refusal arriving after the flush would leave the note already Void while the ledger still
 * counted the goods. So the void is a direct call inside one transaction, and if the stock entry
 * cannot be made the note does not void. Exactly the argument `DebitMemoStockService` makes for the
 * buy side's ISSUE, reached here from the other end.
 *
 * ## With the bundle absent
 *
 * No provider, so `restockedUnits()` is never asked, nothing is required of the caller, and a note
 * voids on one post as it always did. Correct rather than degraded: with no depth layer installed
 * nothing ever wrote a `returned` row, so there is no stock to account for.
 */
final class CreditMemoRestockResolver
{
    /** @param iterable<CreditMemoRestockLedgerInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * What issuing this note put into the ledger. Empty when it moved nothing — which is also the
     * answer to "does voiding this note need a decision".
     *
     * @return list<array{product: \App\Entity\ProductCore, quantity: int, warehouse: ?\App\Entity\Warehouse, location: ?string}>
     */
    public function restockedUnits(CreditMemo $memo): array
    {
        return $this->activeProvider()?->restockedUnits($memo) ?? [];
    }

    public function movedStock(CreditMemo $memo): bool
    {
        return $this->restockedUnits($memo) !== [];
    }

    /** What became of the goods on a note already voided, for its detail screen to state. */
    public function voidOutcome(CreditMemo $memo): ?string
    {
        return $this->activeProvider()?->voidOutcome($memo);
    }

    /**
     * Voids $memo, and records what became of any stock its issuing moved.
     *
     * @throws \DomainException from `CreditMemo::void()`, from the missing-answer refusal below, or
     *                          from the movement layer — all three reach the screen as a sentence
     */
    public function void(CreditMemo $memo, ?string $disposition, ?string $actor = null): void
    {
        $provider = $this->activeProvider();
        $moved = $provider?->restockedUnits($memo) ?? [];

        if ($provider === null || $moved === []) {
            // Nothing moved when this note was issued, so nothing has to be said about goods. Voids
            // exactly as it always did: no question, no group, no movement.
            $memo->setStatus('Void', DocumentActor::system());
            $this->entityManager->flush();

            return;
        }

        $chosen = CreditMemoVoidDisposition::tryFrom((string) $disposition);
        if ($chosen === null) {
            throw new \DomainException(sprintf(
                'Issuing credit note %s recorded goods coming back from the customer. Voiding it has to say what '
                . 'became of them — they are not here, they are here but written off, or they are still here '
                . 'awaiting a ruling — because the three leave the warehouse in three different states and this '
                . 'cannot be worked out from the note.',
                $memo->getDocumentNumber(),
            ));
        }

        $this->entityManager->wrapInTransaction(function () use ($memo, $chosen, $actor, $provider): void {
            // First, so "already void" and "money is applied against it" refuse before any stock
            // entry is built. A refusal here leaves the ledger untouched.
            $memo->setStatus('Void', DocumentActor::system());

            $provider->recordVoidDisposition($memo, $chosen, $actor);

            $this->entityManager->flush();
        });
    }

    private function activeProvider(): ?CreditMemoRestockLedgerInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof CreditMemoRestockLedgerInterface
                && $this->bundleStatusRepo->isActive($provider->getSource())
            ) {
                return $provider;
            }
        }

        return null;
    }
}
