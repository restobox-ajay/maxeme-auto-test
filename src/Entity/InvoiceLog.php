<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Document\DocumentLog;
use Doctrine\ORM\Mapping as ORM;

/**
 * The human-readable timeline on an invoice, mirroring SalesOrderLog and EstimateLog.
 *
 * Distinct in kind from the audit log, which is why both exist and why AuditLogSubscriber excludes
 * the other two document logs from itself: the audit log is a field-level diff written
 * automatically at flush, where completeness matters and intent does not. This is narrative
 * written for a person — "Cancelled by the stale-unpaid sweep" and "Cancelled by an admin" are the
 * same changeset and different entries — so it is written by the action that makes the change,
 * which is the only place the intent still exists.
 *
 * `implements DocumentLog` adds no column, no table and no behaviour — the three methods it names
 * were already here with these exact signatures. Declaring it is what lets the shared `setStatus()`
 * on `AbstractSalesDocument` fill in a row it did not construct, now that the invoice has joined the
 * status seam. `SalesOrderLog` and `EstimateLog` declared it for the same reason.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_log')]
class InvoiceLog implements DocumentLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userName = null;

    #[ORM\Column(type: 'text')]
    private string $comment;

    #[ORM\Column(length: 64)]
    private string $type = 'System';

    #[ORM\Column]
    private bool $customerNotified = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function setInvoice(Invoice $invoice): self { $this->invoice = $invoice; return $this; }
    public function getUserName(): ?string { return $this->userName; }
    public function setUserName(?string $userName): self { $this->userName = $userName; return $this; }
    public function getComment(): string { return $this->comment; }
    public function setComment(string $comment): self { $this->comment = $comment; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function isCustomerNotified(): bool { return $this->customerNotified; }
    public function setCustomerNotified(bool $customerNotified): self { $this->customerNotified = $customerNotified; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
