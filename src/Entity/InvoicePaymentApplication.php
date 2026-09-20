<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Payment\PayableDocument;
use App\Contract\Payment\PaymentApplication;
use Doctrine\ORM\Mapping as ORM;

/**
 * One payment's slice landing, in part or whole, on one invoice (#708, queue item 34's
 * multi-invoice follow-up) — the sell-side mirror of {@see CreditMemoApplication}, which is the
 * pattern this table was told to copy rather than invent.
 *
 * ## Why this table exists at all
 *
 * Because the relationship is MANY-TO-MANY WITH AN AMOUNT, and a single `invoice_id` on
 * `invoice_payment` could neither say how much of a payment went to which invoice nor allow one
 * cheque to settle several. In practice both happen: one payment clears three invoices, and one
 * invoice is paid by two separate cheques. This row is its own fact for exactly the reason
 * `CreditMemoApplication`'s docblock gives for the identical shape.
 *
 * `appliedAt` is here and not derived from `InvoicePayment::getReceivedAt()` for the same reason:
 * a cheque banked in March and split across invoices in March and May has two dates, and this is
 * the one that answers "what was this invoice owed on the day this settled it".
 *
 * ## Rows are written and removed only through Invoice
 *
 * `Invoice::recordPayment()` (new payment, applied in full), `Invoice::applyPayment()` (part of an
 * existing payment, from the multi-invoice picker), `Invoice::withdrawApplication()` and
 * `Invoice::moveApplication()` are the only paths — there is deliberately no public `setInvoice()`
 * beyond construction-time wiring for a caller to re-point a claim at another document with.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_payment_application')]
#[ORM\Index(name: 'idx_invoice_payment_application_invoice', fields: ['invoice'])]
class InvoicePaymentApplication implements PaymentApplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: InvoicePayment::class, inversedBy: 'applications')]
    #[ORM\JoinColumn(name: 'invoice_payment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InvoicePayment $payment;

    /**
     * NOT NULL and NOT cascade-deleted. A claim with no invoice is not a claim, and an invoice is
     * never deleted in this app anyway — a withdrawn one is Cancelled and keeps its number forever.
     */
    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'applications')]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false)]
    private Invoice $invoice;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount = '0.00';

    /** The day this slice was applied, which is not the day the money arrived. */
    #[ORM\Column(name: 'applied_at', type: 'date_immutable')]
    private \DateTimeImmutable $appliedAt;

    public function __construct()
    {
        $this->appliedAt = new \DateTimeImmutable('today');
    }

    public function getId(): ?int { return $this->id; }

    public function getPayment(): InvoicePayment { return $this->payment; }

    /**
     * Set by `InvoicePayment::applyTo()`, which is the only thing that should call it.
     *
     * @internal
     */
    public function setPayment(InvoicePayment $payment): self { $this->payment = $payment; return $this; }

    public function getInvoice(): Invoice { return $this->invoice; }
    public function setInvoice(Invoice $invoice): self { $this->invoice = $invoice; return $this; }

    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): self { $this->amount = $amount; return $this; }

    public function getAppliedAt(): \DateTimeImmutable { return $this->appliedAt; }
    public function setAppliedAt(\DateTimeImmutable $appliedAt): self { $this->appliedAt = $appliedAt; return $this; }

    /*
     * ------------------------------------------------------------------------------------------
     * PaymentApplication — the four that are genuinely this pool's, delegated in one line each
     * ------------------------------------------------------------------------------------------
     */

    /** The invoice, widened. `getInvoice()` above stays, because sell-side callers want the concrete type. */
    public function getDocument(): PayableDocument { return $this->invoice; }

    public function getCurrency(): ?string { return $this->payment->getCurrency(); }
    public function getMethod(): string { return $this->payment->getMethod(); }
    public function getReference(): ?string { return $this->payment->getComment(); }
    public function getRecordedBy(): ?AdminUser { return $this->payment->getUser(); }
}
