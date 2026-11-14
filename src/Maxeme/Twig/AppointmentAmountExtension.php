<?php

declare(strict_types=1);

namespace App\Maxeme\Twig;

use App\Maxeme\Entity\Appointment;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Repository\InvoiceRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ appointment_amount(appointment) }}: the "$" of an appointment on the lists (mockup e0fde70): its
 * repair order's latest invoice that is not cancelled, else the appointment's own invoice, else its
 * repair order's total; with a link to that record. Null when there is none.
 */
final class AppointmentAmountExtension extends AbstractExtension
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('appointment_amount', $this->amount(...))];
    }

    /** @return array{amount: string, url: string, title: string}|null */
    public function amount(Appointment $appointment): ?array
    {
        $repairOrder = $appointment->getRepairOrder();
        $invoice = $repairOrder !== null ? $this->invoices->findCurrentForRepairOrder($repairOrder) : $this->invoices->findOneByAppointment($appointment);
        if ($invoice !== null && $invoice->getStatus() !== InvoiceStatus::Cancelled) {
            return [
                'amount' => $invoice->getTotalPrice(),
                'url' => $this->urls->generate('maxeme_invoice_show', ['invoiceKey' => $invoice->getInvoiceKey()]),
                'title' => sprintf('Invoice (%s)', $invoice->getStatus()->label()),
            ];
        }
        if ($repairOrder !== null) {
            return [
                'amount' => $repairOrder->getTotal(),
                'url' => $this->urls->generate('maxeme_repair_order_edit', ['id' => $repairOrder->getId()]),
                'title' => 'Repair order total (no invoice yet)',
            ];
        }

        return null;
    }
}
