<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Accounting\InvoiceTotals;
use App\Maxeme\Accounting\Money;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Enum\PaymentMethod;
use App\Maxeme\Repository\InvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An appointment's bill (legacy CNSAccountingBundle Invoice). The client and vehicle details are a
 * snapshot taken when it was created and edited on the invoice itself. Totals are always
 * calculated from the lines (App\Maxeme\Accounting\InvoiceCalculator). Ids are the legacy ids; the
 * shown number is the id padded to 8 digits.
 */
#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'maxeme_invoice')]
#[ORM\UniqueConstraint(name: 'uniq_maxeme_invoice_key', fields: ['invoiceKey'])]
#[ORM\Index(name: 'idx_maxeme_invoice_last_modified', columns: ['last_modified'])]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The key in the invoice's URLs (legacy uniqid()). */
    #[ORM\Column(length: 40)]
    private string $invoiceKey;

    #[ORM\OneToOne(targetEntity: Appointment::class)]
    #[ORM\JoinColumn(unique: true, onDelete: 'SET NULL')]
    private ?Appointment $appointment = null;

    /** The repair order it bills (set when legacy invoices were converted, and by Issue Invoice). */
    #[ORM\ManyToOne(targetEntity: RepairOrder::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RepairOrder $repairOrder = null;

    /** Issued from a repair order (a copy of its services, charge-through lines and charges), not built in the legacy invoice builder. */
    #[ORM\Column(options: ['default' => false])]
    private bool $issuedFromRepairOrder = false;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    private ?Client $client = null;

    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    private ?Vehicle $vehicle = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientFirstName = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientLastName = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientPreferredName = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientAddress = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientHomeNumber = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientCellNumber = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientNote = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vehicleYear = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vehicleManufacturer = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vehicleModel = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vehicleLicense = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vehicleVin = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vehicleMileage = null;

    /** The appointment's note. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $recommendations = null;

    #[ORM\Column(length: 10, enumType: InvoiceStatus::class)]
    private InvoiceStatus $status = InvoiceStatus::Unpaid;

    /** Config › Settings › Payment Types. */
    #[ORM\ManyToOne(targetEntity: PaymentType::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?PaymentType $paymentType = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $paymentAmount = null;

    /** Stored negative, as the legacy builder forced it. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => 0])]
    private string $discountAmount = '0.00';

    #[ORM\Column(options: ['default' => 0])]
    private int $gstRate = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $pstRate = 0;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => 0])]
    private string $subtotal = '0.00';

    /** GST + PST. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => 0])]
    private string $salesTax = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => 0])]
    private string $totalPrice = '0.00';

    #[ORM\Column]
    private \DateTimeImmutable $createdOn;

    /** The invoice date ("Invoice created on" / "Completed On"): set when it is first paid, or typed. The report filters on it. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastModified = null;

    /** @var Collection<int, InvoiceServiceLine> */
    #[ORM\OneToMany(targetEntity: InvoiceServiceLine::class, mappedBy: 'invoice', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $serviceLines;

    /** @var Collection<int, InvoiceCharge> custom fees and discounts, under the subtotal */
    #[ORM\OneToMany(targetEntity: InvoiceCharge::class, mappedBy: 'invoice', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $charges;

    /** @var Collection<int, InvoicePartLine> every part, standalone and service materials */
    #[ORM\OneToMany(targetEntity: InvoicePartLine::class, mappedBy: 'invoice', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $partLines;

    private function __construct(int $gstRate, int $pstRate)
    {
        $this->invoiceKey = bin2hex(random_bytes(8));
        $this->createdOn = new \DateTimeImmutable();
        $this->gstRate = $gstRate;
        $this->pstRate = $pstRate;
        $this->serviceLines = new ArrayCollection();
        $this->partLines = new ArrayCollection();
        $this->charges = new ArrayCollection();
    }

    /**
     * A new invoice for a repair order (Issue Invoice): its customer and vehicle copied in as on any
     * invoice, its mileage and tax rates. The services and charges are added by RepairOrderInvoicing.
     */
    public static function forRepairOrder(RepairOrder $repairOrder): self
    {
        $client = $repairOrder->getClient();
        $invoice = $client !== null
            ? self::forClient($client, $repairOrder->getVehicle(), $repairOrder->getGstRate(), $repairOrder->getPstRate())
            : new self($repairOrder->getGstRate(), $repairOrder->getPstRate());
        $invoice->repairOrder = $repairOrder;
        $invoice->issuedFromRepairOrder = true;
        $invoice->vehicleMileage = $repairOrder->getMileage() ?? $invoice->vehicleMileage;
        $invoice->note = $repairOrder->getConcern();

        return $invoice;
    }

    /** A new invoice for an appointment, with its client and vehicle copied in (legacy AppointmentToInvoiceFactory). */
    public static function forAppointment(Appointment $appointment, int $gstRate, int $pstRate): self
    {
        $invoice = self::forClient($appointment->getClient(), $appointment->getVehicle(), $gstRate, $pstRate);
        $invoice->appointment = $appointment;
        $invoice->note = $appointment->getNote();

        return $invoice;
    }

    /**
     * An invoice for a client's vehicle with no appointment: the blank "Empty Work Order", never saved.
     * The address is $address, or the first in the client's address book; the invoice's two phone
     * columns (legacy home / cell) take phone 1 and 2, its client note the newest note.
     */
    public static function forClient(Client $client, ?Vehicle $vehicle, int $gstRate, int $pstRate, ?ClientAddress $address = null): self
    {
        $invoice = new self($gstRate, $pstRate);
        $invoice->client = $client;
        $invoice->clientFirstName = $client->getFirstName();
        $invoice->clientLastName = $client->getLastName();
        $invoice->clientPreferredName = $client->getPreferredName();
        $invoice->useAddress($address ?? $client->getPrimaryAddress());
        $invoice->clientHomeNumber = $client->getPhone1();
        $invoice->clientCellNumber = $client->getPhone2();
        $note = $client->getLatestNote()?->getText();
        $invoice->clientNote = $note !== null ? mb_substr($note, 0, 255) : null;
        if ($vehicle !== null) {
            $invoice->useVehicle($vehicle);
        }

        return $invoice;
    }

    /** Copies $address in, on one line (the blank work order's address switch). */
    public function useAddress(?ClientAddress $address): void
    {
        $this->clientAddress = $address !== null ? mb_substr($address->getOneLine(), 0, 255) : null;
    }

    /** Copies $vehicle's details in (the blank work order's vehicle switch). */
    public function useVehicle(Vehicle $vehicle): void
    {
        $this->vehicle = $vehicle;
        $this->vehicleYear = $vehicle->getYear() !== null ? (string) $vehicle->getYear() : null;
        $this->vehicleManufacturer = $vehicle->getManufacturer();
        $this->vehicleModel = $vehicle->getModel();
        $this->vehicleLicense = $vehicle->getLicensePlate();
        $this->vehicleVin = $vehicle->getVin();
        $this->vehicleMileage = $vehicle->getMileage();
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoiceKey(): string { return $this->invoiceKey; }
    public function getAppointment(): ?Appointment { return $this->appointment; }
    public function getRepairOrder(): ?RepairOrder { return $this->repairOrder; }
    public function isIssuedFromRepairOrder(): bool { return $this->issuedFromRepairOrder; }
    public function getClient(): ?Client { return $this->client; }
    public function getVehicle(): ?Vehicle { return $this->vehicle; }

    /** The number shown and printed: "00001482" (legacy getDisplayedId()). */
    public function getDisplayedId(): string
    {
        return $this->id !== null ? str_pad((string) $this->id, 8, '0', STR_PAD_LEFT) : '';
    }

    public function isSaved(): bool
    {
        return $this->id !== null;
    }

    public function getClientFirstName(): ?string { return $this->clientFirstName; }
    public function setClientFirstName(?string $value): void { $this->clientFirstName = $value; }
    public function getClientLastName(): ?string { return $this->clientLastName; }
    public function setClientLastName(?string $value): void { $this->clientLastName = $value; }
    public function getClientPreferredName(): ?string { return $this->clientPreferredName; }
    public function setClientPreferredName(?string $value): void { $this->clientPreferredName = $value; }
    public function getClientAddress(): ?string { return $this->clientAddress; }
    public function setClientAddress(?string $value): void { $this->clientAddress = $value; }
    public function getClientHomeNumber(): ?string { return $this->clientHomeNumber; }
    public function setClientHomeNumber(?string $value): void { $this->clientHomeNumber = $value; }
    public function getClientCellNumber(): ?string { return $this->clientCellNumber; }
    public function getClientNote(): ?string { return $this->clientNote; }
    public function setClientNote(?string $value): void { $this->clientNote = $value; }

    /** "First Last (Preferred)", as on the client. */
    public function getClientFullName(): string
    {
        $name = trim(sprintf('%s %s', $this->clientFirstName, $this->clientLastName));

        return $this->clientPreferredName !== null && $this->clientPreferredName !== '' ? sprintf('%s (%s)', $name, $this->clientPreferredName) : $name;
    }

    public function getVehicleYear(): ?string { return $this->vehicleYear; }
    public function setVehicleYear(?string $value): void { $this->vehicleYear = $value; }
    public function getVehicleManufacturer(): ?string { return $this->vehicleManufacturer; }
    public function setVehicleManufacturer(?string $value): void { $this->vehicleManufacturer = $value; }
    public function getVehicleModel(): ?string { return $this->vehicleModel; }
    public function setVehicleModel(?string $value): void { $this->vehicleModel = $value; }
    public function getVehicleLicense(): ?string { return $this->vehicleLicense; }
    public function setVehicleLicense(?string $value): void { $this->vehicleLicense = $value; }
    public function getVehicleVin(): ?string { return $this->vehicleVin; }
    public function setVehicleVin(?string $value): void { $this->vehicleVin = $value; }
    public function getVehicleMileage(): ?string { return $this->vehicleMileage; }
    public function setVehicleMileage(?string $value): void { $this->vehicleMileage = $value; }

    /** "Year Make Model". */
    public function getVehicleFullName(): string
    {
        return trim(sprintf('%s %s %s', $this->vehicleYear, $this->vehicleManufacturer, $this->vehicleModel));
    }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $value): void { $this->note = $value; }
    public function getRecommendations(): ?string { return $this->recommendations; }
    public function setRecommendations(?string $value): void { $this->recommendations = $value; }

    public function getStatus(): InvoiceStatus { return $this->status; }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
    }

    /** Paid or not; the appointment follows (paid → complete, unpaid → in progress), as in the legacy save. */
    public function setStatus(InvoiceStatus $status): void
    {
        $this->status = $status;
        $status === InvoiceStatus::Paid ? $this->appointment?->complete() : $this->appointment?->reopen();
    }

    public function getPaymentType(): ?PaymentType { return $this->paymentType; }
    public function setPaymentType(?PaymentType $value): void { $this->paymentType = $value; }
    public function getPaymentAmount(): ?string { return $this->paymentAmount; }
    public function setPaymentAmount(?string $value): void { $this->paymentAmount = $value; }

    public function getGstRate(): int { return $this->gstRate; }
    public function getPstRate(): int { return $this->pstRate; }
    public function getDiscountAmount(): string { return $this->discountAmount; }
    public function getSubtotal(): string { return $this->subtotal; }
    public function getSalesTax(): string { return $this->salesTax; }
    public function getTotalPrice(): string { return $this->totalPrice; }

    public function getGstAmount(): string
    {
        return Money::fromCents(Money::percentOf(Money::toCents($this->subtotal), $this->gstRate));
    }

    public function getPstAmount(): string
    {
        return Money::fromCents(Money::percentOf(Money::toCents($this->subtotal), $this->pstRate));
    }

    /** Payment − total: negative when under-paid, −total when nothing was paid (legacy getChangeAmount()). */
    public function getChangeAmount(): string
    {
        return Money::fromCents(Money::toCents($this->paymentAmount) - Money::toCents($this->totalPrice));
    }

    /** Material cost: every part's cost × quantity, the services' parts included (legacy getExpenseAmount()). */
    public function getExpenseAmount(): string
    {
        return Money::fromCents(array_sum(array_map(static fn (InvoicePartLine $line): int => $line->costCents(), $this->partLines->toArray())));
    }

    /**
     * Total − sales tax − material cost. The legacy getNetAmount() also added the discount, which
     * the total already includes, so it counted every discount twice.
     */
    public function getNetAmount(): string
    {
        return Money::fromCents(Money::toCents($this->totalPrice) - Money::toCents($this->salesTax) - Money::toCents($this->getExpenseAmount()));
    }

    public function getCreatedOn(): \DateTimeImmutable { return $this->createdOn; }
    public function getLastModified(): ?\DateTimeImmutable { return $this->lastModified; }
    public function setLastModified(?\DateTimeImmutable $value): void { $this->lastModified = $value; }

    /** The date printed on the documents: the invoice date, or when it was created. */
    public function getDocumentDate(): \DateTimeImmutable
    {
        return $this->lastModified ?? $this->createdOn;
    }

    /** @return Collection<int, InvoiceServiceLine> */
    public function getServiceLines(): Collection { return $this->serviceLines; }

    /** @return Collection<int, InvoicePartLine> */
    public function getPartLines(): Collection { return $this->partLines; }

    /** @return list<InvoiceServiceLine> the services, without the charge-through lines billed under them */
    public function getTopServiceLines(): array
    {
        return array_values(array_filter($this->serviceLines->toArray(), static fn (InvoiceServiceLine $line): bool => !$line->isChargeThrough()));
    }

    /** @return list<InvoiceServiceLine> the charge-through lines billed under $service */
    public function getChargeThroughLines(InvoiceServiceLine $service): array
    {
        return array_values(array_filter($this->serviceLines->toArray(), static fn (InvoiceServiceLine $line): bool => $line->getParent() === $service));
    }

    /** @return list<InvoiceCharge> in order */
    public function getCharges(): array { return array_values($this->charges->toArray()); }

    public function addCharge(InvoiceCharge $charge): void
    {
        $charge->setPosition($this->charges->count());
        $this->charges->add($charge);
    }

    /** Removes every line and charge (an issued invoice's edit posts them all again). */
    public function clearCharges(): void
    {
        $this->charges->clear();
    }

    /** Σ the billed lines (services, charge-through lines and parts billed on their own), before the discount and charges. */
    public function getLinesTotal(): string
    {
        return Money::fromCents(
            array_sum(array_map(static fn (InvoiceServiceLine $line): int => $line->totalCents(), $this->serviceLines->toArray()))
            + array_sum(array_map(static fn (InvoicePartLine $line): int => $line->totalCents(), $this->getStandalonePartLines())),
        );
    }

    /** Every discount, negative: the legacy one and the custom discount lines (the Summary Report's Discount). */
    public function getDiscountTotal(): string
    {
        $charges = array_sum(array_map(static fn (InvoiceCharge $charge): int => min(0, $charge->getSignedCents()), $this->getCharges()));

        return Money::fromCents(Money::toCents($this->discountAmount) + $charges);
    }

    /** @return list<InvoicePartLine> the parts billed on their own lines */
    public function getStandalonePartLines(): array
    {
        return array_values(array_filter($this->partLines->toArray(), static fn (InvoicePartLine $line): bool => $line->isStandalone()));
    }

    /** Replaces every line (the builder posts the whole table each time). */
    public function clearLines(): void
    {
        $this->serviceLines->clear();
        $this->partLines->clear();
    }

    public function addServiceLine(InvoiceServiceLine $line): void
    {
        $this->serviceLines->add($line);
    }

    /** Removes a line, with the charge-through lines billed under it. */
    public function removeServiceLine(InvoiceServiceLine $line): void
    {
        foreach ($this->getChargeThroughLines($line) as $child) {
            $this->serviceLines->removeElement($child);
        }
        $this->serviceLines->removeElement($line);
    }

    /** @param list<InvoiceCharge> $charges this invoice's, in order; one left out is removed */
    public function replaceCharges(array $charges): void
    {
        foreach ($this->charges as $charge) {
            if (!in_array($charge, $charges, true)) {
                $this->charges->removeElement($charge);
            }
        }
        foreach ($charges as $position => $charge) {
            $charge->setPosition($position);
            if (!$this->charges->contains($charge)) {
                $this->charges->add($charge);
            }
        }
    }

    public function addPartLine(InvoicePartLine $line): void
    {
        $this->partLines->add($line);
        $line->getServiceLine()?->getParts()->add($line);
    }

    /** @param int $gstRate / $pstRate percent */
    public function applyTotals(InvoiceTotals $totals, string $discount, int $gstRate, int $pstRate): void
    {
        $this->discountAmount = $discount;
        $this->gstRate = $gstRate;
        $this->pstRate = $pstRate;
        $this->subtotal = $totals->subtotal;
        $this->salesTax = $totals->salesTax;
        $this->totalPrice = $totals->total;
    }
}
