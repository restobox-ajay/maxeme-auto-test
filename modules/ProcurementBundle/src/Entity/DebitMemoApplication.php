<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One debit memo's balance landing, in part or whole, on one vendor bill (#638) — the mirror of
 * `App\Entity\CreditMemoApplication`. Many-to-many with an amount, for the identical reason: one
 * memo can reduce three bills, and one bill can be reduced by two memos.
 *
 * Rows are written and removed only through `DebitMemo::applyTo()`/`withdrawApplication()` — no
 * public `setDebitMemo()` for a caller to re-point an allocation with, which is what keeps the
 * memo's balance underivable by hand.
 */
#[ORM\Entity]
#[ORM\Table(name: 'debit_memo_application')]
#[ORM\Index(name: 'idx_debit_memo_application_bill', fields: ['vendorBill'])]
class DebitMemoApplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DebitMemo::class, inversedBy: 'applications')]
    #[ORM\JoinColumn(name: 'debit_memo_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private DebitMemo $debitMemo;

    /** NOT NULL and NOT cascade-deleted — an allocation with no bill is not an allocation. */
    #[ORM\ManyToOne(targetEntity: VendorBill::class, inversedBy: 'debitApplications')]
    #[ORM\JoinColumn(name: 'vendor_bill_id', referencedColumnName: 'id', nullable: false)]
    private VendorBill $vendorBill;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(name: 'applied_at', type: 'date_immutable')]
    private \DateTimeImmutable $appliedAt;

    public function __construct()
    {
        $this->appliedAt = new \DateTimeImmutable('today');
    }

    public function getId(): ?int { return $this->id; }
    public function getDebitMemo(): DebitMemo { return $this->debitMemo; }

    /** @internal set by DebitMemo::applyTo() */
    public function setDebitMemo(DebitMemo $debitMemo): self { $this->debitMemo = $debitMemo; return $this; }

    public function getVendorBill(): VendorBill { return $this->vendorBill; }
    public function setVendorBill(VendorBill $vendorBill): self { $this->vendorBill = $vendorBill; return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): self { $this->amount = $amount; return $this; }
    public function getAppliedAt(): \DateTimeImmutable { return $this->appliedAt; }
    public function setAppliedAt(\DateTimeImmutable $appliedAt): self { $this->appliedAt = $appliedAt; return $this; }
}
