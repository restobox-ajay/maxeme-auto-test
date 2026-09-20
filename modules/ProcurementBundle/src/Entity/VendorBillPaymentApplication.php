<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Contract\Payment\PayableDocument;
use App\Contract\Payment\PaymentApplication;
use App\Entity\AdminUser;
use Doctrine\ORM\Mapping as ORM;

/**
 * One payment's slice landing, in part or whole, on one bill (#708, queue item 34's multi-bill
 * follow-up) — the buy-side mirror of `App\Entity\InvoicePaymentApplication`, which is itself
 * mirrored from `App\Entity\CreditMemoApplication`. See either docblock for why this table exists
 * rather than a foreign key on `vendor_bill_payment`.
 *
 * Rows are written and removed only through `VendorBill::recordPayment()` (new payment, applied in
 * full), `VendorBill::applyPayment()` (part of an existing payment, from the multi-bill picker),
 * `VendorBill::withdrawApplication()` and `VendorBill::moveApplication()`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vendor_bill_payment_application')]
#[ORM\Index(name: 'idx_bill_payment_application_bill', fields: ['bill'])]
class VendorBillPaymentApplication implements PaymentApplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VendorBillPayment::class, inversedBy: 'applications')]
    #[ORM\JoinColumn(name: 'vendor_bill_payment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private VendorBillPayment $payment;

    /**
     * NOT NULL and NOT cascade-deleted. A claim with no bill is not a claim, and a bill is never
     * deleted in this app anyway — a withdrawn one is Void and keeps its number forever.
     */
    #[ORM\ManyToOne(targetEntity: VendorBill::class, inversedBy: 'applications')]
    #[ORM\JoinColumn(name: 'vendor_bill_id', referencedColumnName: 'id', nullable: false)]
    private VendorBill $bill;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount = '0.00';

    /** The day this slice was applied, which is not the day the money left. */
    #[ORM\Column(name: 'applied_at', type: 'date_immutable')]
    private \DateTimeImmutable $appliedAt;

    public function __construct()
    {
        $this->appliedAt = new \DateTimeImmutable('today');
    }

    public function getId(): ?int { return $this->id; }

    public function getPayment(): VendorBillPayment { return $this->payment; }

    /**
     * Set by `VendorBillPayment::applyTo()`, which is the only thing that should call it.
     *
     * @internal
     */
    public function setPayment(VendorBillPayment $payment): self { $this->payment = $payment; return $this; }

    public function getBill(): VendorBill { return $this->bill; }
    public function setBill(VendorBill $bill): self { $this->bill = $bill; return $this; }

    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): self { $this->amount = $amount; return $this; }

    public function getAppliedAt(): \DateTimeImmutable { return $this->appliedAt; }
    public function setAppliedAt(\DateTimeImmutable $appliedAt): self { $this->appliedAt = $appliedAt; return $this; }

    /*
     * ------------------------------------------------------------------------------------------
     * PaymentApplication — the four that are genuinely this pool's, delegated in one line each
     * ------------------------------------------------------------------------------------------
     */

    /** The bill, widened. `getBill()` above stays, because buy-side callers want the concrete type. */
    public function getDocument(): PayableDocument { return $this->bill; }

    public function getCurrency(): ?string { return $this->payment->getCurrency(); }
    public function getMethod(): string { return $this->payment->getMethod(); }
    public function getReference(): ?string { return $this->payment->getComment(); }
    public function getRecordedBy(): ?AdminUser { return $this->payment->getUser(); }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf('$%s of %s', $this->amount, $this->payment->getLabel());
    }
}
