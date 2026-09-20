<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\Warehouse;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Enum\RfqStatus;

/**
 * A Request for Quotation (#637) — the buy-side mirror of `App\Entity\Estimate`, for one
 * requirement put to several vendors at once.
 *
 * ## Deliberately NOT an AbstractPurchaseDocument, and not a CommercialDocument
 *
 * Every other document in this bundle is exactly one thing priced from exactly one counterparty.
 * An RFQ is neither: it names a REQUIREMENT — what is needed, how much — and has no vendor, no
 * total and no currency of its own until somebody actually quotes it. `AbstractPurchaseDocument`
 * requires a vendor; forcing one onto the header here would mean picking one of the tendered
 * vendors arbitrarily, which is exactly the fiction #636 asks to be avoided rather than papered
 * over by omission.
 *
 * The genuine mirror of Estimate — one counterparty, with prices, that converts into an order — is
 * `RfqVendorReply`: each invited vendor's own priced answer to this requirement. THAT class extends
 * `AbstractPurchaseDocument` and implements `CommercialDocument`. This class is the tender itself,
 * the same way a Dynamics/SAP RFQ header names the requirement while each vendor's response is
 * priced separately.
 *
 * ## Conversion is owned by the WINNING REPLY, not by this header
 *
 * `RfqConversionService::convert()` takes an `RfqVendorReply`, not an `Rfq` — see that service for
 * the atomic claim that makes exactly one reply per RFQ convertible, ever, even under a race.
 */
