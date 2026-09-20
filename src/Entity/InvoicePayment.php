<?php

declare(strict_types=1);

namespace App\Entity;

use App\Payment\PaymentApplicationGuard;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Money received from a customer (#539 stage 4; repurposed at #708 from "money received against ONE
 * invoice" to "money received from a customer" once a single payment needed to settle several).
 *
 * ## The split this row is one half of
 *
 * Before #708 this row WAS the payment: one `invoice_id`, one amount, done. That broke the moment a
 * cheque had to clear two invoices at once, so the row that used to be both things splits into two:
 * this one (the POOL — what arrived, when, by what method, from whom) and
 * {@see InvoicePaymentApplication} (a CLAIM against one invoice, for part of this pool), mirroring
 * {@see CreditMemo}/{@see CreditMemoApplication} — the pattern this feature was told to copy from
 * the start. `company` replaces the old `invoice` foreign key for exactly this reason: a payment
 * belongs to whoever wrote the cheque before it belongs to any one document they owed.
 *
 * ## Why `applyTo()` is concrete to `Invoice`, not typed to `PayableDocument`
 *
 * `App\Contract\Payment\PayableDocument`/`PaymentApplication` exist so a payments SCREEN can read
 * either side without knowing which one it is on — see those interfaces' own docblocks. Writing is
 * different: this pool holds a customer's money, and applying it to a `VendorBill` would be a type
 * error the compiler should catch, not a runtime `PaymentApplicationGuard` refusal. `CreditMemo`
 * makes the identical choice — `applyTo(Invoice $invoice, ...)`, not `applyTo(PayableDocument
 * $document, ...)` — for the same reason: the shared shape is the interface both sides read through,
 * and the write path stays exactly as concrete as CreditMemo's always has been.
 *
 * Rows are created only through `Invoice::recordPayment()` (a brand-new payment, applied in full —
 * the single-invoice convenience button) or the multi-invoice picker calling `applyTo()` on an
 * already-persisted payment once per invoice it is splitting across. There is deliberately no public
 * `setCompany()` beyond construction-time wiring for a caller to re-point a payment at a different
 * customer's money with.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_payment')]
