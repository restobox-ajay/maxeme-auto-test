<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Enum\RepairOrderStatus;
use App\Maxeme\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A repair order moved to another status on its own (the Repair Order Board's drag, mockups
 * aa322bb and 8c0ad56), with the mockup's rules (MX.confirmRoMove):
 * - an Invoiced repair order is paid, so it is locked: its invoice must be refunded first;
 * - Invoiced only with a paid invoice (it then becomes Invoiced by itself);
 * - Completed, Picked Up only with a live (not cancelled) invoice: the car leaves with it;
 * - moving a Completed / Picked Up / Invoiced one with live invoices back to an earlier status
 *   asks first (the invoices do not change).
 * The status change itself does the rest (appointments completed or cancelled, reminders queued).
 */
final class RepairOrderStatusChange
{
    private const FINISHED = [RepairOrderStatus::Completed, RepairOrderStatus::CompletedPickedUp, RepairOrderStatus::Invoiced];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceRepository $invoices,
        private readonly DocumentNumbers $numbers,
    ) {
    }

    /**
     * The question to ask before the move, or null when it can go ahead.
     *
     * @throws \DomainException when the move is refused
     */
    public function confirmationFor(RepairOrder $repairOrder, RepairOrderStatus $to): ?string
    {
        $from = $repairOrder->getStatus();
        if ($from === $to) {
            return null;
        }
        $number = $this->numbers->repairOrderNumber($repairOrder);
        if ($from === RepairOrderStatus::Invoiced) {
            throw new \DomainException(sprintf('%s is paid, so it is locked. Mark its invoice unpaid or cancelled first (a refund) and it unlocks.', $number));
        }
        $live = array_values(array_filter($this->invoices->findForRepairOrder($repairOrder), static fn (Invoice $invoice): bool => $invoice->getStatus() !== InvoiceStatus::Cancelled));
        if ($to === RepairOrderStatus::Invoiced && array_filter($live, static fn (Invoice $invoice): bool => $invoice->isPaid()) === []) {
            throw new \DomainException(sprintf('%s has no paid invoice, so it cannot be Invoiced. Issue an invoice and mark it Paid: the repair order then becomes Invoiced by itself.', $number));
        }
        if ($to === RepairOrderStatus::CompletedPickedUp && $live === []) {
            throw new \DomainException(sprintf('%s has no invoice yet. Issue the invoice first (Invoices tab, + Issue Invoice), then mark it Completed, Picked Up.', $number));
        }
        $fromRank = array_search($from, self::FINISHED, true);
        $toRank = array_search($to, self::FINISHED, true);
        if ($fromRank === false || $live === [] || ($toRank !== false && $toRank > $fromRank)) {
            return null;
        }

        return sprintf(
            '%s is %s and has %s %s. Moving it to %s does not change %s%s. Move it anyway?',
            $number,
            $from->label(),
            count($live) === 1 ? 'invoice' : count($live) . ' invoices',
            implode(', ', array_map(fn (Invoice $invoice): string => $this->numbers->number($invoice), $live)),
            $to->label(),
            count($live) === 1 ? 'it' : 'them',
            $to === RepairOrderStatus::Cancelled ? ', and its pending appointments are cancelled' : ', and its appointments stay as they are',
        );
    }

    /**
     * Moves it, once confirmed when a question is due.
     *
     * @return string|null the question when it still has to be confirmed (nothing changed)
     *
     * @throws \DomainException when the move is refused
     */
    public function move(RepairOrder $repairOrder, RepairOrderStatus $to, bool $confirmed = false): ?string
    {
        $question = $this->confirmationFor($repairOrder, $to);
        if ($question !== null && !$confirmed) {
            return $question;
        }
        if ($repairOrder->getStatus() !== $to) {
            $repairOrder->setStatus($to);
            $repairOrder->touch();
            $this->entityManager->flush();
        }

        return null;
    }
}
