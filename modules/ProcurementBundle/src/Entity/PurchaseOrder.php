<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Contract\Document\DocumentLog;
use App\Contract\Status\HasStatus;
use App\Contract\Tax\TaxContext;
use App\Entity\Warehouse;
use App\Service\DisplayNumber;
use App\Service\DocumentActor;
use App\Service\QuantityScale;
use App\Service\RegionSeedData;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLine;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLineSnapshot;
use ProcurementBundle\Enum\PurchaseOrderStatus;

/**
 * What we asked a vendor for, and where it is going (#555).
 *
 * The buy-side mirror of `SalesOrder`, and mirrored deliberately rather than invented: #539 settled
 * how a document behaves in this codebase, and copying that is cheaper than discovering it twice.
 *
 * ## On the status seam — `HasStatus`, `setStatus()` is THE gate
 *
 * This used to say transitions were named actions with no `setStatus()`, mirroring the sell side's
 * OWN pre-#539-stage-3 shape. That was the one place the mirror stopped short: the sell side moved
 * every document onto one public gate and owner rulings R1/R2 forbid a status verb staying publicly
 * reachable once a document is on the seam (`NoStatusVerbIsReachableCest`). This document now
 * completes that mirror. `Issued` and `Cancelled` are real statuses in `PurchaseOrderStatus`, so
 * `issue()` and `cancel()` are gone as public methods — reach both through
 * `setStatus('Issued'|'Cancelled', $actor, $comment)`. `closeShort()` stays public: `Closed` is a
 * status too, but "closeShort" is not the verb the seam's own test derives from it, and it writes
 * through the gate rather than assigning the status itself.
 *
 * What is carried over unchanged, and why each one:
 *
 *  - **Each write's own timeline entry** is written by `setStatus()` (queue item 64) rather than a
 *    subscriber, because it records intent a changeset does not have: "Closed short — vendor
 *    discontinued it" and "Closed short — we cancelled the rest" are the same changeset.
 *  - **The actor is passed in, never resolved here.** An entity cannot reach the security context,
 *    and an ambient lookup would leave a console command or an import unable to sign its own work.
 *  - **Audit logging is path-independent already**, through AuditLogSubscriber, and is deliberately
 *    not re-implemented here.
 *  - **A cancelled document keeps its number forever.** Nothing here is ever deleted.
 *
 * ## `poNumber`, not `documentNumber`
 *
 * On a sales document `poNumber` is *the customer's* purchase order reference. Here the PO number
 * is the document's own identity. Same word, two families — which is the single clearest reason the
 * two document trees do not share a base. `getDocumentNumber()` is the `CommercialDocument` view of
 * the same column, and the column is per-concrete-class rather than on the superclass for the
 * footgun `AbstractSalesDocument` documents: a base property aliased by a subclass method is not
 * renamed for DQL, and every `Unrecognized field` that follows is discovered at runtime.
 *
 * ## Status is derived where it can be
 *
 * `Partially Received` and `Received` are computed from the lines, in `deriveStatus()` below — the
 * goods arriving is the event, and the status is a projection of it. `PurchaseOrderStatusDeriver` is
 * now orchestration only, deciding WHEN to ask; the computation moved onto the document, the same
 * way `SalesOrder::deriveStatus()` holds its own. `Draft`, `Issued`, `Closed` and `Cancelled` are
 * decisions, reached only through `setStatus()`. Closing a short shipment is deliberately in the
 * second group: the remainder is a decision — chase it, cancel it, write it off — and quietly
 * closing it hides money.
 */
