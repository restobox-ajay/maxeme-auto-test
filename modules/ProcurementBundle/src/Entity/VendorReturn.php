<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\Warehouse;
use App\Service\QuantityScale;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Enum\VendorReturnStatus;

/**
 * Goods physically going back to a supplier (#638) — the buy-side mirror of
 * `App\Entity\SalesReturn`, direction reversed: this records what LEFT our warehouse, not what
 * arrived at it.
 *
 * ## A plain entity, not an AbstractPurchaseDocument, and not a CommercialDocument
 *
 * Same call `GoodsReceipt` and `SalesReturn` already made, and #636 asks for it to be made
 * explicitly rather than by omission: this states what left, from which warehouse, and why. It has
 * no total, no currency and states no money owed — that is `DebitMemo`'s job, exactly as
 * `CreditMemo` is `SalesReturn`'s money counterpart on the sell side. A document that moves goods
 * and a document that states money are two different kinds of thing in this codebase, and only the
 * second implements `CommercialDocument`.
 *
 * ## No bin, lot or serial on the header or the lines
 *
 * Same argument `SalesReturnLine`'s docblock makes at length: which physical units left, from which
 * bin, is a fact about the SHIPMENT, and `inventory_detail`/`inventory_movement` already record it
 * with more precision than a document column could freeze. `VendorReturnShipService` is where that
 * happens — see its docblock for how the outbound units are actually chosen.
 *
 * ## The lifecycle, and why `Shipped` and not `Received`
 *
 * `SalesReturnStatus` calls its stock-moving state "Received" because goods arrive at OUR building.
 * Here they leave it, so the same state is named for what it actually is. See `VendorReturnStatus`
 * for the full state-by-state mirror and why `warehouse` is a PARAMETER to `ship()` rather than a
 * setter called beforehand — identical reasoning to `SalesReturn::receive()`: "the goods left" and
 * "they left from here" are one fact.
 */
