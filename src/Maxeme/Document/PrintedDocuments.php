<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

use App\Maxeme\Accounting\Money;
use App\Maxeme\Accounting\RepairOrderCalculator;
use App\Maxeme\Accounting\LineTax;
use App\Maxeme\Accounting\PartTaxes;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Enum\MileageUnit;
use App\Maxeme\Enum\RepairOrderStatus;
use App\Maxeme\Enum\WorkStatus;
use App\Maxeme\Validation\ContactFormat;
use App\Maxeme\Entity\InvoiceCharge;
use App\Maxeme\Entity\InvoiceServiceLine;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\RepairOrderCharge;
use App\Maxeme\Entity\RepairOrderJob;
use App\Maxeme\Entity\RepairOrderJobLine;
use App\Maxeme\Service\InvoicePayments;

/**
 * Builds the PrintedDocument of an invoice (from its frozen lines), and of a repair order's quote
 * and work order (from the repair order as it is now).
 */
final class PrintedDocuments
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly PartTaxes $partTaxes,
        private readonly InvoicePayments $payments,
    ) {
    }

    public function invoice(Invoice $invoice): PrintedDocument
    {
        $rows = [];
        foreach ($invoice->getTopServiceLines() as $service) {
            $rows[] = self::row((string) $service->getName(), null, null, $service->totalCents(), false, $service->getTax());
            foreach ($invoice->getChargeThroughLines($service) as $line) {
                $rows[] = self::row((string) $line->getName(), $line->getQuantity(), $line->getSalePrice(), $line->totalCents(), true, $line->getTax());
            }
        }
        foreach ($invoice->getStandalonePartLines() as $part) {
            $rows[] = self::row((string) $part->getName(), (string) $part->getQuantity(), $part->getSalePrice(), $part->totalCents(), false);
        }

        return new PrintedDocument(
            kind: DocumentKind::Invoice,
            number: $this->numbers->number($invoice),
            date: $invoice->getDocumentDate(),
            status: $invoice->getStatus()->label(),
            clientName: $invoice->getClientFullName(),
            phones: array_map(ContactFormat::phone(...), array_values(array_filter([$invoice->getClientHomeNumber(), $invoice->getClientCellNumber()], static fn (?string $phone): bool => $phone !== null && $phone !== ''))),
            address: $invoice->getClientAddress(),
            email: $invoice->getClient()?->getEmail(),
            vehicleYear: $invoice->getVehicleYear(),
            vehicleMake: $invoice->getVehicleManufacturer(),
            vehicleModel: $invoice->getVehicleModel(),
            vehicleVin: $invoice->getVehicleVin(),
            vehicleLicense: $invoice->getVehicleLicense(),
            mileage: $invoice->getVehicleMileage(),
            mileageUnit: $invoice->getVehicleMileageUnit()->label(),
            rows: $rows,
            charges: array_map(static fn (InvoiceCharge $charge): array => ['label' => $charge->getLabel(), 'amount' => Money::fromCents($charge->getSignedCents())], $invoice->getCharges()),
            discount: $invoice->getDiscountAmount(),
            subtotal: $invoice->getLinesTotal(),
            gstRate: $invoice->getGstRate(),
            gst: $invoice->getGstAmount(),
            pstRate: $invoice->getPstRate(),
            pst: $invoice->getPstAmount(),
            total: $invoice->getTotalPrice(),
            payment: $this->payments->summary($invoice),
            note: $invoice->getNote(),
            recommendations: $invoice->getRecommendations(),
            masterTechnician: $invoice->getRepairOrder()?->getMasterTechnician()?->getName(),
            repairItems: array_map(static fn (InvoiceServiceLine $line): array => ['name' => (string) $line->getName(), 'lines' => []], $invoice->getTopServiceLines()),
        );
    }

    public function quote(RepairOrder $repairOrder): PrintedDocument
    {
        return $this->fromRepairOrder($repairOrder, DocumentKind::Quote);
    }

    public function workOrder(RepairOrder $repairOrder): PrintedDocument
    {
        return $this->fromRepairOrder($repairOrder, DocumentKind::WorkOrder);
    }

    /**
     * The name a service is billed under: the catalogue service's Label (its printed name) when the
     * repair order kept the service's own name, else the name typed on the repair order.
     */
    public static function billedName(RepairOrderJob $job): string
    {
        $service = $job->getService();
        $label = $service?->getPreferredName();

        return $service !== null && $label !== null && $label !== '' && $job->getName() === $service->getName() ? $label : $job->getName();
    }

    private function fromRepairOrder(RepairOrder $repairOrder, DocumentKind $kind): PrintedDocument
    {
        $rows = [];
        foreach ($repairOrder->getJobs() as $job) {
            $serviceTax = RepairOrderCalculator::serviceTax($job);
            $rows[] = self::row(self::billedName($job), null, null, Money::toCents($job->getPrice()), false, $serviceTax);
            foreach ($job->getChargeThroughLines() as $line) {
                $rows[] = self::row($line->getItemLabel(), $line->getQuantity(), $line->getUnitPrice(), $line->getExtendedCents(), true, LineTax::ofLine($line, $serviceTax, $this->partTaxes));
            }
        }
        $client = $repairOrder->getClient();
        $vehicle = $repairOrder->getVehicle();

        return new PrintedDocument(
            kind: $kind,
            number: $this->numbers->repairOrderNumber($repairOrder, $kind),
            date: $repairOrder->getLastUpdated(),
            status: $repairOrder->getStatus()->label(),
            clientName: (string) $client?->getFullName(),
            phones: array_map(ContactFormat::phone(...), $client?->getPhones() ?? []),
            address: $client?->getPrimaryAddress()?->getOneLine(),
            email: $client?->getEmail(),
            vehicleYear: $vehicle?->getYear() !== null ? (string) $vehicle->getYear() : null,
            vehicleMake: $vehicle?->getManufacturer(),
            vehicleModel: $vehicle?->getModel(),
            vehicleVin: $vehicle?->getVin(),
            vehicleLicense: $vehicle?->getLicensePlate(),
            mileage: $repairOrder->getMileage() ?? $vehicle?->getMileage(),
            mileageUnit: ($repairOrder->getMileage() !== null ? $repairOrder->getMileageUnit() : ($vehicle?->getMileageUnit() ?? MileageUnit::Km))->label(),
            rows: $rows,
            charges: array_map(static fn (RepairOrderCharge $charge): array => ['label' => $charge->getLabel(), 'amount' => Money::fromCents($charge->getSignedCents())], $repairOrder->getCharges()),
            discount: '0.00',
            subtotal: $repairOrder->getSubtotal(),
            gstRate: $repairOrder->getGstRate(),
            gst: $repairOrder->getGst(),
            pstRate: $repairOrder->getPstRate(),
            pst: $repairOrder->getPst(),
            total: $repairOrder->getTotal(),
            payment: null,
            note: $repairOrder->getConcern(),
            recommendations: $kind === DocumentKind::WorkOrder ? $repairOrder->getRecommendations() : null,
            masterTechnician: $repairOrder->getMasterTechnician()?->getName(),
            repairItems: array_map(static fn (RepairOrderJob $job): array => [
                'name' => self::billedName($job),
                'technician' => $job->getTechnician()?->getName(),
                'workStatus' => $job->getWorkStatus() !== WorkStatus::NotStarted ? $job->getWorkStatus()->label() : null,
                'lines' => array_map(static fn (RepairOrderJobLine $line): string => sprintf('x%s %s', (string) (float) $line->getQuantity(), $line->getType()->hasItem() ? $line->getItemLabel() : $line->getType()->label()), $job->getLines()),
            ], $repairOrder->getJobs()),
            firstPrinted: $kind === DocumentKind::WorkOrder ? $repairOrder->getWorkOrderFirstPrintedAt() : null,
            quoteStatus: $kind === DocumentKind::Quote ? self::quoteStatus($repairOrder) : null,
        );
    }

    /**
     * The quote's answer (mockup 345c037): Declined when it was turned down; Awaiting approval while the
     * repair order is still at the estimate (or cancelled some other way); else Accepted, with how and
     * when when that was recorded.
     *
     * @return array{state: string, via: ?string, at: ?\DateTimeImmutable}
     */
    private static function quoteStatus(RepairOrder $repairOrder): array
    {
        $declined = $repairOrder->getQuoteDeclinedAt();
        if ($repairOrder->getStatus() === RepairOrderStatus::Cancelled && $declined !== null) {
            return ['state' => 'declined', 'via' => null, 'at' => $declined];
        }
        if ($repairOrder->isQuoteOpen() || $repairOrder->getStatus() === RepairOrderStatus::Cancelled) {
            return ['state' => 'awaiting', 'via' => null, 'at' => null];
        }

        return ['state' => 'accepted', 'via' => $repairOrder->getQuoteApprovedVia()?->phrase(), 'at' => $repairOrder->getQuoteApprovedAt()];
    }

    /** @return array{name: string, quantity: ?string, unitPrice: ?string, amount: string, chargeThrough: bool, taxNote: ?string} */
    private static function row(string $name, ?string $quantity, ?string $unitPrice, int $cents, bool $chargeThrough, ?LineTax $tax = null): array
    {
        return [
            'name' => $name,
            'quantity' => $quantity !== null ? (string) (float) $quantity : null,
            'unitPrice' => $unitPrice !== null ? Money::fromCents(Money::toCents($unitPrice)) : null,
            'amount' => Money::fromCents($cents),
            'chargeThrough' => $chargeThrough,
            // "GST only" / "PST only" / "Tax exempt" when the line isn't taxed both.
            'taxNote' => $tax?->note(),
        ];
    }
}
