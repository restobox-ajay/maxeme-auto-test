<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Accounting\LineTax;
use App\Maxeme\Accounting\Money;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A service on an invoice (legacy InvoiceHasServices): its name and price as billed, and the parts
 * it used. Those parts are materials: they move stock and count as cost, but are not billed.
 *
 * On an invoice issued from a repair order, a service's charge-through lines are lines of their own
 * under it (parent): billed in addition to the service, with their quantity (1.5 hours, say) and
 * price per unit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_invoice_service')]
class InvoiceServiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdOn;

    /** @var Collection<int, InvoicePartLine> */
    #[ORM\OneToMany(targetEntity: InvoicePartLine::class, mappedBy: 'serviceLine')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $parts;

    /** The taxes it carries, frozen when it was added (its Tax Class then; LineTax). Existing lines carry both. */
    #[ORM\Column(options: ['default' => true])]
    private bool $chargesGst = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $chargesPst = true;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'serviceLines')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Invoice $invoice,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $name,
        /** 1 for a service; hours or a count for a charge-through line. */
        #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => 1])]
        private string $quantity = '1',
        /** The price of one, as billed (dollars). */
        #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
        private ?string $salePrice = null,
        /** The catalogue service it was picked from; the legacy app also allowed typed-in names. */
        #[ORM\ManyToOne(targetEntity: ServiceItem::class)]
        private ?ServiceItem $service = null,
        /** The service a charge-through line is billed under. */
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?self $parent = null,
    ) {
        $this->createdOn = new \DateTimeImmutable();
        $this->parts = new ArrayCollection();
    }

    public function chargesGst(): bool { return $this->chargesGst; }
    public function chargesPst(): bool { return $this->chargesPst; }
    public function getTax(): LineTax { return new LineTax($this->chargesGst, $this->chargesPst); }

    public function setTax(LineTax $tax): self
    {
        $this->chargesGst = $tax->gst;
        $this->chargesPst = $tax->pst;

        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getName(): ?string { return $this->name; }
    public function getQuantity(): string { return $this->quantity; }
    public function getSalePrice(): ?string { return $this->salePrice; }
    public function getService(): ?ServiceItem { return $this->service; }
    public function getParent(): ?self { return $this->parent; }

    /** An issued invoice's edit: its name, quantity and price as billed. */
    public function change(string $name, string $quantity, ?string $salePrice): void
    {
        $this->name = $name;
        $this->quantity = $quantity;
        $this->salePrice = $salePrice;
    }

    /** A charge-through line, billed under its service. */
    public function isChargeThrough(): bool
    {
        return $this->parent !== null;
    }

    /** @return Collection<int, InvoicePartLine> */
    public function getParts(): Collection { return $this->parts; }

    /** Quantity × price, in cents. */
    public function totalCents(): int
    {
        return (int) round((float) $this->quantity * Money::toCents($this->salePrice));
    }
}
