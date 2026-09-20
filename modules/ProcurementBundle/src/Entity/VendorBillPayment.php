<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\AdminUser;
use App\Payment\PaymentApplicationGuard;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Money paid OUT to a vendor — the buy-side mirror of `App\Entity\InvoicePayment`, repurposed at
 * #708 the same way and for the same reason: one payment can settle several bills, so the row that
 * used to BE the payment splits into this (the POOL — what left, when, by what method) and
 * {@see VendorBillPaymentApplication} (a CLAIM against one bill, for part of this pool).
 *
 * ## Shape, mirrored rather than invented
 *
 * Field for field with `InvoicePayment`, with one word changed throughout: `paid_at` where the sell
 * side has `received_at`, because on this side the money leaves; `vendor` where the sell side has
 * `company`. `$user` is nullable for the reason it is there — a payment run or an import has no
 * admin who typed it — and there is no Stripe column, because nobody pays a supplier through our
 * own checkout.
 *
 * ## Why `applyTo()` is concrete to `VendorBill`
 *
 * See `InvoicePayment::applyTo()`'s own docblock — the identical argument holds here: the shared
 * shape is what a payments SCREEN reads through, and this pool holds a specific vendor's money, so
 * applying it to an `Invoice` should be a type error, not a runtime refusal.
 *
 * Rows are created only through `VendorBill::recordPayment()` (a brand-new payment, applied in full)
 * or the multi-bill picker calling `applyTo()` on an already-persisted payment once per bill it is
 * splitting across. There is deliberately no public `setVendor()` beyond construction-time wiring.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vendor_bill_payment')]
class VendorBillPayment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false)]
    private Vendor $vendor;

    /** Nullable: a payment run or an import has no admin who recorded it by hand. */
    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    private ?AdminUser $user = null;

    #[ORM\Column(name: 'paid_at', type: 'date_immutable')]
    private \DateTimeImmutable $paidAt;

    #[ORM\Column(length: 64)]
    private string $method;

    /** The WHOLE amount paid out, not what any one bill has claimed — see {@see getUnappliedBalance()}. */
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, VendorBillPaymentApplication> */
    #[ORM\OneToMany(mappedBy: 'payment', targetEntity: VendorBillPaymentApplication::class, cascade: ['persist'])]
    private Collection $applications;

    public function __construct()
    {
        $this->paidAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->method = '';
        $this->applications = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getVendor(): Vendor { return $this->vendor; }

    /**
     * Set by `VendorBill::recordPayment()`, which is the only thing that should call it.
     *
     * @internal
     */
    public function setVendor(Vendor $vendor): self { $this->vendor = $vendor; return $this; }

    public function getUser(): ?AdminUser { return $this->user; }
    public function setUser(?AdminUser $user): self { $this->user = $user; return $this; }
    public function getPaidAt(): \DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(\DateTimeImmutable $paidAt): self { $this->paidAt = $paidAt; return $this; }
    public function getMethod(): string { return $this->method; }
    public function setMethod(string $method): self { $this->method = $method; return $this; }
    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): self { $this->amount = $amount; return $this; }
    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): self { $this->comment = $comment; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, VendorBillPaymentApplication> every bill this payment has settled, in part or whole */
    public function getApplications(): Collection { return $this->applications; }

    /** Everything already claimed against a bill, summed in whole cents and formatted back. */
    public function getAppliedTotal(): string
    {
        $cents = 0;
        foreach ($this->applications as $application) {
            $cents += self::cents($application->getAmount());
        }

        return self::money($cents);
    }

    /** What is left to apply to a further bill. Never negative — see {@see applyTo()}. */
    public function getUnappliedBalance(): string
    {
        return self::money(self::cents($this->amount) - self::cents($this->getAppliedTotal()));
    }

    /**
     * `vendor:12`. Compared for equality and never parsed — see
     * {@see \App\Contract\Payment\PayableDocument::getPaymentCounterpartyKey()} for why this is a
     * key and not a name.
     */
    public function getPaymentCounterpartyKey(): string
    {
        return 'vendor:' . ($this->vendor->getId() ?? 'unsaved-' . spl_object_id($this->vendor));
    }

    /**
     * The vendor's currency, read through rather than stored — a payment cannot be in a currency the
     * vendor it was paid to does not use.
     */
    public function getCurrency(): ?string
    {
        return $this->vendor->getCurrency();
    }

    /**
     * Claim part or all of this payment's unapplied balance against $bill.
     *
     * The three rules {@see PaymentApplicationGuard} checks are asked of $bill; the fourth — enough
     * of the pool left to cover $amount — is this pool's own business and stays here, mirroring
     * `InvoicePayment::applyTo()`'s identical split.
     *
     * @throws \DomainException with the reason, when the application is not a legal one. Nothing is
     *                          written: every rule is checked before the row is created
     */
    public function applyTo(VendorBill $bill, string $amount, ?\DateTimeImmutable $appliedAt = null): VendorBillPaymentApplication
    {
        $this->assertPositive($amount);

        if (self::cents($amount) > self::cents($this->getUnappliedBalance())) {
            throw new \DomainException(sprintf(
                'This payment has $%s left to apply. It cannot apply $%s.',
                $this->getUnappliedBalance(),
                $amount,
            ));
        }

        $this->assertApplicableTo($bill);

        $application = (new VendorBillPaymentApplication())
            ->setPayment($this)
            ->setBill($bill)
            ->setAmount(self::money(self::cents($amount)));

        if ($appliedAt instanceof \DateTimeImmutable) {
            $application->setAppliedAt($appliedAt);
        }

        $this->applications->add($application);

        return $application;
    }

    /**
     * The three {@see PaymentApplicationGuard} rules, asked without writing anything — see
     * `InvoicePayment::assertApplicableTo()`'s identical docblock for why `VendorBill::moveApplication()`
     * calls this BEFORE withdrawing the old claim rather than only inside `applyTo()`.
     *
     * @throws \DomainException with the reason, when $bill cannot take this payment
     */
    public function assertApplicableTo(VendorBill $bill): void
    {
        PaymentApplicationGuard::assertApplicable(
            $bill,
            $this->getPaymentCounterpartyKey(),
            $this->vendor->getName(),
            $this->getCurrency(),
        );
    }

    /**
     * Take an application back off this pool — the in-memory half of a withdrawal. See
     * `InvoicePayment::withdrawApplication()`'s identical docblock for why this does not itself
     * delete the row.
     */
    public function withdrawApplication(VendorBillPaymentApplication $application): self
    {
        $this->applications->removeElement($application);

        return $this;
    }

    private function assertPositive(string $amount): void
    {
        if (self::cents($amount) <= 0) {
            throw new \DomainException('A payment has to be a positive amount. Record a debit memo against the vendor instead.');
        }
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf('$%s via %s', $this->amount, $this->method !== '' ? $this->method : 'unknown method');
    }

    private static function cents(?string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
