<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Invoice;
use App\Entity\InvoicePaymentApplication;
use App\Enum\InvoiceStatus;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moves an invoice payment claim from one invoice to another (queue item 34, #708) — the sell
 * side's mirror of `VendorBillPaymentMover` (in the ProcurementBundle payment namespace), and
 * deliberately thinner than it.
 *
 * ## The asymmetry this class is the sell-side half of
 *
 * `VendorBillPaymentMover` exists because the buy side runs no Doctrine listener: nothing recomputes
 * a bill's derived status unless a call site asks `VendorBillStatusDeriver` to, so the mover has to
 * call it twice — once per document — after every move. The sell side has run
 * {@see \App\EventSubscriber\InvoicePaymentStatusSubscriber} since #539 stage 4, and that subscriber
 * already reads a moved claim's UNIT OF WORK change set to find the invoice it left, precisely so
 * that a future move would need no recalculation call of its own. This class is that future move: it
 * withdraws the claim, reapplies the same underlying payment elsewhere, and flushes; the listener
 * does the rest.
 *
 * So where the buy side's mover is "move, then recalculate both, then flush", this one is "move,
 * then flush" — one fewer step, not a shortcut. The candidates query below still duplicates the
 * guard's rules for the dropdown, exactly as the buy side's does, for the same reason: a dropdown is
 * a convenience and never the guard in an application that accepts hand-made POSTs.
 */
final class InvoicePaymentMover
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Move $application from $source to $target and flush.
     *
     * @return string the sentence to put in front of the person: where the payment went, what both
     *                balances are now, and — when it applies — that the target is overpaid
     *
     * @throws \DomainException with the reason, when the move is not a legal one. Nothing is
     *                          written: every rule is checked before the first collection is touched
     */
    public function move(
        DocumentActor $actor,
        Invoice $source,
        InvoicePaymentApplication $application,
        Invoice $target,
        ?string $reason = null,
    ): string {
        // Formatted once, here, because getAmount() is the raw decimal column and SQLite hands a
        // round figure back as '900'. Money is two decimals, consistently — the same reason
        // VendorBillPaymentMover::move() formats it here rather than trusting the column.
        $amount = number_format((float) $application->getAmount(), 2, '.', '');
        $method = $application->getMethod();

        $payment = $application->getPayment();

        $source->moveApplication($actor, $application, $target, $reason);

        // Belt and braces over the cancel that PersistentCollection::add() already performs inside
        // moveApplication() when the target's collection is a managed one — see
        // VendorBillPaymentMover::move()'s identical line for why this is not a no-op on every path.
        $this->em->getUnitOfWork()->cancelOrphanRemoval($payment);

        $this->em->flush();

        $notice = sprintf(
            'Payment of $%s via %s moved from invoice %s to invoice %s. It kept its date, its reference'
            . ' and its id. Invoice %s is now %s with a balance of $%s; invoice %s is now %s with a'
            . ' balance of $%s.',
            $amount,
            $method,
            $source->getDocumentLabel(),
            $target->getDocumentLabel(),
            $source->getDocumentLabel(),
            $source->getPaymentStatus()->value,
            $source->getBalance(),
            $target->getDocumentLabel(),
            $target->getPaymentStatus()->value,
            $target->getBalance(),
        );

        $overpaid = PaymentApplicationGuard::overpaymentNotice($target);

        return $overpaid === null ? $notice : $notice . ' ' . $overpaid;
    }

    /**
     * The invoices this claim could legally be moved to, for the dropdown.
     *
     * Queried rather than filtered in PHP over every invoice: the same company, not this invoice,
     * not Draft, not Cancelled — {@see PaymentApplicationGuard}'s rules expressed as a WHERE clause.
     * Currency is not filtered: `AbstractSalesDocument::getCurrency()` is always null on this side —
     * the sell side names its one currency once, in `base_currency` — so the currency rule can never
     * actually refuse two invoices here the way it can refuse two bills.
     *
     * The duplication is deliberate and one-directional, exactly as the buy side's: the dropdown
     * never offers an invoice the guard would refuse, and the guard still refuses one that reaches
     * the POST anyway.
     *
     * @return list<Invoice>
     */
    public function candidatesFor(Invoice $source): array
    {
        /** @var list<Invoice> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.company = :company')
            ->andWhere('i.id != :self')
            ->andWhere('i.status NOT IN (:excluded)')
            ->setParameter('company', $source->getCompany())
            ->setParameter('self', $source->getId())
            ->setParameter('excluded', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
            ->orderBy('i.documentNumber', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