#[ORM\Entity(repositoryClass: \ProcurementBundle\Repository\PurchaseOrderRepository::class)]
#[ORM\Table(name: 'purchase_order')]
#[ORM\UniqueConstraint(name: 'uniq_po_number', fields: ['poNumber'])]
#[ORM\Index(name: 'idx_po_vendor', fields: ['vendor'])]
#[ORM\Index(name: 'idx_po_status', fields: ['status'])]
#[ORM\Index(name: 'idx_po_expected', fields: ['expectedDate'])]
class PurchaseOrder extends AbstractPurchaseDocument implements HasStatus
{
    /** Which status vocabulary governs this document — read through late static binding. */
    public const STATUS_VOCABULARY = 'purchase_order';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'po_number', length: 32)]
    private string $poNumber = '';

    #[ORM\Column(length: 20, enumType: PurchaseOrderStatus::class)]
    private PurchaseOrderStatus $status = PurchaseOrderStatus::Draft;

    /**
     * Where the goods are going — and, since queue item 37, the ONLY thing `$taxProvince` below is
     * ever derived from.
     *
     * Required, and a real foreign key: a warehouse is live infrastructure this app owns rather
     * than a snapshot of someone else's paperwork, and receiving has to put stock in a real one.
     * Deleting a warehouse that has purchase orders against it is not a state the app offers.
     *
     * Written only by `deriveTaxProvinceFrom()`. There is no `setWarehouse()` any more, and that is
     * deliberate rather than tidying: a separate setter would let the destination move without the
     * province following it, which is two places holding one fact — the shape behind #589, #590 and
     * #591, and the shape item 37 exists to remove. One call writes both, so they cannot disagree.
     */
    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false)]
    private Warehouse $warehouse;

    /** When the vendor says it lands. Plain 'Y-m-d', same reasoning as $documentDate. */
    #[ORM\Column(name: 'expected_date', length: 10, nullable: true)]
    private ?string $expectedDate = null;

    /** Frozen copy of the vendor's address at issue time; see VendorAddress::toSnapshot(). */
    #[ORM\Column(name: 'vendor_address', type: 'text', nullable: true)]
    private ?string $vendorAddress = null;

    /** Frozen copy of the term we bought on. The live default is on the vendor. */
    #[ORM\Column(name: 'payment_term', length: 80, nullable: true)]
    private ?string $paymentTerm = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * The charges frozen onto this order — freight, brokerage, duty, a one-off (#655).
     *
     * A JSON snapshot, not a table, and not a scalar. See
     * `ProcurementBundle\Contract\Purchase\PurchaseChargeLineSnapshot` for the full argument; the
     * short of it is that the sell side deliberately REMOVED its charge-lines store in #165 step 8
     * and holds `fee_lines` and `tax_lines` as CLOB snapshots on the document instead, and that is
     * the shape being mirrored.
     *
     * Null means no charges — one state in the column, not two.
     */
    #[ORM\Column(name: 'charge_lines', type: 'text', nullable: true)]
    private ?string $chargeLines = null;

    /**
     * The itemised tax breakdown frozen at save, in the SAME JSON shape as `sales_order.tax_lines`.
     *
     * Byte-compatible by construction rather than by agreement: it is written and read through
     * `App\Service\OrderTaxBreakdownService`'s own `toJson()`/`tryDecodeTaxLinesJson()` pair, which
     * are the two genuinely document-agnostic methods on that class.
     *
     * This is what makes `$tax` below a DERIVED figure rather than something an admin types: the
     * document's tax is the sum of the lines in here, so every cent of it has a row behind it that
     * can be printed and reported on.
     */
    #[ORM\Column(name: 'tax_lines', type: 'text', nullable: true)]
    private ?string $taxLines = null;

    /**
     * Which province's tax rules apply to this purchase — a code ('BC', 'ON'), or null (#655).
     *
     * ## Derived at entry, frozen on the document (queue item 37)
     *
     * On the sell side the province comes from the document's SHIPPING address — where the goods
     * go. The buy-side equivalent is where the goods LAND, which is `$warehouse`. This column USED
     * TO BE TYPED, through a province select on the edit form, because `App\Entity\Warehouse` held
     * a name, a status and a created-at and nothing else. Queue item 32 gave a warehouse a real
     * address and did this same work on the vendor bill; this is its cheaper sibling, and cheaper
     * for one reason: `$warehouse` above is a REQUIRED foreign key already on this document, so
     * there is nothing to add. The bill needed `vendor_bill.warehouse_id` created, because a
     * standalone bill has no order to inherit a destination from. A purchase order always has one.
     *
     * **The column stays, and staying is the point.** It is a FROZEN SNAPSHOT, not a cache of a
     * join — relocating a warehouse next year must not silently restate last year's tax. That is
     * the same rule `CompanyIdentity` states for a customer's identity, that `issue()` below applies
     * to `$vendorAddress`, and that every `AbstractDocumentAddress` applies to an address. Reading
     * the province live off `$warehouse` at render time would reintroduce exactly the drift those
     * three exist to prevent.
     *
     * Nobody types into it. There is no `setTaxProvince()`; `deriveTaxProvinceFrom()` is the only
     * writer, and it refuses off Draft so the freeze lives with the DATA rather than only in the
     * controller that happens to guard the edit screen today.
     *
     * Null still means "nobody has recorded a province", which is what a warehouse with no address
     * yet produces. `PurchaseTaxBreakdown` hands a blank province to the shared resolver, no
     * calculator claims it, the failure is logged and the answer is $0 rather than an exception —
     * and that silent $0 is precisely why `issue()` refuses a TAXABLE order in this state. See
     * `assertTaxProvinceKnownIfTaxable()` for the reasoning, and for why an exempt one goes through.
     */
    #[ORM\Column(name: 'tax_province', length: 8, nullable: true)]
    private ?string $taxProvince = null;

    /**
     * @var Collection<int, PurchaseOrderLine>
     */
    #[ORM\OneToMany(targetEntity: PurchaseOrderLine::class, mappedBy: 'purchaseOrder', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    /**
     * This order's own frozen vendor addresses — Order To and Ship From — the buy-side mirror of
     * `SalesOrder::$orderAddresses`.
     *
     * @var Collection<int, PurchaseOrderAddress>
     */
    #[ORM\OneToMany(targetEntity: PurchaseOrderAddress::class, mappedBy: 'purchaseOrder', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $orderAddresses;

    /**
     * What has turned up against this order.
     *
     * Deliberately NOT cascaded and NOT orphanRemoval, unlike the lines and the logs. A receipt is
     * a record of goods physically arriving and of the stock movements that followed; it is not a
     * part of this document the way a line is. Deleting a purchase order must never delete the
     * evidence that its goods came — which is also why the join column on the other side is
     * `SET NULL`.
     *
     * @var Collection<int, GoodsReceipt>
     */
    #[ORM\OneToMany(targetEntity: GoodsReceipt::class, mappedBy: 'purchaseOrder')]
    #[ORM\OrderBy(['receivedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $receipts;

    public function __construct()
    {
        parent::__construct();
        $this->lines = new ArrayCollection();
        $this->orderAddresses = new ArrayCollection();
        $this->receipts = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getPoNumber(): string { return $this->poNumber; }
    public function setPoNumber(string $poNumber): self { $this->poNumber = $poNumber; return $this; }

    /** The CommercialDocument view of $poNumber — see the class docblock on why they are one column. */
    public function getDocumentNumber(): string { return $this->poNumber; }

    /** `HasStatus::getStatus()` — a plain string, per the interface. See `getStatusEnum()` for the type. */
    public function getStatus(): string { return $this->status->value; }

    /** Typed accessor for new code. Non-nullable: the column is enum-typed with no legacy strings. */
    public function getStatusEnum(): PurchaseOrderStatus { return $this->status; }

    /**
     * `HasStatus::canEditOnStatus()`. Replaces `isLockedForEditing()`'s negation; the rule itself
     * lives on `PurchaseOrderStatus::allowsEditing()`.
     */
    public function canEditOnStatus(): bool
    {
        return $this->status->allowsEditing();
    }

    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function getExpectedDate(): ?string { return $this->expectedDate; }
    public function setExpectedDate(?string $expectedDate): self { $this->expectedDate = $expectedDate; return $this; }
    public function getVendorAddress(): ?string { return $this->vendorAddress; }
    public function setVendorAddress(?string $vendorAddress): self { $this->vendorAddress = $vendorAddress; return $this; }
    public function getPaymentTerm(): ?string { return $this->paymentTerm; }
    public function setPaymentTerm(?string $paymentTerm): self { $this->paymentTerm = $paymentTerm; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }

    public function getChargeLines(): ?string { return $this->chargeLines; }
    public function setChargeLines(?string $chargeLines): self { $this->chargeLines = $chargeLines; return $this; }

    /**
     * The charges as objects — the only way anything should read that column.
     *
     * @return PurchaseChargeLine[]
     */
    public function getChargeLineRows(): array
    {
        return PurchaseChargeLineSnapshot::decode($this->chargeLines);
    }

    public function getTaxLines(): ?string { return $this->taxLines; }
    public function setTaxLines(?string $taxLines): self { $this->taxLines = $taxLines; return $this; }

    public function getTaxProvince(): ?string { return $this->taxProvince; }

    /**
     * Name the receiving warehouse. Delegates to `deriveTaxProvinceFrom()`, so the province cannot
     * drift from the destination — setting one IS setting the other, and there is still no way to
     * write a province independently.
     *
     * Restored after deleting it broke three separate branches that git had merged cleanly: a
     * removed public setter is invisible to a merge and only fails at runtime, and every caller
     * wanted the derivation anyway. The argument the class makes against `setTaxProvince()` is
     * untouched and is the one that mattered — a string setter let somebody choose 'AB' for a
     * Surrey warehouse and compute GST where it should have been GST+PST. Naming a destination is
     * not that.
     */
    public function setWarehouse(Warehouse $warehouse): self
    {
        return $this->deriveTaxProvinceFrom($warehouse);
    }

    /**
     * Name the receiving warehouse and take the tax province from it. The ONLY way either column is
     * ever written (queue item 37).
     *
     * There is deliberately no `setTaxProvince()`. `setWarehouse()` exists and delegates here, so
     * naming a destination and taking its province are one act. A public string setter is
     * what let a person choose 'AB' on an order going to a Surrey warehouse, computing GST-only
     * where it should have been GST+PST with nothing in the system able to contradict it — and a
     * setter kept "for imports" would keep that door open, the same argument the class docblock
     * makes for there being no `setStatus()`. Taking the warehouse as the argument rather than
     * reading `$this->warehouse` is the other half: a destination that could move without the
     * province following it is the two-places-hold-one-fact shape all over again.
     *
     * Refused off Draft. The freeze is a rule about the DOCUMENT, so it lives here rather than only
     * in `PurchaseOrderController::save()`, which happens to refuse an issued order today: an issued
     * purchase order is what the vendor is working from, and re-deriving its province afterwards
     * would restate the tax on paperwork already sent. `issue()` freezes `$vendorAddress` at the
     * same boundary for the same reason.
     *
     * Normalised on the way in, at the write boundary, the way every other province in this
     * application is — see `App\Service\Region`: addresses store codes, so a value object comparing
     * 'BC' === 'BC' never has to look anything up. `Warehouse::setProvince()` has already resolved
     * it once; doing it again costs nothing and means this is safe whatever wrote the warehouse row.
     * A blank becomes null rather than an empty string, so "nobody said" is one state.
     */
    public function deriveTaxProvinceFrom(Warehouse $warehouse): self
    {
        if ($this->status !== PurchaseOrderStatus::Draft) {
            throw new \DomainException(sprintf(
                'Purchase order %s is %s. Its tax province was frozen when it left Draft and cannot be re-derived — '
                    . 'moving a warehouse does not restate tax on an order the vendor already has.',
                $this->documentLabel(),
                $this->status->value,
            ));
        }

        $this->warehouse = $warehouse;

        $resolved = RegionSeedData::resolveProvinceAnyCountry(trim((string) $warehouse->getProvince()));
        $this->taxProvince = $resolved !== '' ? $resolved : null;

        return $this;
    }

    /**
     * Refuse to ISSUE a taxable purchase order whose province nobody has recorded (queue item 37).
     *
     * Issuing is the commitment: it is what goes to the vendor, and from here on it is what the
     * goods and the bills are matched against. With no province the shared calculators are handed a
     * blank, no calculator claims it, the failure is logged and the tax is $0 — and a $0 derived
     * from nothing is indistinguishable on the document from a genuine zero. That is the exact shape
     * of the defect this work exists to close, so the last moment it can still be caught is the
     * moment the document is committed to.
     *
     * Issue rather than approve, because a purchase order has no approve: `issue()` is its
     * Draft-to-committed transition, the position `VendorBill::approve()` occupies on the bill. The
     * guard is at the same point in the life of the document, not merely on a method with a similar
     * name.
     *
     * **An exempt order is let through**, and that is a decision rather than an oversight. Refusing
     * every province-less order would block a legitimate one — all-exempt goods, a foreign supplier
     * charging no Canadian tax — to guard a calculation that was never going to run: with a highest
     * tax class of 'E' there is no rate to get wrong and the answer is $0 whatever the province
     * says. The guard fires exactly where something is at stake.
     *
     * The remedy is named in the message rather than left to be guessed, because the fix is on a
     * different screen from the one the refusal appears on: record the warehouse's address, then
     * re-save the draft, which re-derives.
     *
     * WHICH remedy is ASKED rather than assumed, the same correction `VendorBill` took in 3d08acd3.
     * This message used to state "has no province on file" of the delivery warehouse whenever the
     * ORDER had no province — two different facts. The moment somebody records the address the
     * warehouse has a province and the order still does not, so the refusal sent them back to a
     * screen they had already fixed while the detail screen beside it printed that warehouse's
     * Surrey address in full. The outstanding step in that state is re-saving the draft, which is
     * the only thing that re-derives a frozen province, so that is what it says.
     *
     * The warehouse is a required foreign key here, unlike `vendor_bill.warehouse_id` — a bill can
     * stand alone with no destination and needs a third branch for it, a purchase order cannot.
     */
    private function assertTaxProvinceKnownIfTaxable(): void
    {
        if ($this->taxProvince !== null || $this->getHighestTaxClass() === 'E') {
            return;
        }

        $recorded = trim((string) $this->warehouse->getProvince());

        $gap = $recorded !== ''
            ? sprintf(
                'Its delivery warehouse, %s, records %s, but this order was entered before that. Save this draft again',
                $this->warehouse->getName(),
                $recorded,
            )
            : sprintf(
                'Its delivery warehouse, %s, has no province on file. Record its address under Config > Warehouses, then re-save this draft',
                $this->warehouse->getName(),
            );

        throw new \DomainException(sprintf(
            'Purchase order %s has taxable goods but no tax province. %s — the province is taken from the warehouse, '
                . 'and issuing without one would send the vendor a tax figure derived from nothing.',
            $this->documentLabel(),
            $gap,
        ));
    }

    /**
     * The highest tax class across this order's goods — 'S' beats 'G' beats 'E'.
     *
     * What a freight row is taxed at, because freight on mixed goods is charged at the highest
     * class among them. The sell side's `AbstractSalesDocument::getHighestTaxClass()` states the
     * same rule for the same position in the document, and both run it through the SHARED
     * `TaxContext::resolveHighestTaxClass()` so there is one priority order, not two.
     */
    public function getHighestTaxClass(): string
    {
        $codes = [];
        foreach ($this->lines as $line) {
            $codes[] = $line->getTaxCode();
        }

        return TaxContext::resolveHighestTaxClass($codes);
    }

    /** @return Collection<int, PurchaseOrderLine> */
    public function getLines(): Collection { return $this->lines; }

    public function getAddresses(): Collection { return $this->orderAddresses; }

    protected function newAddress(string $type): AbstractPurchaseDocumentAddress
    {
        $address = (new PurchaseOrderAddress())->setType($type);
        $address->setPurchaseOrder($this);
        $this->orderAddresses->add($address);

        return $address;
    }

    /** Where this order is sent — the buy-side mirror of an order's Billing address. */
    public function getOrderToAddressSnapshot(): ?PurchaseOrderAddress
    {
        /** @var ?PurchaseOrderAddress $address */
        $address = $this->getAddress(PurchaseOrderAddress::TYPE_ORDER_TO);

        return $address;
    }

    /** Frozen if issued, otherwise resolved live off the vendor's own order-to address on file. */
    public function getEffectiveOrderToAddress(): ?PurchaseOrderAddress
    {
        /** @var ?PurchaseOrderAddress $address */
        $address = $this->getEffectiveAddress(PurchaseOrderAddress::TYPE_ORDER_TO);

        return $address;
    }

    /** Where the vendor ships from — the buy-side mirror of Shipping. */
    public function getShipFromAddressSnapshot(): ?PurchaseOrderAddress
    {
        /** @var ?PurchaseOrderAddress $address */
        $address = $this->getAddress(PurchaseOrderAddress::TYPE_SHIP_FROM);

        return $address;
    }

    public function getEffectiveShipFromAddress(): ?PurchaseOrderAddress
    {
        /** @var ?PurchaseOrderAddress $address */
        $address = $this->getEffectiveAddress(PurchaseOrderAddress::TYPE_SHIP_FROM);

        return $address;
    }

    public function addLine(PurchaseOrderLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setPurchaseOrder($this);
        }

        return $this;
    }

    public function removeLine(PurchaseOrderLine $line): self
    {
        $this->lines->removeElement($line);

        return $this;
    }

    /** @return Collection<int, GoodsReceipt> */
    public function getReceipts(): Collection { return $this->receipts; }

    public function addReceipt(GoodsReceipt $receipt): self
    {
        if (!$this->receipts->contains($receipt)) {
            $this->receipts->add($receipt);
            $receipt->setPurchaseOrder($this);
        }

        return $this;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * The status seam (HasStatus). Shared bodies are on AbstractPurchaseDocument.
     *
     * `issue()` and `cancel()` are gone as PUBLIC methods — `Issued` and `Cancelled` are both real
     * statuses in this document's own vocabulary, so `NoStatusVerbIsReachableCest` forbids a
     * publicly-callable method of either name once this class is on the seam (owner rulings R1/R2).
     * Their guards moved into `assertStatusChangeAllowed()` below and their side effects (freezing
     * the vendor snapshot) into this class's own `setStatus()` override; a caller reaches both
     * exclusively through `setStatus('Issued', ...)` / `setStatus('Cancelled', ...)` now.
     *
     * `closeShort()` stays public and unchanged in shape: `Closed` is a real status too, but
     * "closeShort" is not one of the verbs the test derives from it (`close` is; `closeShort` is
     * not), and — per that test's own stated criterion — a method whose name is not a status verb is
     * out of scope regardless of what it does, PROVIDED it writes through the gate rather than
     * assigning the status itself. It does, below.
     */

    /**
     * THE GATE, made PUBLIC on this document. The shared body is on `AbstractPurchaseDocument`.
     *
     * The vendor snapshot freeze that used to live inside `issue()` happens here, AFTER the parent
     * call succeeds, so a refused issue (empty order, unknown tax province — both checked in
     * `assertStatusChangeAllowed()`) leaves the draft exactly as it was rather than half-frozen.
     * Checking `$wasDraft` before delegating is what tells a genuine Draft -> Issued transition
     * apart from any other call that happens to name 'Issued' — the only other one is a no-op
     * re-assertion of the status already held, which parent::setStatus() would have refused to
     * write anyway, but the freeze must not run a second time regardless.
     */
    public function setStatus(string $status, DocumentActor $actor, ?string $comment = null): string
    {
        $wasDraft = $this->status === PurchaseOrderStatus::Draft;

        $result = parent::setStatus($status, $actor, $comment);

        if ($wasDraft && $status === PurchaseOrderStatus::Issued->value) {
            $this->freezeVendorSnapshot();
        }

        return $result;
    }

    /**
     * Everything `issue()` and `cancel()` used to refuse, in the one place a caller can reach.
     *
     * The tax-province and empty-order guards run BEFORE the vendor snapshot freeze in
     * `setStatus()` above, because both throw and a refused issue must leave the draft untouched.
     */
    protected function assertStatusChangeAllowed(string $from, string $to): void
    {
        if ($to === PurchaseOrderStatus::Issued->value) {
            if ($this->lines->isEmpty()) {
                throw new \DomainException(sprintf(
                    'Purchase order %s has no lines. There is nothing to order.',
                    $this->documentLabel(),
                ));
            }

            // Queue item 37.
            $this->assertTaxProvinceKnownIfTaxable();
        }

        if ($to === PurchaseOrderStatus::Cancelled->value && $this->hasAnyReceipts()) {
            throw new \DomainException(sprintf(
                'Purchase order %s has goods received against it and cannot be cancelled. Close it short instead.',
                $this->documentLabel(),
            ));
        }

        $this->assertMovableFrom($from, $to, ...match ($to) {
            PurchaseOrderStatus::Issued->value => [PurchaseOrderStatus::Draft->value],
            PurchaseOrderStatus::Closed->value => [
                PurchaseOrderStatus::Issued->value,
                PurchaseOrderStatus::PartiallyReceived->value,
                PurchaseOrderStatus::Received->value,
            ],
            PurchaseOrderStatus::Cancelled->value => [
                PurchaseOrderStatus::Draft->value,
                PurchaseOrderStatus::Issued->value,
            ],
            default => [],
        });
    }

    /**
     * The sentences `issue()` and `cancel()` used to write when called with no reason. A caller
     * with something to say — `closeShort()`'s outstanding-quantity sentence, a cancel reason —
     * passes the whole comment, which is not consulted here.
     */
    protected function defaultStatusComment(string $from, string $to): string
    {
        return match ($to) {
            PurchaseOrderStatus::Issued->value => 'Purchase order issued to the vendor.',
            PurchaseOrderStatus::Cancelled->value => 'Purchase order cancelled.',
            default => parent::defaultStatusComment($from, $to),
        };
    }

    /** `AbstractPurchaseDocument::assertMovableFrom()`'s refusal reads "A purchase order cannot...". */
    protected function documentNoun(): string
    {
        return 'purchase order';
    }

    protected function readStatus(): string
    {
        return $this->status->value;
    }

    protected function writeStatus(string $status): void
    {
        $this->status = PurchaseOrderStatus::from($status);
    }

    public function newLogEntry(): ?DocumentLog
    {
        return $this->queueActivityLogEntry();
    }

    /**
     * Finished with, short.
     *
     * Deliberately a decision and not something the deriver does: the outstanding quantity is money
     * somebody has to choose what to do with. `$reason` is required because a PO closed short
     * without one is a question nobody can answer six months later.
     */
    public function closeShort(DocumentActor $actor, string $reason): self
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \DomainException('Closing a purchase order short needs a reason — what happened to the rest of it.');
        }

        $this->setStatus(
            PurchaseOrderStatus::Closed->value,
            $actor,
            // Through DisplayNumber, so the sentence reads "30 unit(s)" and not "30.0000 unit(s)" —
            // the quantity columns are decimals now and a log entry is prose a person reads.
            sprintf('Purchase order closed with %s unit(s) outstanding: %s', (new DisplayNumber())->qty($this->getQuantityOutstanding()), $reason),
        );

        return $this;
    }

    /**
     * The PO was emailed to the vendor.
     *
     * Not a transition — sending a copy changes nothing about the document — but it is a mutation a
     * person expects to find on the timeline, so it follows the same rule as every other one here:
     * the method that makes the change writes its own entry, and there is no way to make the change
     * without producing it.
     */
    public function recordSent(DocumentActor $actor, string $recipient, string $subject): self
    {
        $this->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf('Purchase order emailed to %s. Subject: %s', $recipient, $subject))
            ->setType('System')
            ->setRecipientNotified(true);

        return $this;
    }

    /**
     * This order was amended after it had gone to the vendor (#47).
     *
     * Not a transition — an amendment does not change which state the order is in — but it follows
     * the same rule every other mutation on this class does: the method that makes the change writes
     * its own entry, and there is no way to make the change without producing one. `recordSent()` is
     * the same shape for the same reason.
     *
     * It exists because an issued purchase order became editable. The rest of this document's life
     * is on the timeline — issued, received, closed, cancelled, emailed — and an edit that moved a
     * quantity somebody is chasing a vendor over must not be the one event that leaves no trace.
     * `$detail` is whatever the caller can say that the amended document itself no longer can: a
     * removed line, above all, since the order that comes back carries no record of a row it no
     * longer has.
     */
    public function recordAmendment(DocumentActor $actor, string $detail = ''): self
    {
        $comment = sprintf(
            'Purchase order amended: %d line(s), %s unit(s) outstanding, %s %s.',
            $this->lines->count(),
            (new DisplayNumber())->qty($this->getQuantityOutstanding()),
            $this->getCurrency(),
            $this->getTotal(),
        );

        $detail = trim($detail);
        if ($detail !== '') {
            $comment .= ' ' . $detail;
        }

        $this->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment($comment)
            ->setType('System');

        return $this;
    }

    /**
     * What the lines imply the status should be — the computation `PurchaseOrderStatusDeriver` used
     * to hold as `statusFor()`. It is now ORCHESTRATION only, deciding WHEN to recalculate; the
     * computation lives here, and the write and the timeline row are `HasStatus::applyDerivedStatus()`'s,
     * inherited from `AbstractPurchaseDocument`.
     *
     * Null — derives nothing — off the three decided states `PurchaseOrderStatus::isDerivable()`
     * names as reachable: Draft, Closed and Cancelled are judgements, not projections, so goods
     * turning up against a Closed order records what arrived and does not reopen it.
     */
    public function deriveStatus(): ?string
    {
        if (!$this->status->isDerivable()) {
            return null;
        }

        if ($this->isFullyReceived()) {
            return PurchaseOrderStatus::Received->value;
        }

        return $this->hasAnyReceipts()
            ? PurchaseOrderStatus::PartiallyReceived->value
            : PurchaseOrderStatus::Issued->value;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Reads
     * ------------------------------------------------------------------------------------------
     */

    /** Total units still owed to us across every line. */
    public function getQuantityOutstanding(): string
    {
        $outstanding = QuantityScale::canonical(0);
        foreach ($this->lines as $line) {
            $outstanding = QuantityScale::add($outstanding, $line->getQuantityOutstanding());
        }

        return $outstanding;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Billed quantity (#658)
     * ------------------------------------------------------------------------------------------
     *
     * The buy-side mirror of `SalesOrder`'s invoiced-quantity block, structure for structure:
     * derived on every read, never stored, attributed by the foreign key the bill line already
     * carries (`VendorBillLine::$purchaseOrderLine`) rather than by SKU — a bill MAY charge for
     * something the purchase order never had (freight, a substitution, a correction) and such a
     * line draws down nothing, because it has nothing to draw down.
     *
     * These two methods are where a screen or a service should ask; the arithmetic itself is on
     * PurchaseOrderLine, which is the row that knows its own bill lines.
     */

    /** How much of one purchase order line has been billed, in base units. */
    public function billedQuantityFor(PurchaseOrderLine $line, ?VendorBill $excluding = null): string
    {
        return $line->getQuantityBilled($excluding);
    }

    /**
     * What is left to bill on one line: ordered minus billed, floored at zero.
     *
     * `$excluding` is the bill being saved. Without it, re-saving a draft that already bills a line
     * would measure that line against a remainder its own quantity had already consumed, and an
     * unchanged bill would refuse to save a second time — which is how a guard stops being used.
     */
    public function unbilledQuantityFor(PurchaseOrderLine $line, ?VendorBill $excluding = null): string
    {
        return $line->getQuantityUnbilled($excluding);
    }

    /** True when no ordered quantity is left to bill on any line. */
    public function isFullyBilled(): bool
    {
        if ($this->lines->isEmpty()) {
            return false;
        }

        foreach ($this->lines as $line) {
            if (!$line->isFullyBilled()) {
                return false;
            }
        }

        return true;
    }

    /** Has every line had its full ordered quantity — the condition for the derived Received. */
    public function isFullyReceived(): bool
    {
        if ($this->lines->isEmpty()) {
            return false;
        }

        foreach ($this->lines as $line) {
            if (!$line->isComplete()) {
                return false;
            }
        }

        return true;
    }

    public function hasAnyReceipts(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->hasReceipts()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this order still expecting goods — what the screens offer a Receive button for.
     *
     * Narrower than what receiving will actually accept: a late delivery against a Closed order is
     * recordable, it just is not something anything invites. See PurchaseOrderStatus.
     */
    public function acceptsReceipts(): bool
    {
        return $this->status->acceptsReceipts();
    }

    /**
     * The line-level rules that stay in force regardless of what `canEditOnStatus()` says — this
     * document being editable is not a licence to rewrite history:
     *
     *  - `PurchaseOrderLine::hasReceipts()` — received quantity is the floor. A line cannot go below
     *    what has arrived and a received line cannot be deleted, because the receipts that recorded
     *    those goods would be left pointing at an order that never asked for them.
     *  - `PurchaseOrderLine::isPriceSettled()` — a billed line's unit cost is settled money. Note
     *    that this is BILLS and not receipts: they are different events. Receiving records that
     *    goods arrived and says nothing about what is owed, so a line received but not yet billed
     *    is still price-correctable.
     *
     * The one sentence a refused edit says, with the number and the way out in it.
     *
     * Here rather than in the controller because two routes refuse with it — the edit screen and
     * the save behind it — and a rule stated twice is a rule that disagrees with itself.
     */
    public function editingRefusalReason(): string
    {
        return sprintf(
            'Purchase order %s is %s and can no longer be edited. %s',
            $this->documentLabel(),
            $this->status->value,
            $this->status === PurchaseOrderStatus::Cancelled
                ? 'A cancelled order says nothing was ever ordered from this vendor; raise a new one.'
                : 'Its remainder was written off when it was closed; raise a new order for anything still needed.',
        );
    }

    /**
     * Recomputes subtotal, tax and total from what is actually on the document, in whole cents.
     *
     * ```
     * subtotal  Σ line.subtotal                          the goods
     * charges   Σ charge_lines[].amount                  freight, brokerage, duty, one-offs
     * tax       Σ tax_lines[].amount                     the frozen breakdown
     * total     subtotal + charges + tax
     * ```
     *
     * ## Tax is no longer left alone
     *
     * This method used to say: *"Tax is left alone: a purchase tax is a figure off the vendor's
     * paperwork, not something this app calculates."* That was overruled by the owner on
     * 2026-09-11 — tax is SHARED with the sell side, because the rules are the same rules and are
     * driven by tax classes. `$tax` is now derived here from `$taxLines`, which
     * `ProcurementBundle\Purchase\PurchaseTaxBreakdown` writes from the very same calculators the
     * sell side uses. It is no longer a number anybody types into the form.
     *
     * The concern behind the old wording is met rather than discarded: an admin who HAS a figure
     * off the vendor's paperwork types it as a manual tax charge row, which becomes a `TaxLine` of
     * `SOURCE_MANUAL` inside the same snapshot — so it is in the total, it is on the printed
     * document as its own row, and it is distinguishable from a calculated one.
     *
     * ## Rounding
     *
     * Every figure summed here is already rounded to the cent: a line's subtotal is a
     * `decimal(12,2)` column, a charge amount is rounded when the row is normalised, and a tax line
     * is rounded by the calculator that produced it. So the document's total is exactly the sum of
     * the rows printed on it — which is the whole point of rounding at the line rather than at the
     * document, and the reason this side does not follow `App\Service\SalesDocumentMoney`.
     *
     * Idempotent: calling it twice with nothing changed writes the same three figures.
     */
    public function recalculateTotals(): self
    {
        $subtotal = 0;
        foreach ($this->lines as $line) {
            $subtotal += self::cents($line->getSubtotal());
        }

        $taxCents = 0;
        foreach ($this->frozenTaxLineAmounts() as $amount) {
            $taxCents += (int) round($amount * 100);
        }

        $this->subtotal = self::money($subtotal);
        $this->tax = self::money($taxCents);
        $this->total = self::money($subtotal + $this->chargeCents() + $taxCents);

        return $this;
    }

    /**
     * Every charge on the document, in cents.
     *
     * Placement is deliberately NOT consulted: it decides where a row is DISPLAYED and whether it
     * is taxed, never whether it is owed. The sell side's total does the same — it adds its whole
     * fee total once, regardless of placement.
     */
    private function chargeCents(): int
    {
        $cents = 0;
        foreach ($this->getChargeLineRows() as $charge) {
            $cents += (int) round($charge->amount * 100);
        }

        return $cents;
    }

    /**
     * The amounts of the frozen tax lines.
     *
     * Read off the snapshot rather than recomputed, deliberately: the snapshot is what the document
     * PRINTS, so deriving the header figure from anything else is how a total comes to disagree
     * with the rows above it. Decoded defensively — a malformed column contributes no tax rather
     * than making the document unreadable.
     *
     * @return list<float>
     */
    private function frozenTaxLineAmounts(): array
    {
        if ($this->taxLines === null || trim($this->taxLines) === '') {
            return [];
        }

        $decoded = json_decode($this->taxLines, true);
        if (!is_array($decoded) || !is_array($decoded['lines'] ?? null)) {
            return [];
        }

        $amounts = [];
        foreach ($decoded['lines'] as $line) {
            if (is_array($line)) {
                $amounts[] = (float) ($line['amount'] ?? 0);
            }
        }

        return $amounts;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return $this->documentLabel();
    }

    private function freezeVendorSnapshot(): void
    {
        $this->vendorName = $this->vendor->getName();

        if ($this->vendorAddress === null) {
            // The structured Order To snapshot (PurchaseOrderAddress) wins when the draft's own
            // address panel named or edited one — the admin may have picked a DIFFERENT one of the
            // vendor's several addresses, or hand-corrected a suite number, for this order alone,
            // exactly as Order's own billing/shipping panel already lets an admin do. Falling back
            // to the vendor's live order-to address (#606) when no panel edit exists at all is what
            // keeps a database where nobody has ever touched the panel freezing exactly what it
            // always froze.
            $orderTo = $this->getEffectiveOrderToAddress();
            $this->vendorAddress = $orderTo !== null && trim($orderTo->toSnapshot()) !== ''
                ? $orderTo->toSnapshot()
                : $this->vendor->getOrderToAddress()?->toSnapshot();
        }

        if ($this->paymentTerm === null) {
            $this->paymentTerm = $this->vendor->getPaymentTerm();
        }
    }

    private function documentLabel(): string
    {
        return $this->poNumber !== '' ? $this->poNumber : '#' . (string) $this->id;
    }

}
