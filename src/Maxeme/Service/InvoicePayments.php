<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Accounting\Money;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\InvoicePayment;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Enum\InvoiceStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An invoice's payments (mockup 345c037): one or more, none above the balance due. The invoice follows
 * them: Paid once they cover its total, else Unpaid (a Cancelled one stays Cancelled), keeping the last
 * method, the total paid and, as its invoice date, when the last one was made; its repair order follows
 * the invoice (Invoiced / back, RepairOrderInvoicing::followInvoices).
 */
final class InvoicePayments
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RepairOrderInvoicing $invoicing,
    ) {
    }

    /** @return list<InvoicePayment> oldest first */
    public function of(Invoice $invoice): array
    {
        return $this->entityManager->getRepository(InvoicePayment::class)->findBy(['invoice' => $invoice], ['paidAt' => 'ASC', 'id' => 'ASC']);
    }

    public function paidCents(Invoice $invoice): int
    {
        return array_sum(array_map(static fn (InvoicePayment $payment): int => Money::toCents($payment->getAmount()), $this->of($invoice)));
    }

    public function balanceCents(Invoice $invoice): int
    {
        return max(0, Money::toCents($invoice->getTotalPrice()) - $this->paidCents($invoice));
    }

    /**
     * @param ?InvoicePayment $editing the payment being changed (its own amount is room too)
     *
     * @throws \DomainException
     */
    public function save(Invoice $invoice, ?InvoicePayment $editing, ?PaymentType $type, string $amount, \DateTimeImmutable $paidAt, ?string $note): InvoicePayment
    {
        if ($invoice->getStatus() === InvoiceStatus::Cancelled) {
            throw new \DomainException('A cancelled invoice takes no payments.');
        }
        $cents = Money::toCents($amount);
        if ($cents <= 0) {
            throw new \DomainException('Enter an amount above 0.');
        }
        if ($type === null) {
            throw new \DomainException('Choose a payment method.');
        }
        $room = $this->balanceCents($invoice) + ($editing !== null ? Money::toCents($editing->getAmount()) : 0);
        if ($cents > $room) {
            throw new \DomainException(sprintf('That is more than the balance due ($%s).', Money::fromCents($room)));
        }
        if ($editing !== null) {
            $editing->change($type, Money::fromCents($cents), $paidAt, $note);
            $payment = $editing;
        } else {
            $payment = new InvoicePayment($invoice, $type, Money::fromCents($cents), $paidAt, $note);
            $this->entityManager->persist($payment);
        }
        $this->entityManager->flush();
        $this->sync($invoice);

        return $payment;
    }

    /** Mark Paid: one payment of the whole balance, by $type, now. */
    public function markPaid(Invoice $invoice, ?PaymentType $type): InvoicePayment
    {
        $balance = $this->balanceCents($invoice);
        if ($balance <= 0) {
            throw new \DomainException('Nothing is due on this invoice.');
        }

        return $this->save($invoice, null, $type, Money::fromCents($balance), new \DateTimeImmutable(), null);
    }

    public function delete(InvoicePayment $payment): void
    {
        $invoice = $payment->getInvoice();
        $this->entityManager->remove($payment);
        $this->entityManager->flush();
        $this->sync($invoice);
    }

    /**
     * The invoice builder (an invoice with no repair order) still takes a method and an amount and can
     * save the invoice Paid: that payment becomes the invoice's payment row when it has none yet.
     */
    public function adoptBuilderPayment(Invoice $invoice): void
    {
        if ($invoice->getId() === null || $this->of($invoice) !== [] || !$invoice->isPaid() || Money::toCents((string) $invoice->getPaymentAmount()) <= 0) {
            return;
        }
        $this->entityManager->persist(new InvoicePayment($invoice, $invoice->getPaymentType(), Money::fromCents(min(Money::toCents((string) $invoice->getPaymentAmount()), Money::toCents($invoice->getTotalPrice()))), $invoice->getLastModified() ?? new \DateTimeImmutable()));
        $this->entityManager->flush();
    }

    /**
     * The invoice follows its payments (and its repair order follows the invoice). $promoteOnly (an
     * issued-invoice edit, whose total can only go down): it may become Paid, never Unpaid, so an old
     * invoice paid in the old system for less than its total is not reopened by an unrelated edit.
     */
    public function sync(Invoice $invoice, bool $promoteOnly = false): void
    {
        $payments = $this->of($invoice);
        $paid = array_sum(array_map(static fn (InvoicePayment $payment): int => Money::toCents($payment->getAmount()), $payments));
        $last = $payments !== [] ? $payments[count($payments) - 1] : null;
        if ($invoice->getStatus() !== InvoiceStatus::Cancelled) {
            $total = Money::toCents($invoice->getTotalPrice());
            // Paid once the payments cover the total; a zero-total invoice already Paid stays Paid (nothing to pay).
            $status = ($payments !== [] && $paid >= $total) || ($total <= 0 && $invoice->isPaid()) ? InvoiceStatus::Paid : InvoiceStatus::Unpaid;
            if ($invoice->getStatus() !== $status && !($promoteOnly && $status === InvoiceStatus::Unpaid && $invoice->isPaid())) {
                $invoice->setStatus($status);
            }
        }
        if (!$promoteOnly || $payments !== []) {
            $invoice->setPaymentAmount($payments !== [] ? Money::fromCents($paid) : null);
            $invoice->setPaymentType($last?->getPaymentType());
        }
        if ($last !== null && !$promoteOnly) {
            $invoice->setLastModified($last->getPaidAt());
        }
        $this->entityManager->flush();
        if ($invoice->getRepairOrder() !== null) {
            $this->invoicing->followInvoices($invoice->getRepairOrder());
        }
    }
}
