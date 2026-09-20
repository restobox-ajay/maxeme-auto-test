<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Enum\DebitMemoStatus;

/**
 * A debit memo (#638): what a vendor owes back to US. Column-for-column and rule-for-rule the exact
 * mirror of `App\Entity\CreditMemo` — see that class's docblock for the full reasoning, restated
 * here only where direction changes it.
 *
 * ## It is a document with a balance, applied against vendor bills
 *
 * Exactly CreditMemo's shape, reversed: `$vendorBill` is PROVENANCE ("raised from BILL-123"), and
 * `debit_memo_application` is where the money actually lands — a many-to-many with an amount,
 * because one debit memo can reduce three bills and one bill can be reduced by two memos.
 *
 * ## Balance is DERIVED, and this is #638's explicit warning made real
 *
 *     balance = total − SUM(applications.amount) − SUM(refunds.amount)
 *
 * #638 names `#603` by number — the open bug where `Invoice::getBalance()` was built as
 * `total − payments` with no applications term, so a fully-credited invoice still reads "Not Paid".
 * `VendorBill::getBalance()` has that exact shallow shape today (`total − amountPaid`, no
 * applications concept, because no `VendorBillPayment` table exists yet). This class does NOT copy
 * it. It copies `CreditMemo::getBalance()`'s three-term derivation instead, correctly, from the
 * outset — see `settle()`, called from every method that can move the balance, which is what makes
 * "Open means balance > 0" true by construction rather than by everybody remembering.
 *
 * ## $restock is the exclusivity CreditMemo enforces against SalesReturn, mirrored against VendorReturn
 *
 * `$restock = true` on a STANDALONE debit memo means these lines describe goods that physically left
 * for the vendor as part of issuing this memo — the collapsed, Dynamics-style case #638 keeps for
 * exactly the reason CreditMemo keeps its own: a pure billing correction with no goods movement is
 * common enough to need the simple path. It is refused outright once `$vendorReturn` is set, because
 * that document's own `ship()` already moved the stock — see `assertRestockAndReturnAreExclusive()`,
 * byte-for-byte `CreditMemo`'s enforcement with the entity names swapped.
 *
 * Unlike `CreditMemo`, a standalone `restock = true` memo has no controller-side stock-writing path
 * in this change — see `DebitMemoController`'s docblock for why that is reported rather than built.
 */
