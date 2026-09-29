<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Accounting\InvoiceCalculator;
use App\Maxeme\Accounting\InvoiceSettings;
use App\Maxeme\Dto\InvoiceData;
use App\Maxeme\Dto\InvoiceItemData;
use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\InvoicePartLine;
use App\Maxeme\Entity\InvoiceServiceLine;
use App\Maxeme\Entity\Part;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\InvoiceSaveIntent;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Repository\InvoiceRepository;
use App\Maxeme\Schedule\ScheduleSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Invoice creation and saving (legacy AppointmentToInvoiceFactory, InvoiceArrayToEntityFactory,
 * blankWorkOrderAction). Saving rebuilds the lines from the posted table, recalculates the totals,
 * moves the appointment's status with the invoice's, and reconciles the stock (StockLedger).
 */
final class InvoiceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceCalculator $calculator,
        private readonly InvoiceSettings $settings,
        private readonly StockLedger $stock,
        private readonly ScheduleSettings $schedule,
    ) {
    }

    /** The appointment's invoice, or a new unsaved one for it (saved by the first Save). */
    public function forAppointment(Appointment $appointment): Invoice
    {
        return $this->invoices->findOneByAppointment($appointment)
            ?? Invoice::forAppointment($appointment, $this->settings->gstRate, $this->settings->pstRate);
    }

    /** The client's blank work order, for $vehicle or the client's first vehicle. Never saved. */
    public function blankWorkOrder(Client $client, ?Vehicle $vehicle = null): Invoice
    {
        return Invoice::forClient($client, $vehicle ?? $client->getVehicles()->first() ?: null, $this->settings->gstRate, $this->settings->pstRate);
    }

    /** @param InvoiceData $data already validated */
    public function save(Invoice $invoice, InvoiceData $data, InvoiceSaveIntent $intent): void
    {
        $accessor = PropertyAccess::createPropertyAccessor();
        foreach ($data->snapshot as $property => $value) {
            $accessor->setValue($invoice, $property, $value);
        }
        $this->updateVehicle($invoice);

        $invoice->setPaymentMethod($data->paymentMethod);
        $invoice->setPaymentAmount($data->paymentAmount);
        $invoice->setStatus($intent->status($data));
        $invoice->setLastModified(match (true) {
            $data->invoiceDate !== null => \DateTimeImmutable::createFromFormat(InvoiceData::DATE_FORMAT, $data->invoiceDate, $this->schedule->timezone())
                ->setTimezone(new \DateTimeZone('UTC')),
            $invoice->isPaid() => $invoice->getLastModified() ?? new \DateTimeImmutable(),
            default => $invoice->getLastModified(),
        });

        $this->replaceLines($invoice, $data->items);

        $discount = $data->negativeDiscount();
        $gst = $this->settings->gst($data->gstRate);
        $pst = $this->settings->pst($data->pstRate);
        $invoice->applyTotals($this->calculator->calculate($invoice, $discount, $gst, $pst), $discount, $gst, $pst);

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        $this->stock->reconcileInvoice($invoice);
        $this->entityManager->flush();
    }

    /** Deleting an appointment deletes its invoice (legacy orphanRemoval), after giving back any stock it took. */
    public function deleteFor(Appointment $appointment): void
    {
        $invoice = $this->invoices->findOneByAppointment($appointment);
        if ($invoice === null) {
            return;
        }

        $invoice->setStatus(InvoiceStatus::Unpaid);
        $this->stock->reconcileInvoice($invoice);
        $this->entityManager->remove($invoice);
    }

    /** @param list<InvoiceItemData> $items */
    private function replaceLines(Invoice $invoice, array $items): void
    {
        $invoice->clearLines();

        foreach ($items as $item) {
            if ($item->isService()) {
                $line = new InvoiceServiceLine($invoice, $item->name, $item->quantity, $item->price, $this->find(ServiceItem::class, $item->id));
                $invoice->addServiceLine($line);

                foreach ($item->parts as $material) {
                    $part = $this->find(Part::class, $material['id']);
                    $invoice->addPartLine(new InvoicePartLine($invoice, $material['name'] ?? $part?->getName(), $material['quantity'], $part?->getUnitPrice(), null, $part, $line));
                }
            } else {
                $part = $this->find(Part::class, $item->id);
                $invoice->addPartLine(new InvoicePartLine($invoice, $item->name, $item->quantity, $part?->getUnitPrice(), $item->price, $part));
            }
        }
    }

    /** A mileage or licence typed on the invoice is kept on the vehicle too (legacy setupVehicleInfo). */
    private function updateVehicle(Invoice $invoice): void
    {
        $vehicle = $invoice->getVehicle();
        if ($vehicle === null) {
            return;
        }
        if ($invoice->getVehicleMileage() !== null && $invoice->getVehicleMileage() !== $vehicle->getMileage()) {
            $vehicle->setMileage($invoice->getVehicleMileage());
        }
        if ($invoice->getVehicleLicense() !== null && $invoice->getVehicleLicense() !== $vehicle->getLicensePlate()) {
            $vehicle->setLicensePlate($invoice->getVehicleLicense());
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function find(string $class, ?int $id): ?object
    {
        return $id !== null ? $this->entityManager->find($class, $id) : null;
    }
}
