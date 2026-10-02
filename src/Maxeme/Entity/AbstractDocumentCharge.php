<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Enum\DocumentChargeKind;
use Doctrine\ORM\Mapping as ORM;

/**
 * A custom line under a document's subtotal, above tax (as wholesale's custom fee and discount
 * lines): a label and an amount, as many as the admin adds. The amount is positive; a discount
 * takes it off. A repair order's (RepairOrderCharge) are copied onto the invoices issued from it
 * (InvoiceCharge).
 */
#[ORM\MappedSuperclass]
abstract class AbstractDocumentCharge
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10, enumType: DocumentChargeKind::class)]
    private DocumentChargeKind $kind;

    #[ORM\Column(length: 120)]
    private string $label = '';

    /** In dollars (CDN), positive. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(DocumentChargeKind $kind)
    {
        $this->kind = $kind;
    }

    public function getId(): ?int { return $this->id; }

    public function getKind(): DocumentChargeKind { return $this->kind; }
    public function setKind(DocumentChargeKind $kind): static { $this->kind = $kind; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }

    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    /** What it adds to the total, in cents: negative for a discount. */
    public function getSignedCents(): int
    {
        $cents = abs((int) round((float) $this->amount * 100));

        return $this->kind === DocumentChargeKind::Discount ? -$cents : $cents;
    }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }
}
