<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One credit note's balance landing, in part or whole, on one invoice (#586).
 *
 * ## Why this table exists at all
 *
 * Because the relationship is MANY-TO-MANY WITH AN AMOUNT, and that is the part a naive design gets
 * wrong. `credit_memo.invoice_id` is provenance — "this note was raised from INV-123" — and it can
 * neither say how much of the balance went there nor allow it to go anywhere else. In practice both
 * happen: one note pays down three invoices, and one invoice is credited by two notes. A foreign key
 * can express neither, so the allocation is its own row with its own figure.
 *
 * Which is also why `applied_at` is here and not derivable: a note raised in March and spent against
 * an invoice in May has two dates, and the one that matters for "what was this invoice owed on the
 * 30th" is this one.
 *
 * ## Rows are written and removed only through CreditMemo
 *
 * CreditMemo::applyTo() and CreditMemo::withdrawApplication() are the only paths, and there is
 * deliberately no public setCreditMemo() for a caller to re-point an allocation with. That is what
 * keeps the note's balance — and therefore its Open/Closed status — underivable by hand: every
 * mutation of this collection runs settle() on the way out.
 */
#[ORM\Entity]
#[ORM\Table(name: 'credit_memo_application')]
#[ORM\Index(name: 'idx_credit_memo_application_invoice', fields: ['invoice'])]
class CreditMemoApplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CreditMemo::class, inversedBy: 'applications')]
    #[ORM\JoinColumn(name: 'credit_memo_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CreditMemo $creditMemo;

    /**
     * NOT NULL and NOT cascade-deleted. An allocation with no invoice is not an allocation, and an
     * invoice is never deleted in this app anyway — a withdrawn one is Cancelled and keeps its
     * number forever.
     */
    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'creditApplications')]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false)]
    private Invoice $invoice;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount = '0.00';

    /** The day the credit was applied, which is not the day the note was raised. */
    #[ORM\Column(name: 'applied_at', type: 'date_immutable')]
    private \DateTimeImmutable $appliedAt;

    public function __construct()
    {
        $this->appliedAt = new \DateTimeImmutable('today');
    }

    public function getId(): ?int { return $this->id; }

    public function getCreditMemo(): CreditMemo { return $this->creditMemo; }

    /**
     * Set by CreditMemo::applyTo(), which is the only thing that should call it.
     *
     * @internal
     */
    public function setCreditMemo(CreditMemo $creditMemo): self { $this->creditMemo = $creditMemo; return $this; }

    public function getInvoice(): Invoice { return $this->invoice; }
    public function setInvoice(Invoice $invoice): self { $this->invoice = $invoice; return $this; }

    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): self { $this->amount = $amount; return $this; }

    public function getAppliedAt(): \DateTimeImmutable { return $this->appliedAt; }
    public function setAppliedAt(\DateTimeImmutable $appliedAt): self { $this->appliedAt = $appliedAt; return $this; }
}
