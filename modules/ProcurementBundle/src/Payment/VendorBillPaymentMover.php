<?php

declare(strict_types=1);

namespace ProcurementBundle\Payment;

use App\Payment\PaymentApplicationGuard;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillPaymentApplication;

/**
 * Moves a vendor bill payment claim from one bill to another (queue item 34).
 *
 * ## Thinner than it used to be
 *
 * This class used to be the one place that recalculated BOTH bills' derived status after a move —
 * `VendorBillStatusDeriver::recalculate()`, called by hand, because the buy side ran no Doctrine
 * listener and "recalculate both documents" was a thing somebody had to remember at every call site
 * that moved money. `VendorBillPaymentStatusSubscriber` is that listener now (#708, "listener on both
 * side"): it watches every inserted, updated and deleted `VendorBillPaymentApplication` and every
 * `VendorBill`, reads a moved claim's UNIT OF WORK change set to find the bill it left, and
 * recalculates both at `postFlush`. So this class no longer calls the deriver at all — see the
 * subscriber's own docblock for why the buy side finally gets the same shape the sell side's
 * `InvoicePaymentMover` always had.
 */
final class VendorBillPaymentMover
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
        VendorBill $source,
        VendorBillPaymentApplication $application,
        VendorBill $target,
        ?string $reason = null,
    ): string {
        // Formatted once, here, because `getAmount()` is the raw decimal column and SQLite hands a
        // round figure back as '900'. Money is two decimals with the currency, consistently.
        $amount = number_format((float) $application->getAmount(), 2, '.', '');
        $method = $application->getMethod();

        $payment = $application->getPayment();

        $source->moveApplication($actor, $application, $target, $reason);

        // Belt and braces over the cancel that `PersistentCollection::add()` already performed
        // inside moveApplication(). That cancel only happens when the target's collection is a
        // MANAGED PersistentCollection; a target built in memory and not yet flushed holds a plain
        // ArrayCollection, which has no unit of work to tell. The failure mode it guards against is
        // the payment being DELETED by the move that exists to preserve it, which is worth one
        // idempotent line.
        $this->em->getUnitOfWork()->cancelOrphanRemoval($payment);

        $this->em->flush();

        $notice = sprintf(
            'Payment of %s %s via %s moved from bill %s to bill %s. It kept its date, its reference and its id.'
            . ' Bill %s is now %s with a balance of %s %s; bill %s is now %s with a balance of %s %s.',
            $source->getCurrency(),
            $amount,
            $method,
            $source->getDocumentLabel(),
            $target->getDocumentLabel(),
            $source->getDocumentLabel(),
            $source->getStatus(),
            $source->getCurrency(),
            $source->getBalance(),
            $target->getDocumentLabel(),
            $target->getStatus(),
            $target->getCurrency(),
            $target->getBalance(),
        );

        $overpaid = PaymentApplicationGuard::overpaymentNotice($target);

        return $overpaid === null ? $notice : $notice . ' ' . $overpaid;
    }

    /**
     * The bills this claim could legally be moved to, for the dropdown.
     *
     * Queried rather than filtered in PHP over every bill: the same vendor, not this bill, not Void,
     * same currency. That is {@see PaymentApplicationGuard}'s rules expressed as a WHERE clause, and
     * the duplication is deliberate and one-directional — the dropdown never offers a bill the guard
     * would refuse, and the guard still refuses one that reaches the POST anyway. A dropdown is not
     * a guard in an application that works with scripting off and accepts hand-made POSTs.
     *
     * @return list<VendorBill>
     */
    public function candidatesFor(VendorBill $source): array
    {
        /** @var list<VendorBill> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('b')
            ->from(VendorBill::class, 'b')
            ->where('b.vendor = :vendor')
            ->andWhere('b.id != :self')
            ->andWhere('b.status != :void')
            ->andWhere('b.currency = :currency')
            ->setParameter('vendor', $source->getVendor())
            ->setParameter('self', $source->getId())
            ->setParameter('void', \ProcurementBundle\Enum\VendorBillStatus::Void)
            ->setParameter('currency', $source->getCurrency())
            ->orderBy('b.billNumber', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
