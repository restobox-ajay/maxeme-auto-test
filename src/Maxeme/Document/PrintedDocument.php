<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

/**
 * A document as printed, shown and emailed, whatever it is made from: an invoice (its frozen copy),
 * or a repair order's quote or work order (live). PrintedDocuments builds them; the templates under
 * maxeme/document/ render them for the page and the PDF alike.
 *
 * Rows are what the customer is billed for: each service by its name only (no labour / parts
 * breakdown), each charge-through line under its service with its quantity and price per unit,
 * and parts billed on their own (legacy invoices). Amounts are dollar strings ("12.50").
 */
final class PrintedDocument
{
    /**
     * @param list<string>                                                                                         $phones   Phone 1, 2, …
     * @param list<array{name: string, quantity: ?string, unitPrice: ?string, amount: string, chargeThrough: bool}> $rows
     * @param list<array{label: string, amount: string}>                                                           $charges  custom fees (positive) and discounts (negative)
     * @param list<array{name: string, lines: list<string>}>                                                         $repairItems the work order's items: each service and what to use ("x2 Rotor, front")
     * @param array{type: ?string, amount: ?string, change: string}|null                                           $payment  an invoice's
     */
    public function __construct(
        public readonly DocumentKind $kind,
        public readonly string $number,
        public readonly \DateTimeImmutable $date,
        public readonly ?string $status,
        public readonly string $clientName,
        public readonly array $phones,
        public readonly ?string $address,
        public readonly ?string $email,
        public readonly ?string $vehicleYear,
        public readonly ?string $vehicleMake,
        public readonly ?string $vehicleModel,
        public readonly ?string $vehicleVin,
        public readonly ?string $vehicleLicense,
        public readonly ?string $mileage,
        public readonly array $rows,
        public readonly array $charges,
        public readonly string $discount,
        public readonly string $subtotal,
        public readonly int $gstRate,
        public readonly string $gst,
        public readonly int $pstRate,
        public readonly string $pst,
        public readonly string $total,
        public readonly ?array $payment,
        public readonly ?string $note,
        public readonly ?string $recommendations,
        public readonly ?string $masterTechnician,
        public readonly array $repairItems,
    ) {
    }

    /** "INV-00001482.pdf" */
    public function filename(): string
    {
        return sprintf('%s.pdf', $this->number);
    }

    /** "Invoice INV-00001482" / "Quote QO-00000042" / "Work Order WO-00000042" */
    public function emailSubject(): string
    {
        return sprintf('%s %s', $this->kind->emailTitle(), $this->number);
    }

    /** "2011 TOYOTA CAMRY" */
    public function vehicleName(): string
    {
        return trim(sprintf('%s %s %s', $this->vehicleYear, $this->vehicleMake, $this->vehicleModel));
    }
}
