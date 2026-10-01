<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\InventoryHistory;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\Part;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place a part's stock changes: it moves Part::$quantity and records the InventoryHistory
 * row in the same step (legacy Parts::updateQuantity() + the history row every caller built by
 * hand). Restocks, edits of the quantity and, with the invoices, paid invoices all come here.
 *
 * Does not flush: the caller saves the movement together with whatever caused it.
 */
final class StockLedger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentNumbers $numbers,
    ) {
    }

    /**
     * @param int $quantity change in stock: positive in, negative out; zero records nothing
     * @param ?string $unitPrice cost of this movement, defaults to the part's
     * @param ?string $salePrice price of this movement, defaults to the part's
     */
    public function record(
        Part $part,
        int $quantity,
        ?string $note = null,
        ?string $poNumber = null,
        ?string $unitPrice = null,
        ?string $salePrice = null,
        ?int $invoiceId = null,
    ): ?InventoryHistory {
        if ($quantity === 0) {
            return null;
        }

        $part->adjustQuantity($quantity);
        $movement = new InventoryHistory(
            $part,
            $quantity,
            $unitPrice ?? $part->getUnitPrice(),
            $salePrice ?? $part->getSalePrice(),
            $note,
            $poNumber,
            $invoiceId,
        );
        $this->entityManager->persist($movement);

        return $movement;
    }

    /** Records whatever change takes the part's stock to $quantity. */
    public function setQuantity(Part $part, int $quantity, string $note): ?InventoryHistory
    {
        return $this->record($part, $quantity - $part->getQuantity(), $note);
    }

    /**
     * Brings the stock taken by a (saved) invoice in line with it: a paid invoice has taken every
     * part on it (its own lines and its services' parts), an unpaid one has taken none. Only the
     * difference from what is already recorded against the invoice is moved, so saving again
     * changes nothing. (The legacy app took the parts again on every save of a paid invoice.)
     */
    public function reconcileInvoice(Invoice $invoice): void
    {
        $wanted = [];
        $parts = [];
        if ($invoice->isPaid()) {
            foreach ($invoice->getPartLines() as $line) {
                if ($line->getPart() !== null) {
                    $id = (int) $line->getPart()->getId();
                    $parts[$id] = $line->getPart();
                    $wanted[$id] = ($wanted[$id] ?? 0) + $line->getQuantity();
                }
            }
        }

        $taken = [];
        foreach ($this->takenByInvoice((int) $invoice->getId()) as $partId => $row) {
            $taken[$partId] = $row['taken'];
            $parts[$partId] ??= $row['part'];
        }

        foreach ($parts as $partId => $part) {
            $change = ($wanted[$partId] ?? 0) - ($taken[$partId] ?? 0);
            $this->record($part, -$change, sprintf('Invoice %s', $this->numbers->number($invoice)), invoiceId: $invoice->getId());
        }
    }

    /** @return array<int, array{part: Part, taken: int}> part id => the part and the stock recorded as taken by the invoice */
    private function takenByInvoice(int $invoiceId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(h.part) AS part', 'SUM(h.quantity) AS moved')
            ->from(InventoryHistory::class, 'h')
            ->andWhere('h.invoiceId = :invoice')->setParameter('invoice', $invoiceId)
            ->groupBy('h.part')
            ->getQuery()
            ->getArrayResult();

        $taken = [];
        foreach ($rows as $row) {
            $part = $this->entityManager->find(Part::class, (int) $row['part']);
            if ($part !== null) {
                $taken[(int) $row['part']] = ['part' => $part, 'taken' => -(int) $row['moved']];
            }
        }

        return $taken;
    }
}