#[ORM\Entity(repositoryClass: \ProcurementBundle\Repository\VendorReturnRepository::class)]
#[ORM\Table(name: 'vendor_return')]
#[ORM\UniqueConstraint(name: 'uniq_vendor_return_number', fields: ['documentNumber'])]
#[ORM\Index(name: 'idx_vendor_return_vendor', fields: ['vendor'])]
#[ORM\Index(name: 'idx_vendor_return_status', fields: ['status'])]
class VendorReturn
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'document_number', length: 32)]
    private string $documentNumber = '';

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false)]
    private Vendor $vendor;

    #[ORM\Column(length: 20, enumType: VendorReturnStatus::class)]
    private VendorReturnStatus $status = VendorReturnStatus::Requested;

    /** The order these goods were originally bought on, when anyone knows. Provenance only. */
    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class)]
    #[ORM\JoinColumn(name: 'purchase_order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PurchaseOrder $purchaseOrder = null;

    /**
     * The receipt these units were booked in on, when the return was raised from one.
     *
     * Nullable for the identical reason `SalesReturn::$invoice` is: a warehouse worker noticing
     * damaged stock has not necessarily pulled up which of several receipts it arrived on, and
     * refusing to open the document until they do is how goods sit around with no paperwork.
     */
    #[ORM\ManyToOne(targetEntity: GoodsReceipt::class)]
    #[ORM\JoinColumn(name: 'goods_receipt_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?GoodsReceipt $goodsReceipt = null;

    /** The building the goods actually left from. NULL until shipped, and set BY ship(). */
    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Warehouse $warehouse = null;

    /**
     * Frozen copy of where the package was addressed, taken at ship() — the moment this document
     * becomes the record of what was actually done, the same moment `PurchaseOrder::issue()`
     * freezes `$vendorAddress`.
     *
     * `Vendor::getReturnToAddress()` (#605/#606) is the RETURN-TO purpose, falling back to the
     * vendor's default address when nobody has assigned one — exactly the resolver #638 asks this
     * work to use rather than inventing an address model on its way past.
     */
    #[ORM\Column(name: 'return_to_address', type: 'text', nullable: true)]
    private ?string $returnToAddress = null;

    /**
     * Three stamps, each written by exactly one named transition — the return's own history, the
     * same way SalesReturn's three stamps are and for the same reason: no return_log table exists.
     */
    #[ORM\Column(name: 'requested_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'authorised_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $authorisedAt = null;

    #[ORM\Column(name: 'shipped_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $shippedAt = null;

    /** Why these goods are going back — the RMA number the vendor gave us belongs in $notes. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    /** The vendor's RMA number, who authorised it, anything the paperwork needs. Appended to by decline(). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /** @var Collection<int, VendorReturnLine> */
    #[ORM\OneToMany(targetEntity: VendorReturnLine::class, mappedBy: 'vendorReturn', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getDocumentNumber(): string { return $this->documentNumber; }
    public function setDocumentNumber(string $documentNumber): self { $this->documentNumber = $documentNumber; return $this; }
    public function getVendor(): Vendor { return $this->vendor; }
    public function setVendor(Vendor $vendor): self { $this->vendor = $vendor; return $this; }
    public function getStatus(): VendorReturnStatus { return $this->status; }
    public function getPurchaseOrder(): ?PurchaseOrder { return $this->purchaseOrder; }
    public function setPurchaseOrder(?PurchaseOrder $purchaseOrder): self { $this->purchaseOrder = $purchaseOrder; return $this; }
    public function getGoodsReceipt(): ?GoodsReceipt { return $this->goodsReceipt; }
    public function setGoodsReceipt(?GoodsReceipt $goodsReceipt): self { $this->goodsReceipt = $goodsReceipt; return $this; }
    public function getWarehouse(): ?Warehouse { return $this->warehouse; }
    public function getReturnToAddress(): ?string { return $this->returnToAddress; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function getAuthorisedAt(): ?\DateTimeImmutable { return $this->authorisedAt; }
    public function getShippedAt(): ?\DateTimeImmutable { return $this->shippedAt; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): self { $this->reason = $reason; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }

    /*
     * ------------------------------------------------------------------------------------------
     * Transitions. There is no setStatus() — see SalesReturn's class docblock for the register.
     * ------------------------------------------------------------------------------------------
     */

    /** Requested -> Authorised. The vendor has agreed to take these back — an RMA number belongs in $notes. */
    public function authorise(?\DateTimeImmutable $at = null): self
    {
        if ($this->status !== VendorReturnStatus::Requested) {
            throw new \DomainException(sprintf('Return %s is %s; only a requested return can be authorised.', $this->documentLabel(), $this->status->value));
        }

        if (QuantityScale::compare($this->totalUnits(), 0) <= 0) {
            throw new \DomainException(sprintf('Return %s authorises nothing: it has no lines with a quantity on them.', $this->documentLabel()));
        }

        $this->status = VendorReturnStatus::Authorised;
        $this->authorisedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /**
     * Authorised -> Shipped. The goods have physically left. $warehouse is a PARAMETER — see the
     * class docblock — and this method itself moves NO stock: `VendorReturnShipService` calls this
     * inside the same transaction it hands to `StockMovementService`, exactly as
     * `SalesReturnController` calls `SalesReturn::receive()` before dispatching its event. An entity
     * has no entity manager and no movement service.
     */
    public function ship(Warehouse $warehouse, ?\DateTimeImmutable $at = null): self
    {
        if ($this->status !== VendorReturnStatus::Authorised) {
            throw new \DomainException(sprintf(
                'Return %s is %s; only an authorised return can be shipped. Get the vendor\'s agreement first.',
                $this->documentLabel(),
                $this->status->value,
            ));
        }

        if (QuantityScale::compare($this->totalUnits(), 0) <= 0) {
            throw new \DomainException(sprintf('Return %s has no units on it. There is nothing to ship.', $this->documentLabel()));
        }

        $this->status = VendorReturnStatus::Shipped;
        $this->shippedAt = $at ?? new \DateTimeImmutable();
        $this->warehouse = $warehouse;
        // Vendor::getReturnToAddress() falls back to the default address, so a vendor with one
        // address records that one and a vendor with none records nothing rather than an empty
        // string — matching PurchaseOrder::freezeVendorSnapshot()'s own call exactly.
        $this->returnToAddress ??= $this->vendor->getReturnToAddress()?->toSnapshot();

        return $this;
    }

    /**
     * Requested, Authorised or Shipped -> Declined. Terminal.
     *
     * ## Requested is in that list on purpose, and was not always
     *
     * This used to start at Authorised, which meant the only way out of a mistyped DRAFT was to
     * authorise it first — to get the vendor to agree to a return nobody wanted, purely to earn the
     * right to kill it. A draft is a document nobody has committed to; abandoning one must not
     * require committing to it. Every sibling draft in this codebase already worked that way
     * (`PurchaseOrder::cancel()`, `Rfq::cancel()`, `VendorBill::void()`, `CreditMemo::void()`), and
     * `VendorReturnStatus` carries the full argument plus why no separate "Cancelled" case was
     * added for it.
     *
     * The from-state is recorded in $notes below, so a return abandoned as a draft and one refused
     * at the vendor's dock remain distinguishable — "Declined (was Requested)" against
     * "Declined (was Shipped)".
     *
     * ## What is still refused
     *
     * A terminal return. Closed and Declined are final, and re-declining one would overwrite the
     * history of a matter that is already settled. ship() and close() are untouched: stock still
     * only moves from Authorised, so no draft can reach the ledger through here.
     */
    public function decline(?string $reason = null, ?\DateTimeImmutable $at = null): self
    {
        if ($this->status->isTerminal()) {
            throw new \DomainException(sprintf('Return %s is already %s and cannot be declined.', $this->documentLabel(), $this->status->value));
        }

        $previous = $this->status;
        $this->status = VendorReturnStatus::Declined;

        $reason = trim((string) $reason);
        $note = $reason !== ''
            ? sprintf('[%s] Declined (was %s): %s', ($at ?? new \DateTimeImmutable())->format('Y-m-d'), $previous->value, $reason)
            : sprintf('[%s] Declined (was %s).', ($at ?? new \DateTimeImmutable())->format('Y-m-d'), $previous->value);

        $this->notes = trim((string) $this->notes) === '' ? $note : $this->notes . "\n" . $note;

        return $this;
    }

    /** Shipped -> Closed. Terminal. Needs no debit memo to exist, mirroring SalesReturn::close(). */
    public function close(): self
    {
        if ($this->status !== VendorReturnStatus::Shipped) {
            throw new \DomainException(sprintf('Return %s is %s; only a shipped return can be closed.', $this->documentLabel(), $this->status->value));
        }

        $this->status = VendorReturnStatus::Closed;

        return $this;
    }

    public function totalUnits(): string
    {
        $units = QuantityScale::canonical(0);
        foreach ($this->lines as $line) {
            $units = QuantityScale::add($units, $line->getUnits());
        }

        return $units;
    }

    public function documentLabel(): string
    {
        return $this->documentNumber !== '' ? $this->documentNumber : '#' . (string) $this->id;
    }

    /** @return Collection<int, VendorReturnLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(VendorReturnLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setVendorReturn($this);
        }

        return $this;
    }

    public function removeLine(VendorReturnLine $line): self
    {
        $this->lines->removeElement($line);

        return $this;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return $this->documentLabel();
    }
}