class InvoicePayment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false)]
    private Company $company;

    // Nullable: customer-initiated Stripe payments have no admin who recorded them.
    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    private ?AdminUser $user = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(length: 64)]
    private string $method;

    /** The WHOLE amount received, not what any one invoice has claimed — see {@see getUnappliedBalance()}. */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'stripe_payment_intent_id', length: 255, nullable: true, unique: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, InvoicePaymentApplication> */
    #[ORM\OneToMany(mappedBy: 'payment', targetEntity: InvoicePaymentApplication::class, cascade: ['persist'])]
    private Collection $applications;

    public function __construct()
    {
        $this->receivedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->applications = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getCompany(): Company { return $this->company; }

    /**
     * Set by `Invoice::recordPayment()`, which is the only thing that should call it — the moment a
     * brand-new pool is wired to the customer whose money it is.
     *
     * @internal
     */
    public function setCompany(Company $company): self { $this->company = $company; return $this; }

    public function getUser(): ?AdminUser { return $this->user; }
    public function setUser(?AdminUser $user): self { $this->user = $user; return $this; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function setReceivedAt(\DateTimeImmutable $receivedAt): self { $this->receivedAt = $receivedAt; return $this; }
    public function getMethod(): string { return $this->method; }
    public function setMethod(string $method): self { $this->method = $method; return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): self { $this->amount = $amount; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): self { $this->comment = $comment; return $this; }
    public function getStripePaymentIntentId(): ?string { return $this->stripePaymentIntentId; }
    public function setStripePaymentIntentId(?string $stripePaymentIntentId): self { $this->stripePaymentIntentId = $stripePaymentIntentId; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, InvoicePaymentApplication> every invoice this payment has settled, in part or whole */
    public function getApplications(): Collection { return $this->applications; }

    /** Everything already claimed against an invoice, summed in whole cents and formatted back. */
    public function getAppliedTotal(): string
    {
        $cents = 0;
        foreach ($this->applications as $application) {
            $cents += self::cents($application->getAmount());
        }

        return self::money($cents);
    }

    /** What is left to apply to a further invoice. Never negative — see {@see applyTo()}. */
    public function getUnappliedBalance(): string
    {
        return self::money(self::cents($this->amount) - self::cents($this->getAppliedTotal()));
    }

    /**
     * `company:7`. Compared for equality and never parsed — see
     * {@see \App\Contract\Payment\PayableDocument::getPaymentCounterpartyKey()} for why this is a key
     * and not a name.
     */
    public function getPaymentCounterpartyKey(): string
    {
        return 'company:' . ($this->company->getId() ?? 'unsaved-' . spl_object_id($this->company));
    }

    /**
     * Always null: the sell side is single-currency and names it once in `base_currency`, which an
     * entity cannot read — see `AbstractSalesDocument::getCurrency()`'s identical answer.
     */
    public function getCurrency(): ?string
    {
        return null;
    }

    /**
     * Claim part or all of this payment's unapplied balance against $invoice.
     *
     * The three rules {@see PaymentApplicationGuard} checks are asked of $invoice; the fourth —
     * enough of the pool left to cover $amount — is this pool's own business and stays here,
     * mirroring `CreditMemo::applyTo()`'s identical split between "can the target take it" and "do I
     * have that much to give".
     *
     * @throws \DomainException with the reason, when the application is not a legal one. Nothing is
     *                          written: every rule is checked before the row is created
     */
    public function applyTo(Invoice $invoice, string $amount, ?\DateTimeImmutable $appliedAt = null): InvoicePaymentApplication
    {
        $this->assertPositive($amount);

        if (self::cents($amount) > self::cents($this->getUnappliedBalance())) {
            throw new \DomainException(sprintf(
                'This payment has $%s left to apply. It cannot apply $%s.',
                $this->getUnappliedBalance(),
                $amount,
            ));
        }

        $this->assertApplicableTo($invoice);

        $application = (new InvoicePaymentApplication())
            ->setPayment($this)
            ->setInvoice($invoice)
            ->setAmount(self::money(self::cents($amount)));

        if ($appliedAt instanceof \DateTimeImmutable) {
            $application->setAppliedAt($appliedAt);
        }

        $this->applications->add($application);

        return $application;
    }

    /**
     * The three {@see PaymentApplicationGuard} rules, asked without writing anything — what
     * `applyTo()` checks before it creates a row, and what `Invoice::moveApplication()` checks
     * BEFORE it withdraws the old claim, so that a refused move touches neither collection. Checking
     * only after the withdrawal would leave the source holding nothing while the guard still had the
     * last word — a real defect this method exists to close, not a hypothetical one.
     *
     * @throws \DomainException with the reason, when $invoice cannot take this payment
     */
    public function assertApplicableTo(Invoice $invoice): void
    {
        PaymentApplicationGuard::assertApplicable(
            $invoice,
            $this->getPaymentCounterpartyKey(),
            $this->company->getName(),
            $this->getCurrency(),
        );
    }

    /**
     * Take an application back off this pool — the in-memory half of a withdrawal.
     *
     * `Invoice::withdrawApplication()` is what actually deletes the row (orphanRemoval lives on
     * `Invoice::$applications`, the same side `Invoice::$payments` held it on before #708); this only
     * keeps THIS collection — and therefore {@see getUnappliedBalance()} — correct for the rest of
     * the request, exactly as `VendorBillPayment::withdrawApplication()` has always kept both ends of
     * a move consistent by hand rather than trusting Doctrine to infer the other side.
     */
    public function withdrawApplication(InvoicePaymentApplication $application): self
    {
        $this->applications->removeElement($application);

        return $this;
    }

    private function assertPositive(string $amount): void
    {
        if (self::cents($amount) <= 0) {
            throw new \DomainException('A payment must be for more than $0.00.');
        }
    }

    /**
     * Money is compared in whole cents, never as floats: 0.10 + 0.20 is not 0.30 in binary floating
     * point, and a payment a hundredth of a cent short of covering an invoice never reads as applied.
     */
    private static function cents(?string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
