<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Contract\Document\DocumentLog;
use Doctrine\ORM\Mapping as ORM;

/**
 * The human-readable timeline on a vendor bill (#555).
 *
 * Same rule as PurchaseOrderLog and InvoiceLog: written by the action that makes the change,
 * because that is the only place the intent still exists. "Disputed — short shipment" and
 * "Disputed — priced above the PO" are the same changeset and different entries, and AuditLogger's
 * field-level diff cannot tell them apart.
 *
 * On a bill this matters more than anywhere else in the bundle. Approving a bill is authorising
 * money to leave, and "who approved this, and what did the match say at the time" is the question
 * an audit actually asks.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vendor_bill_log')]
#[ORM\Index(name: 'idx_bill_log_bill', fields: ['bill'])]
class VendorBillLog implements DocumentLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VendorBill::class, inversedBy: 'logs')]
    #[ORM\JoinColumn(name: 'vendor_bill_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private VendorBill $bill;

    #[ORM\Column(name: 'user_name', length: 255, nullable: true)]
    private ?string $userName = null;

    #[ORM\Column(type: 'text')]
    private string $comment = '';

    #[ORM\Column(length: 64)]
    private string $type = 'System';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getBill(): VendorBill { return $this->bill; }
    public function setBill(VendorBill $bill): self { $this->bill = $bill; return $this; }
    public function getUserName(): ?string { return $this->userName; }
    public function setUserName(?string $userName): self { $this->userName = $userName; return $this; }
    public function getComment(): string { return $this->comment; }
    public function setComment(string $comment): self { $this->comment = $comment; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
