<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One movement of a part's stock (legacy CNSInventoryBundle InventoryHistory): a restock or
 * adjustment from Parts Inventory, or the parts used on a paid invoice. Written only by
 * App\Maxeme\Service\StockLedger. Ids are the legacy ids.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_inventory_history')]
#[ORM\Index(name: 'idx_maxeme_inventory_history_invoice', columns: ['invoice_id'])]
class InventoryHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdOn;

    /**
     * @param int $quantity change in stock: positive in, negative out
     * @param ?int $invoiceId the invoice whose parts this is (the invoice relation arrives with the invoices)
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Part::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Part $part,
        #[ORM\Column]
        private int $quantity,
        #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
        private ?string $unitPrice = null,
        #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
        private ?string $salePrice = null,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $note = null,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $poNumber = null,
        #[ORM\Column(nullable: true)]
        private ?int $invoiceId = null,
    ) {
        $this->createdOn = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPart(): Part { return $this->part; }
    public function getQuantity(): int { return $this->quantity; }
    public function getUnitPrice(): ?string { return $this->unitPrice; }
    public function getSalePrice(): ?string { return $this->salePrice; }
    public function getNote(): ?string { return $this->note; }
    public function getPoNumber(): ?string { return $this->poNumber; }
    public function getInvoiceId(): ?int { return $this->invoiceId; }
    public function getCreatedOn(): \DateTimeImmutable { return $this->createdOn; }
}
