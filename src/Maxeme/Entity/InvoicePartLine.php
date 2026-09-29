<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Accounting\Money;
use Doctrine\ORM\Mapping as ORM;

/**
 * A part on an invoice (legacy InvoiceHasParts): either billed on its own line, or used by one of
 * the invoice's services (`serviceLine`), in which case it has no sale price.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_invoice_part')]
class InvoicePartLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdOn;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'partLines')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Invoice $invoice,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $name,
        #[ORM\Column(options: ['default' => 1])]
        private int $quantity = 1,
        /** The part's cost when it was added: the Summary Report's Material Cost. */
        #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
        private ?string $unitPrice = null,
        /** The price of one, as billed; none for a service's parts. */
        #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
        private ?string $salePrice = null,
        #[ORM\ManyToOne(targetEntity: Part::class)]
        private ?Part $part = null,
        #[ORM\ManyToOne(targetEntity: InvoiceServiceLine::class, inversedBy: 'parts')]
        #[ORM\JoinColumn(onDelete: 'CASCADE')]
        private ?InvoiceServiceLine $serviceLine = null,
    ) {
        $this->createdOn = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getName(): ?string { return $this->name; }
    public function getQuantity(): int { return $this->quantity; }
    public function getUnitPrice(): ?string { return $this->unitPrice; }
    public function getSalePrice(): ?string { return $this->salePrice; }
    public function getPart(): ?Part { return $this->part; }
    public function getServiceLine(): ?InvoiceServiceLine { return $this->serviceLine; }

    /** Billed on its own line (not a service's material). */
    public function isStandalone(): bool
    {
        return $this->serviceLine === null;
    }

    /** Quantity × price, in cents (0 for a service's parts). */
    public function totalCents(): int
    {
        return $this->isStandalone() ? $this->quantity * Money::toCents($this->salePrice) : 0;
    }

    /** Quantity × cost, in cents. */
    public function costCents(): int
    {
        return $this->quantity * Money::toCents($this->unitPrice);
    }
}
