<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Accounting\LineTax;
use App\Entity\ProductCore;
use App\Maxeme\Accounting\InvoiceCalculator;
use App\Maxeme\Accounting\Money;
use App\Maxeme\Accounting\InvoiceSettings;
use App\Maxeme\Dto\InvoiceData;
use App\Maxeme\Dto\InvoiceItemData;
use App\Maxeme\Entity\Appointment;
use App\Maxeme\Entity\Client;
use App\Maxeme\Entity\ClientAddress;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\InvoicePartLine;
use App\Maxeme\Entity\InvoiceServiceLine;
use App\Maxeme\Entity\PaymentType;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\Vehicle;
use App\Maxeme\Enum\InvoiceSaveIntent;
use App\Maxeme\Repository\InvoiceRepository;
use App\Maxeme\Schedule\ScheduleSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Invoice creation and saving (legacy AppointmentToInvoiceFactory, InvoiceArrayToEntityFactory,
 * blankWorkOrderAction). Saving rebuilds the lines from the posted table (parts are products of
 * core's catalogue), recalculates the totals and moves the appointment's status with the invoice's.
 * It moves no stock: product stock is held by repair order status, which is not built yet.
 */
final class InvoiceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceCalculator $calculator,
        private readonly InvoiceSettings $settings,
        private readonly ScheduleSettings $schedule,
    ) {
    }

    /** The appointment's invoice, or a new unsaved one for it (saved by the first Save). */
    public function forAppointment(Appointment $appointment): Invoice
    {
        return $this->invoices->findOneByAppointment($appointment)
            ?? Invoice::forAppointment($appointment, $this->settings->gstRate(), $this->settings->pstRate());
    }

    /** The client's blank work order, for $vehicle or the client's first vehicle. Never saved. */
    public function blankWorkOrder(Client $client, ?Vehicle $vehicle = null, ?ClientAddress $address = null): Invoice
    {
        return Invoice::forClient($client, $vehicle ?? $client->getVehicles()->first() ?: null, $this->settings->gstRate(), $this->settings->pstRate(), $address);
    }

    /** @param InvoiceData $data already validated */
    public function save(Invoice $invoice, InvoiceData $data, InvoiceSaveIntent $intent): void
    {
        $accessor = PropertyAccess::createPropertyAccessor();
        foreach ($data->snapshot as $property => $value) {
            $accessor->setValue($invoice, $property, $value);
        }
        $this->updateVehicle($invoice);

        $invoice->setPaymentType($this->find(PaymentType::class, $data->paymentTypeId));
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
    }

    /** Deleting an appointment deletes its invoice (legacy orphanRemoval). */
    public function deleteFor(Appointment $appointment): void
    {
        $invoice = $this->invoices->findOneByAppointment($appointment);
        if ($invoice === null) {
            return;
        }

        $this->entityManager->remove($invoice);
    }

    /**
     * The builder posts every service and part again; its charge-through lines are not posted. A
     * service already on the invoice keeps the charge-through lines it had (an old invoice's total
     * doesn't move when the catalogue changes); a service added now brings its catalogue service's
     * charge-through lines, and is billed under the service's Label when it kept the service's name.
     *
     * @param list<InvoiceItemData> $items
     */
    private function replaceLines(Invoice $invoice, array $items): void
    {
        $kept = [];
        foreach ($invoice->getTopServiceLines() as $old) {
            $serviceId = $old->getService()?->getId();
            if ($serviceId !== null && !isset($kept[$serviceId])) {
                $kept[$serviceId] = [$old->getTax(), array_map(
                    static fn (InvoiceServiceLine $line): array => [(string) $line->getName(), $line->getQuantity(), $line->getSalePrice(), $line->getTax()],
                    $invoice->getChargeThroughLines($old),
                )];
            }
        }
        $invoice->clearLines();

        foreach ($items as $item) {
            if ($item->isService()) {
                $service = $this->find(ServiceItem::class, $item->id);
                $isNew = $service === null || !array_key_exists($service->getId(), $kept);
                $line = new InvoiceServiceLine($invoice, $isNew ? self::billedName($service, $item->name) : $item->name, (string) $item->quantity, $item->price, $service);
                $line->setTax($isNew ? LineTax::of($service?->getTaxClass()) : $kept[$service->getId()][0]);
                $invoice->addServiceLine($line);
                $chargeThrough = match (true) {
                    $service === null => [],
                    !$isNew => $kept[$service->getId()][1],
                    default => self::catalogueChargeThrough($service, (string) $item->quantity, $line->getTax()),
                };
                foreach ($chargeThrough as [$name, $quantity, $price, $tax]) {
                    $invoice->addServiceLine((new InvoiceServiceLine($invoice, mb_substr($name, 0, 255), $quantity, $price, null, $line))->setTax($tax));
                }
                unset($kept[$service?->getId()]);

                foreach ($item->parts as $material) {
                    $product = $this->find(ProductCore::class, $material['id']);
                    $invoice->addPartLine(new InvoicePartLine($invoice, $material['name'] ?? $product?->getName(), $material['quantity'], self::cost($product), null, $product, $line));
                }
            } else {
                $product = $this->find(ProductCore::class, $item->id);
                $invoice->addPartLine(new InvoicePartLine($invoice, $item->name, $item->quantity, self::cost($product), $item->price, $product));
            }
        }
    }

    /** The service's Label (its printed name) when the line kept the service's name, as picked; else the name as typed. */
    private static function billedName(?ServiceItem $service, ?string $name): ?string
    {
        $label = $service?->getPreferredName();
        if ($service === null || $label === null || $label === '') {
            return $name;
        }

        return in_array(trim((string) $name), [$service->getName(), $service->getFullName()], true) ? $label : $name;
    }

    /**
     * The catalogue service's charge-through lines, for $quantity of the service.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: LineTax}> name, quantity, price of one, taxes
     */
    private static function catalogueChargeThrough(ServiceItem $service, string $quantity, LineTax $serviceTax): array
    {
        $lines = [];
        foreach ($service->getLines() as $line) {
            if ($line->isChargeThrough()) {
                $lines[] = [$line->getItemLabel(), number_format((float) $line->getQuantity() * max(1.0, (float) $quantity), 2, '.', ''), $line->getUnitPrice(), LineTax::ofLine($line, $serviceTax)];
            }
        }

        return $lines;
    }

    /** The product's cost, in dollars to the cent (core keeps 6 decimals): the line's material cost. */
    private static function cost(?ProductCore $product): ?string
    {
        return $product?->getCostPrice() !== null ? Money::fromCents(Money::toCents($product->getCostPrice())) : null;
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