#[ORM\Entity(repositoryClass: \ProcurementBundle\Repository\RfqRepository::class)]
#[ORM\Table(name: 'rfq')]
#[ORM\UniqueConstraint(name: 'uniq_rfq_number', fields: ['documentNumber'])]
#[ORM\Index(name: 'idx_rfq_status', fields: ['status'])]
class Rfq
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'document_number', length: 32)]
    private string $documentNumber = '';

    #[ORM\Column(length: 20, enumType: RfqStatus::class)]
    private RfqStatus $status = RfqStatus::Draft;

    /**
     * Where the goods would go if a reply is accepted. Not required at Draft — the requirement can
     * be built before anyone has decided which site is short — but required before send(), for the
     * same reason PurchaseOrder::$warehouse is required outright: receiving has to put stock in a
     * real warehouse, and the winning reply's purchase order inherits this one.
     */
    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: true)]
    private ?Warehouse $warehouse = null;

    /**
     * The address the tender STATES the goods must be delivered to, as free text.
     *
     * **CORRECTION (queue item 37).** This paragraph used to say "a warehouse in this application is
     * a name and a status and nothing else — there is no address on `warehouse` to print", and that
     * stopped being true the moment queue item 32 gave `App\Entity\Warehouse` a real address. The
     * site the goods would go to IS sayable now, off `$warehouse->getAddressSummary()`.
     *
     * This column still earns its place, for the reason the paragraph should always have given:
     * a tender may state a delivery point that is NOT one of our warehouses — a customer's dock on
     * a drop-ship, a bonded site, a third-party cross-dock — and the vendor is quoting freight to
     * whatever is written here. Every vendor asked to quote is being asked to quote delivery to
     * somewhere, and freight is most of the difference between two quotes for the same goods. What
     * IS now open, and is deliberately not taken here, is defaulting this from the named
     * warehouse's address instead of leaving it blank. The RFQ's own screens now PRINT the site
     * beside the warehouse's name — that part was a one-liner and is done — but DEFAULTING this
     * column from it is not, because this column is what every vendor quotes freight against and
     * silently prefilling it changes what a tender states. That is a decision about the document,
     * not a comment fix, and it is left for a person.
     *
     * Free text and not a structured address, deliberately. `EstimateAddress` exists because a
     * quote's shipping address is chosen from a CUSTOMER's address book and is later matched,
     * geocoded and taxed against; this one is our own site, typed once, read by a human at the
     * vendor. A blank one is not an empty address — it means "not stated", and the screens fall
     * back to the company address from Settings → Company Information rather than printing nothing.
     *
     * It states no money. See the class docblock: an address is where, not how much.
     */
    #[ORM\Column(name: 'delivery_address', type: 'text', nullable: true)]
    private ?string $deliveryAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'sent_at', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'closed_at', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** @var Collection<int, RfqLine> */
    #[ORM\OneToMany(targetEntity: RfqLine::class, mappedBy: 'rfq', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, RfqVendorReply> */
    #[ORM\OneToMany(targetEntity: RfqVendorReply::class, mappedBy: 'rfq', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $replies;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lines = new ArrayCollection();
        $this->replies = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getDocumentNumber(): string { return $this->documentNumber; }
    public function setDocumentNumber(string $documentNumber): self { $this->documentNumber = $documentNumber; return $this; }
    public function getStatus(): RfqStatus { return $this->status; }
    public function getWarehouse(): ?Warehouse { return $this->warehouse; }
    public function setWarehouse(?Warehouse $warehouse): self { $this->warehouse = $warehouse; return $this; }
    public function getDeliveryAddress(): ?string { return $this->deliveryAddress; }
    public function setDeliveryAddress(?string $deliveryAddress): self { $this->deliveryAddress = $deliveryAddress; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }

    /** @return Collection<int, RfqLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(RfqLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setRfq($this);
        }

        return $this;
    }

    public function removeLine(RfqLine $line): self
    {
        $this->lines->removeElement($line);

        return $this;
    }

    /** @return Collection<int, RfqVendorReply> */
    public function getReplies(): Collection { return $this->replies; }

    public function addReply(RfqVendorReply $reply): self
    {
        if (!$this->replies->contains($reply)) {
            $this->replies->add($reply);
            $reply->setRfq($this);
        }

        return $this;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Named actions. There is no setStatus() — same register as PurchaseOrder/VendorBill.
     * ------------------------------------------------------------------------------------------
     */

    /**
     * Draft -> Sent. Tenders the requirement to whichever vendors the caller has already attached
     * replies for — see RfqController::send(), which creates one Invited RfqVendorReply per
     * selected vendor in the same request.
     */
    public function send(?\DateTimeImmutable $at = null): self
    {
        if ($this->status !== RfqStatus::Draft) {
            throw new \DomainException(sprintf('RFQ %s is %s; only a draft can be sent.', $this->documentLabel(), $this->status->value));
        }

        if ($this->lines->isEmpty()) {
            throw new \DomainException(sprintf('RFQ %s has no requirement lines. There is nothing to tender.', $this->documentLabel()));
        }

        if ($this->replies->isEmpty()) {
            throw new \DomainException(sprintf('RFQ %s names no vendors. Invite at least one before sending it.', $this->documentLabel()));
        }

        if (!$this->warehouse instanceof Warehouse) {
            throw new \DomainException(sprintf('RFQ %s has no destination warehouse. Say where the goods would go before tendering.', $this->documentLabel()));
        }

        $this->status = RfqStatus::Sent;
        $this->sentAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /**
     * Withdrawn before any reply was accepted. Refused once one has been — an accepted reply is a
     * purchase order already raised, and cancelling the RFQ underneath it would leave the order
     * pointing at a tender that claims nothing was ever agreed.
     */
    public function cancel(?\DateTimeImmutable $at = null): self
    {
        if ($this->status === RfqStatus::Accepted) {
            throw new \DomainException(sprintf(
                'RFQ %s already has an accepted reply and a purchase order raised from it. It cannot be cancelled.',
                $this->documentLabel(),
            ));
        }

        if ($this->status->isTerminal()) {
            throw new \DomainException(sprintf('RFQ %s is already %s.', $this->documentLabel(), $this->status->value));
        }

        $this->status = RfqStatus::Cancelled;
        $this->closedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /** Nobody was accepted in time. See RfqStatus::Expired. */
    public function expire(?\DateTimeImmutable $at = null): self
    {
        if ($this->status !== RfqStatus::Sent) {
            throw new \DomainException(sprintf('RFQ %s is %s; only a sent RFQ can expire.', $this->documentLabel(), $this->status->value));
        }

        $this->status = RfqStatus::Expired;
        $this->closedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /**
     * Marks the winning reply — called by RfqConversionService alongside its own atomic claim.
     *
     * Public because the service is the only legitimate caller and PHP has no friend classes; this
     * itself performs no locking and must never be called except from inside that claim.
     */
    public function markAccepted(): self
    {
        $this->status = RfqStatus::Accepted;
        $this->closedAt = new \DateTimeImmutable();

        return $this;
    }

    public function documentLabel(): string
    {
        return $this->documentNumber !== '' ? $this->documentNumber : '#' . (string) $this->id;
    }
}