#[ORM\Entity(repositoryClass: \ProcurementBundle\Repository\DebitMemoRepository::class)]
#[ORM\Table(name: 'debit_memo')]
#[ORM\UniqueConstraint(name: 'uniq_debit_memo_number', fields: ['documentNumber'])]
#[ORM\Index(name: 'idx_debit_memo_status', fields: ['status'])]
class DebitMemo extends AbstractPurchaseDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'document_number', length: 32)]
    private string $documentNumber = '';

    #[ORM\Column(length: 20, enumType: DebitMemoStatus::class)]
    private DebitMemoStatus $status = DebitMemoStatus::Draft;

    /** The bill this memo was raised FROM, if any. Provenance — see the class docblock. SET NULL, matching CreditMemo::$invoice. */
    #[ORM\ManyToOne(targetEntity: VendorBill::class)]
    #[ORM\JoinColumn(name: 'vendor_bill_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?VendorBill $vendorBill = null;

    /** The vendor return these goods went back on, when there is one. SET NULL, matching CreditMemo::$salesReturn. */
    #[ORM\ManyToOne(targetEntity: VendorReturn::class)]
    #[ORM\JoinColumn(name: 'vendor_return_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?VendorReturn $vendorReturn = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    /** See the class docblock. May only be true while $vendorReturn is null — assertRestockAndReturnAreExclusive() enforces it. */
    #[ORM\Column(options: ['default' => false])]
    private bool $restock = false;

    /** @var Collection<int, DebitMemoLine> */
    #[ORM\OneToMany(targetEntity: DebitMemoLine::class, mappedBy: 'debitMemo', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, DebitMemoApplication> */
    #[ORM\OneToMany(targetEntity: DebitMemoApplication::class, mappedBy: 'debitMemo', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['appliedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $applications;

    /** @var Collection<int, DebitMemoRefund> */
    #[ORM\OneToMany(targetEntity: DebitMemoRefund::class, mappedBy: 'debitMemo', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['refundedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $refunds;

    /** @var Collection<int, DebitMemoAddress> */
    #[ORM\OneToMany(targetEntity: DebitMemoAddress::class, mappedBy: 'debitMemo', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $memoAddresses;

    public function __construct()
    {
        parent::__construct();
        $this->lines = new ArrayCollection();
        $this->applications = new ArrayCollection();
        $this->refunds = new ArrayCollection();
        $this->memoAddresses = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getDocumentNumber(): string { return $this->documentNumber; }
    public function setDocumentNumber(string $documentNumber): self { $this->documentNumber = $documentNumber; return $this; }
    public function getStatus(): DebitMemoStatus { return $this->status; }
    public function getVendorBill(): ?VendorBill { return $this->vendorBill; }
    public function setVendorBill(?VendorBill $vendorBill): self { $this->vendorBill = $vendorBill; return $this; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): self { $this->reason = $reason; return $this; }
    public function getVendorReturn(): ?VendorReturn { return $this->vendorReturn; }

    /** See CreditMemo::setSalesReturn() — guarded on both sides so neither call order can bypass the exclusivity. */
    public function setVendorReturn(?VendorReturn $vendorReturn): self
    {
        $this->assertRestockAndReturnAreExclusive($this->restock, $vendorReturn);
        $this->vendorReturn = $vendorReturn;

        return $this;
    }

    public function isRestock(): bool { return $this->restock; }

    /** See CreditMemo::setRestock() — same refusal, same reason, direction reversed. */
    public function setRestock(bool $restock): self
    {
        $this->assertRestockAndReturnAreExclusive($restock, $this->vendorReturn);
        $this->restock = $restock;

        return $this;
    }

    /**
     * Byte-for-byte `CreditMemo::assertRestockAndReturnAreExclusive()` with the entity names
     * swapped — see that method's docblock for why this is the entire enforcement and why it lives
     * in one method called from both setters rather than two copies of an `if`.
     */
    private function assertRestockAndReturnAreExclusive(bool $restock, ?VendorReturn $vendorReturn): void
    {
        if (!$restock || !$vendorReturn instanceof VendorReturn) {
            return;
        }

        throw new \DomainException(sprintf(
            'Debit memo %s credits vendor return %s, and that return\'s ship() is what moves the goods out. '
            . 'A memo carrying a return may not also restock: the same units would leave the ledger twice. '
            . 'Untick "the goods went back" on this memo, or detach the return and let the memo own the stock instead.',
            $this->documentLabel(),
            $vendorReturn->getDocumentNumber() !== '' ? $vendorReturn->getDocumentNumber() : '#' . (string) $vendorReturn->getId(),
        ));
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Transitions and allocations — see CreditMemo for the full reasoning behind every one of these.
     * ------------------------------------------------------------------------------------------
     */

    public function issue(): self
    {
        if ($this->status !== DebitMemoStatus::Draft) {
            throw new \DomainException(sprintf('Debit memo %s is %s; only a draft can be issued.', $this->documentLabel(), $this->status->value));
        }

        if (self::cents($this->getTotal()) <= 0) {
            throw new \DomainException(sprintf('Debit memo %s has a total of $%s. There is nothing to debit.', $this->documentLabel(), $this->getTotal()));
        }

        $this->status = DebitMemoStatus::Open;

        return $this;
    }

    public function void(): self
    {
        if ($this->status === DebitMemoStatus::Void) {
            throw new \DomainException(sprintf('Debit memo %s is already void.', $this->documentLabel()));
        }

        $spent = self::cents($this->getAmountApplied()) + self::cents($this->getAmountRefunded());
        if ($spent > 0) {
            throw new \DomainException(sprintf(
                'Debit memo %s has $%s applied and $%s refunded against it and cannot be voided. Withdraw its applications and delete its refunds first.',
                $this->documentLabel(),
                $this->getAmountApplied(),
                $this->getAmountRefunded(),
            ));
        }

        $this->status = DebitMemoStatus::Void;

        return $this;
    }

    /** Spend part or all of this memo's balance against one vendor bill. Mirrors CreditMemo::applyTo() exactly. */
    public function applyTo(VendorBill $vendorBill, string $amount, ?\DateTimeImmutable $appliedAt = null): DebitMemoApplication
    {
        if ($this->status !== DebitMemoStatus::Open) {
            throw new \DomainException(sprintf('Debit memo %s is %s. Only an open memo has a balance to apply.', $this->documentLabel(), $this->status->value));
        }

        if ($vendorBill->getVendor() !== $this->getVendor()) {
            throw new \DomainException(sprintf(
                'Debit memo %s belongs to %s and bill %s to %s. A debit memo cannot cross vendors.',
                $this->documentLabel(),
                $this->getVendorName(),
                $vendorBill->getDocumentNumber(),
                $vendorBill->getVendorName(),
            ));
        }

        $this->assertPositive($amount, 'An application');

        if (self::cents($amount) > self::cents($this->getBalance())) {
            throw new \DomainException(sprintf('Debit memo %s has $%s left. It cannot apply $%s.', $this->documentLabel(), $this->getBalance(), $amount));
        }

        // #771: the memo's own balance caps how much it has LEFT to give, but says nothing about
        // how much the bill has left to RECEIVE — a bill already paid down, or debited by an
        // earlier memo, can take less than this memo's own remaining balance. Mirrors
        // CreditMemo::applyTo()'s identical invoice-side cap (#770).
        if (self::cents($amount) > self::cents($vendorBill->getBalance())) {
            throw new \DomainException(sprintf(
                'Bill %s has a balance of $%s. It cannot take a $%s debit.',
                $vendorBill->getDocumentNumber(),
                $vendorBill->getBalance(),
                $amount,
            ));
        }

        $application = (new DebitMemoApplication())
            ->setDebitMemo($this)
            ->setVendorBill($vendorBill)
            ->setAmount(self::money(self::cents($amount)));

        if ($appliedAt instanceof \DateTimeImmutable) {
            $application->setAppliedAt($appliedAt);
        }

        $this->applications->add($application);
        // Both sides, in memory, for the same reason Invoice::applyPayment() adds to its own
        // $applications after building the claim — see CreditMemo::applyTo()'s identical comment.
        $vendorBill->getDebitApplications()->add($application);
        $this->settle();

        return $application;
    }

    public function withdrawApplication(DebitMemoApplication $application): self
    {
        if (!$this->applications->contains($application)) {
            throw new \DomainException(sprintf('That application does not belong to debit memo %s.', $this->documentLabel()));
        }

        $this->applications->removeElement($application);
        $application->getVendorBill()->getDebitApplications()->removeElement($application);

        return $this->settle();
    }

    /** Money the vendor actually paid back — an admin recording it, exactly as CreditMemoRefund records money paid to a customer. */
    public function recordRefund(DebitMemoRefund $refund): self
    {
        if ($this->status !== DebitMemoStatus::Open) {
            throw new \DomainException(sprintf('Debit memo %s is %s. Only an open memo has a balance to refund.', $this->documentLabel(), $this->status->value));
        }

        $this->assertPositive($refund->getAmount(), 'A refund');

        if (self::cents($refund->getAmount()) > self::cents($this->getBalance())) {
            throw new \DomainException(sprintf('Debit memo %s has $%s left. It cannot refund $%s.', $this->documentLabel(), $this->getBalance(), $refund->getAmount()));
        }

        $refund->setDebitMemo($this);
        if (!$this->refunds->contains($refund)) {
            $this->refunds->add($refund);
        }

        return $this->settle();
    }

    public function voidRefund(DebitMemoRefund $refund): self
    {
        if (!$this->refunds->contains($refund)) {
            throw new \DomainException(sprintf('That refund does not belong to debit memo %s.', $this->documentLabel()));
        }

        $this->refunds->removeElement($refund);

        return $this->settle();
    }

    /** The one place Open and Closed are written — see CreditMemo::settle() for the full reasoning. */
    private function settle(): self
    {
        if ($this->status !== DebitMemoStatus::Open && $this->status !== DebitMemoStatus::Closed) {
            return $this;
        }

        $this->status = self::cents($this->getBalance()) <= 0 ? DebitMemoStatus::Closed : DebitMemoStatus::Open;

        return $this;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * The balance
     * ------------------------------------------------------------------------------------------
     */

    /** @return Collection<int, DebitMemoApplication> */
    public function getApplications(): Collection { return $this->applications; }

    /** @return Collection<int, DebitMemoRefund> */
    public function getRefunds(): Collection { return $this->refunds; }

    public function getAmountApplied(): string
    {
        $cents = 0;
        foreach ($this->applications as $application) {
            $cents += self::cents($application->getAmount());
        }

        return self::money($cents);
    }

    public function getAmountRefunded(): string
    {
        $cents = 0;
        foreach ($this->refunds as $refund) {
            $cents += self::cents($refund->getAmount());
        }

        return self::money($cents);
    }

    /**
     * balance = total − applied − refunded. Derived, never stored — the correct three-term shape
     * from the outset. See the class docblock's #603 warning.
     */
    public function getBalance(): string
    {
        if ($this->status === DebitMemoStatus::Void) {
            return '0.00';
        }

        return self::money(self::cents($this->getTotal()) - self::cents($this->getAmountApplied()) - self::cents($this->getAmountRefunded()));
    }

    public function isDraft(): bool
    {
        return $this->status === DebitMemoStatus::Draft;
    }

    private function assertPositive(string $amount, string $what): void
    {
        if (self::cents($amount) <= 0) {
            throw new \DomainException(sprintf('%s must be for more than $0.00.', $what));
        }
    }

    private function documentLabel(): string
    {
        return $this->documentNumber !== '' ? $this->documentNumber : '#' . (string) $this->id;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Lines
     * ------------------------------------------------------------------------------------------
     */

    /** @return Collection<int, DebitMemoLine> */
    public function getLines(): Collection { return $this->lines; }

    public function getAddresses(): Collection { return $this->memoAddresses; }

    protected function newAddress(string $type): AbstractPurchaseDocumentAddress
    {
        $address = (new DebitMemoAddress())->setType($type);
        $address->setDebitMemo($this);
        $this->memoAddresses->add($address);

        return $address;
    }

    public function addLine(DebitMemoLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setDebitMemo($this);
        }

        return $this;
    }

    public function removeLine(DebitMemoLine $line): self
    {
        $this->lines->removeElement($line);

        return $this;
    }

    public function recalculateTotals(): self
    {
        $subtotal = 0;
        foreach ($this->lines as $line) {
            $subtotal += self::cents($line->getSubtotal());
        }

        $this->setSubtotal(self::money($subtotal));
        $this->setTotal(self::money($subtotal + self::cents($this->getTax())));

        return $this;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return $this->documentLabel();
    }
}
