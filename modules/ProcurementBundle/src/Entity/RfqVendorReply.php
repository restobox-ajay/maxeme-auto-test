<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Enum\RfqVendorReplyStatus;

/**
 * One vendor's priced answer to an RFQ (#637) — the actual mirror of `App\Entity\Estimate`, and the
 * thing `RfqConversionService::convert()` turns into a `PurchaseOrder`, exactly as
 * `EstimateConversionService::convert()` turns an Estimate into a `SalesOrder`.
 *
 * `Rfq` names the requirement once; this exists once per invited vendor, priced independently,
 * which is what makes "N competing quotes against one requirement" real rather than a spreadsheet
 * built by hand. See `Rfq`'s class docblock for why the header itself is not this class and does
 * not implement `CommercialDocument`.
 *
 * Extends `AbstractPurchaseDocument`, which already implements `CommercialDocument` — see #636 —
 * so this conforms for free: `getDocumentDate()`, `getCurrency()`, `getCounterpartyName()`,
 * `getSubtotal/Tax/Total()` all come from the base, keyed to `$vendor`/`$vendorName` exactly as
 * `PurchaseOrder` and `VendorBill` already are. Only `getDocumentNumber()` and `getLines()` are
 * supplied here, for the reason the base's docblock gives: a mapped superclass cannot parametrize
 * `targetEntity`, and aliasing the document number on the base is the footgun `AbstractSalesDocument`
 * already stepped on once.
 */
#[ORM\Entity(repositoryClass: \ProcurementBundle\Repository\RfqVendorReplyRepository::class)]
#[ORM\Table(name: 'rfq_vendor_reply')]
#[ORM\UniqueConstraint(name: 'uniq_rfq_reply_number', fields: ['replyNumber'])]
#[ORM\UniqueConstraint(name: 'uniq_rfq_reply_vendor', fields: ['rfq', 'vendor'])]
#[ORM\Index(name: 'idx_rfq_reply_status', fields: ['status'])]
class RfqVendorReply extends AbstractPurchaseDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Rfq::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(name: 'rfq_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Rfq $rfq;

    #[ORM\Column(name: 'reply_number', length: 32)]
    private string $replyNumber = '';

    #[ORM\Column(length: 20, enumType: RfqVendorReplyStatus::class)]
    private RfqVendorReplyStatus $status = RfqVendorReplyStatus::Invited;

    #[ORM\Column(name: 'replied_at', nullable: true)]
    private ?\DateTimeImmutable $repliedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * Set only by RfqConversionService::convert(), the same moment Rfq::markAccepted() is called on
     * the header — see that service for the atomic claim that makes exactly one of these true across
     * every reply on the same RFQ, even under a race.
     */
    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class)]
    #[ORM\JoinColumn(name: 'purchase_order_id', referencedColumnName: 'id', nullable: true)]
    private ?PurchaseOrder $purchaseOrder = null;

    /** @var Collection<int, RfqVendorReplyLine> */
    #[ORM\OneToMany(targetEntity: RfqVendorReplyLine::class, mappedBy: 'reply', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    public function __construct()
    {
        parent::__construct();
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getRfq(): Rfq { return $this->rfq; }
    public function setRfq(Rfq $rfq): self { $this->rfq = $rfq; return $this; }
    public function getReplyNumber(): string { return $this->replyNumber; }
    public function setReplyNumber(string $replyNumber): self { $this->replyNumber = $replyNumber; return $this; }

    /** The CommercialDocument view of $replyNumber — see the class docblock on why they are one column. */
    public function getDocumentNumber(): string { return $this->replyNumber; }

    public function getStatus(): RfqVendorReplyStatus { return $this->status; }
    public function getRepliedAt(): ?\DateTimeImmutable { return $this->repliedAt; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    public function getPurchaseOrder(): ?PurchaseOrder { return $this->purchaseOrder; }

    /** @return Collection<int, RfqVendorReplyLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(RfqVendorReplyLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setReply($this);
        }

        return $this;
    }

    public function removeLine(RfqVendorReplyLine $line): self
    {
        $this->lines->removeElement($line);

        return $this;
    }

    /**
     * Every requirement line priced — the condition for send-to-Replied and for conversion. An
     * empty reply (nothing typed at all) is not "fully priced"; it is simply not started.
     */
    public function isFullyPriced(): bool
    {
        if ($this->lines->isEmpty()) {
            return false;
        }

        foreach ($this->lines as $line) {
            if ($line->getUnitCost() === null) {
                return false;
            }
        }

        return true;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Named actions.
     * ------------------------------------------------------------------------------------------
     */

    /** Invited -> Replied. The vendor's prices are in, whatever they turn out to be — see setPrices() on the controller side. */
    public function markReplied(?\DateTimeImmutable $at = null): self
    {
        if ($this->status !== RfqVendorReplyStatus::Invited && $this->status !== RfqVendorReplyStatus::Replied) {
            throw new \DomainException(sprintf('Reply %s is %s; only an invited or already-replied reply can take prices.', $this->documentLabel(), $this->status->value));
        }

        if (!$this->isFullyPriced()) {
            throw new \DomainException(sprintf('Reply %s is not fully priced yet — every requirement line needs a unit cost.', $this->documentLabel()));
        }

        $this->status = RfqVendorReplyStatus::Replied;
        $this->repliedAt = $at ?? new \DateTimeImmutable();
        $this->recalculateTotals();

        return $this;
    }

    /** The vendor said no, or the tender closed with no answer from them. Terminal. */
    public function decline(?string $reason = null): self
    {
        if ($this->status->isTerminal()) {
            throw new \DomainException(sprintf('Reply %s is already %s.', $this->documentLabel(), $this->status->value));
        }

        $this->status = RfqVendorReplyStatus::Declined;
        $this->notes = trim((string) $reason) !== ''
            ? trim((string) ($this->notes . "\n" . $reason))
            : $this->notes;

        return $this;
    }

    /**
     * This is the quote that won — set only by RfqConversionService, in the same atomic operation
     * that claims the RFQ header. Public for the identical reason PurchaseOrder::applyDerivedStatus()
     * is: the service is the only legitimate caller and PHP has no friend classes.
     */
    public function markAccepted(PurchaseOrder $purchaseOrder): self
    {
        $this->status = RfqVendorReplyStatus::Accepted;
        $this->purchaseOrder = $purchaseOrder;

        return $this;
    }

    /** Written on every OTHER invited vendor's reply the instant one is accepted — see the class docblock and #624's conducted test. */
    public function markRejected(): self
    {
        if ($this->status->isTerminal()) {
            return $this;
        }

        $this->status = RfqVendorReplyStatus::Rejected;

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

    private function documentLabel(): string
    {
        return $this->replyNumber !== '' ? $this->replyNumber : '#' . (string) $this->id;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * AbstractPurchaseDocument::getAddresses()/newAddress() contract.
     * ------------------------------------------------------------------------------------------
     * A reply has no structured address book, deliberately, for the same reason
     * Rfq::$deliveryAddress is free text and not a structured address (see that property's
     * docblock): the tender's delivery site is typed once as prose, not chosen from and matched
     * against a vendor's address book. Once a reply is accepted, RfqConversionService::convert()
     * produces a real PurchaseOrder, which HAS the structured order_to/ship_from book this class
     * intentionally does not.
     */

    public function getAddresses(): Collection
    {
        return new ArrayCollection();
    }

    protected function newAddress(string $type): AbstractPurchaseDocumentAddress
    {
        throw new \DomainException('An RFQ vendor reply has no structured address book; see Rfq::$deliveryAddress.');
    }
}
