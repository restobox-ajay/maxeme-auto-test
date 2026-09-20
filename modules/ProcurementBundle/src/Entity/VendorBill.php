<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Contract\Tax\TaxContext;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\RegionSeedData;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Contract\Document\DocumentLog;
use App\Contract\Payment\PayableDocument;
use App\Contract\Status\HasStatus;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLine;
use ProcurementBundle\Contract\Purchase\PurchaseChargeLineSnapshot;
use ProcurementBundle\Enum\VendorBillStatus;

/**
 * What a vendor says we owe them (#555). The buy-side mirror of `Invoice`, and mirrored on purpose.
 *
 * ## On the status seam — `HasStatus`, `setStatus()` is THE gate
 *
 * This used to say transitions were named actions with no `setStatus()`, mirroring `Invoice`'s OWN
 * pre-#539-stage-3 shape. That was the one place the mirror stopped short: `Invoice` moved onto one
 * public gate and owner rulings R1/R2 forbid a status verb staying publicly reachable once a
 * document is on the seam (`NoStatusVerbIsReachableCest`). This document now completes that mirror.
 * `Disputed` and `Void` are real statuses in `VendorBillStatus`, so `dispute()` and `void()` are gone
 * as public methods — reach both through `setStatus('Disputed'|'Void', $actor, $comment)`.
 * `approve()` and `returnToDraft()` stay public: `Open` and `Draft` are statuses too, but neither
 * name is the verb the seam's own test derives from them, and both write through the gate rather
 * than assigning the status themselves.
 *
 * What is carried over from `Invoice` unchanged, and why each one:
 *
 *  - **each write's own timeline entry** is written by `setStatus()` (queue item 64) rather than a
 *    subscriber, because it records intent a changeset does not have;
 *  - **the actor is passed in**, so an import or a scheduled job can sign its own work;
 *  - **audit logging is path-independent already** and is not re-implemented here;
 *  - **status is derived where it can be.** Open / Partially Paid / Paid come from the payment rows
 *    against `$total`, in whole cents, in `deriveStatus()` below — never typed;
 *  - **a voided bill keeps its number forever.** Nothing here is ever deleted.
 *
 * ## Two numbers, and why both
 *
 * `$billNumber` is ours, allocated through `DocumentNumberAllocator` like every other document
 * number in this app. `$vendorInvoiceNo` is theirs, off their paperwork, and is the only thing that
 * can answer "have we seen this one before".
 *
 * `idx_bill_vendor_invoice` exists for that question alone and it is a **duplicate-payment guard**.
 * Paying the same invoice twice is the single most expensive clerical error in AP, and it happens
 * because the same PDF arrives twice by email. The guard **warns and does not block**: vendors do
 * genuinely reuse and recycle numbers, and a hard block would eventually force somebody to enter a
 * real bill under a fake number, which is worse than the duplicate it prevented.
 *
 * ## Where this mirror used to be shallower, and no longer is
 *
 * `Invoice` derives its payment status by summing `invoice_payment` rows. #555 shipped without
 * those rows — vendor payments were out of scope — so a bill carried `amount_paid`, one cumulative
 * figure overwritten by whoever typed last, and said the derivation was *"already in the right
 * shape for the day those rows exist; only its input changes"*.
 *
 * That day is this change. `vendor_bill_payment` rows exist, `getAmountPaid()` sums them, the
 * column is gone, and nothing else moved: `VendorBillStatusDeriver` still reads `isSettled()` and
 * `hasPaidAnything()` and cannot tell the difference. What the column could not do and the rows
 * can: say WHICH payments make up the balance, let a mis-keyed one be corrected rather than
 * overwritten by hand, and stop two clerks recording the same amount from silently clobbering each
 * other instead of summing.
 */
#[ORM\Entity(repositoryClass: \ProcurementBundle\Repository\VendorBillRepository::class)]
#[ORM\Table(name: 'vendor_bill')]
#[ORM\UniqueConstraint(name: 'uniq_bill_number', fields: ['billNumber'])]
#[ORM\Index(name: 'idx_bill_vendor_invoice', fields: ['vendor', 'vendorInvoiceNo'])]
#[ORM\Index(name: 'idx_bill_status', fields: ['status'])]
#[ORM\Index(name: 'idx_bill_due', fields: ['dueDate'])]
class VendorBill extends AbstractPurchaseDocument implements PayableDocument, HasStatus
{
    /** Which status vocabulary governs this document — read through late static binding. */
    public const STATUS_VOCABULARY = 'vendor_bill';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Ours. */
    #[ORM\Column(name: 'bill_number', length: 32)]
    private string $billNumber = '';

    /** Theirs — the duplicate-payment guard's whole basis. */
    #[ORM\Column(name: 'vendor_invoice_no', length: 80, nullable: true)]
    private ?string $vendorInvoiceNo = null;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class)]
    #[ORM\JoinColumn(name: 'purchase_order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PurchaseOrder $purchaseOrder = null;

    #[ORM\Column(length: 20, enumType: VendorBillStatus::class)]
    private VendorBillStatus $status = VendorBillStatus::Draft;

    /** Plain 'Y-m-d' — a due date is a calendar day, with the same no-instant reasoning. */
    #[ORM\Column(name: 'due_date', length: 10, nullable: true)]
    private ?string $dueDate = null;

    /**
     * Where this bill's goods landed — the receiving warehouse, and the only thing `$taxProvince`
     * below is ever derived from (queue item 32).
     *
     * A bill against a purchase order lands where that PO says, so the save takes it from the order
     * rather than offering a second answer; the vendor is settled the same way and for the same
     * reason. A standalone bill — the case #658 made first-class — has no order to ask, so it names
     * its own, which is why this is a column on the bill and not a read through
     * `$purchaseOrder->getWarehouse()`.
     *
     * Nullable, because a standalone bill entered before anybody picks one is a real state and the
     * screens have to survive it. ON DELETE SET NULL costs the provenance and never the tax: by the
     * time a warehouse could be deleted the province is already frozen in the column below, so an
     * unlinked bill still says what it was taxed at — it just stops saying which building answered.
     */
    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Warehouse $warehouse = null;

    /**
     * Which province's tax rules apply to this bill — a code ('BC', 'ON'), or null.
     *
     * ## Derived at entry, frozen on the document (queue item 32)
     *
     * On the sell side the province comes from the document's SHIPPING address: where the goods go.
     * The buy-side equivalent is where they LAND, which is `$warehouse`. This column USED TO BE
     * TYPED, because `App\Entity\Warehouse` held a name, a status and a created-at and nothing else
     * — so the same fact was answered by hand on every bill, and an 'AB' typed onto a Surrey
     * warehouse's bill computed GST-only with nothing in the system able to contradict it. A
     * warehouse now has an address, so nobody types into this column any more: there is no public
     * setter for it, and `deriveTaxProvinceFrom()` below is the only way a value gets in.
     *
     * **The column stays, and staying is the point.** It is a FROZEN SNAPSHOT, not a cache of a
     * join — moving a warehouse next year must not silently restate last year's tax. That is the
     * same rule `CompanyIdentity` states for a customer's identity, `PurchaseOrder::issue()` applies
     * to `$vendorAddress`, and every `AbstractDocumentAddress` applies to an address. Reading the
     * province live off `$warehouse` at render time would reintroduce exactly the drift those three
     * exist to prevent.
     *
     * The freeze point is the same one the rest of this document already uses: a draft re-derives on
     * every save, because a draft is a plan rather than a record and nothing has been authorised
     * yet; once the bill is approved `VendorBillController::save()` refuses to edit it at all, so
     * the last derivation before approval is the one that stands forever. `deriveTaxProvinceFrom()`
     * refuses outright off Draft rather than relying on that controller guard, so the rule lives
     * with the data.
     *
     * Null still means "nobody has recorded a province", which is what a warehouse with no address
     * yet produces. `PurchaseTaxBreakdown` hands a blank province to the shared resolver, no
     * calculator claims it, the failure is logged and the answer is $0 rather than an exception —
     * and that silent $0 is precisely why `approve()` refuses to authorise a TAXABLE bill in this
     * state. See that method for the reasoning and for why an exempt bill is let through.
     */
    #[ORM\Column(name: 'tax_province', length: 8, nullable: true)]
    private ?string $taxProvince = null;

    /**
     * The charges frozen onto this bill — freight, brokerage, duty, a one-off.
     *
     * A JSON snapshot, not a table and not a scalar; see `PurchaseChargeLineSnapshot` for the full
     * argument. Null means no charges: one state in the column, not two.
     */
    #[ORM\Column(name: 'charge_lines', type: 'text', nullable: true)]
    private ?string $chargeLines = null;

    /**
     * The itemised tax breakdown frozen at save, in the SAME JSON shape as `invoice.tax_lines`.
     *
     * Byte-compatible by construction rather than by agreement: written and read through
     * `App\Service\OrderTaxBreakdownService`'s own `toJson()`/`tryDecodeTaxLinesJson()` pair, which
     * are the two genuinely document-agnostic methods on that class.
     *
     * This is what makes `$tax` a DERIVED figure rather than something an admin types into a box:
     * the bill's tax is the sum of the rows in here, so every cent of it has a row behind it that
     * can be printed and argued with — including the vendor's own figure, which is entered as a
     * manual row rather than as a replacement for the calculation.
     */
    #[ORM\Column(name: 'tax_lines', type: 'text', nullable: true)]
    private ?string $taxLines = null;

    /**
     * Where payment for this bill goes, frozen when the bill was entered (#606).
     *
     * The bill is the document that leads to a payment, so it is the document that has to carry the
     * remit-to — and a remit-to is frequently a DIFFERENT LEGAL ENTITY from the vendor: a factoring
     * company, a parent's accounts department, a lockbox. Paying the address on the purchase order
     * is how money goes to the wrong company, and until this column there was nowhere else to look.
     *
     * Frozen, not joined, for the reason every other snapshot here is: a supplier who changes
     * factor next year has not changed where last year's bill was paid.
     *
     * NULL on every bill that exists. Nothing is inferred for them — the address on file today is
     * not evidence of where a bill from two years ago was actually paid.
     */
    #[ORM\Column(name: 'remit_to_address', type: 'text', nullable: true)]
    private ?string $remitToAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /** @var Collection<int, VendorBillLine> */
    #[ORM\OneToMany(targetEntity: VendorBillLine::class, mappedBy: 'bill', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    /**
     * This bill's own frozen Remit To address — the buy-side mirror of `SalesOrder::$orderAddresses`,
     * one type only (see {@see VendorBillAddress}).
     *
     * @var Collection<int, VendorBillAddress>
     */
    #[ORM\OneToMany(targetEntity: VendorBillAddress::class, mappedBy: 'bill', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $billAddresses;

    /** @var Collection<int, VendorBillLog> */
    #[ORM\OneToMany(targetEntity: VendorBillLog::class, mappedBy: 'bill', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $logs;

    /**
     * The claims against this bill from one or more payments (became claims rather than raw payment
     * rows at #708, once one payment needed to settle several bills).
     *
     * orphanRemoval, exactly as `Invoice::$applications` is: a claim taken back off a bill did not
     * happen, and a claim that did not happen is not a claim of zero. What survives a deletion is the
     * timeline entry `withdrawApplication()` writes and the audit log's own record of it.
     *
     * @var Collection<int, VendorBillPaymentApplication>
     */
    #[ORM\OneToMany(targetEntity: VendorBillPaymentApplication::class, mappedBy: 'bill', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['appliedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $applications;

    /**
     * The other side of $balance (#771): debit landed here from one or more debit memos. Read-only
     * from this side — DebitMemo::applyTo()/withdrawApplication() are the only writers, so this
     * carries no cascade and no orphanRemoval; a bill never owns or deletes these rows, it only
     * reads what has landed on it. Mirrors `Invoice::$creditApplications` exactly (#603).
     *
     * @var Collection<int, DebitMemoApplication>
     */
    #[ORM\OneToMany(targetEntity: DebitMemoApplication::class, mappedBy: 'vendorBill')]
    #[ORM\OrderBy(['appliedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $debitApplications;

    public function __construct()
    {
        parent::__construct();
        $this->lines = new ArrayCollection();
        $this->billAddresses = new ArrayCollection();
        $this->logs = new ArrayCollection();
        $this->applications = new ArrayCollection();
        $this->debitApplications = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getBillNumber(): string { return $this->billNumber; }
    public function setBillNumber(string $billNumber): self { $this->billNumber = $billNumber; return $this; }

    /** The CommercialDocument view of $billNumber. */
    public function getDocumentNumber(): string { return $this->billNumber; }

    public function getVendorInvoiceNo(): ?string { return $this->vendorInvoiceNo; }
    public function setVendorInvoiceNo(?string $vendorInvoiceNo): self { $this->vendorInvoiceNo = $vendorInvoiceNo; return $this; }
    public function getPurchaseOrder(): ?PurchaseOrder { return $this->purchaseOrder; }
    public function setPurchaseOrder(?PurchaseOrder $purchaseOrder): self { $this->purchaseOrder = $purchaseOrder; return $this; }
    /** `HasStatus::getStatus()` — a plain string, per the interface. See `getStatusEnum()` for the type. */
    public function getStatus(): string { return $this->status->value; }

    /** Typed accessor for new code. Non-nullable: the column is enum-typed with no legacy strings. */
    public function getStatusEnum(): VendorBillStatus { return $this->status; }

    /**
     * `HasStatus::canEditOnStatus()`. Replaces the bare `getStatus() !== VendorBillStatus::Draft`
     * check that used to sit inline in `VendorBillController::save()`; the rule itself lives on
     * `VendorBillStatus::allowsEditing()`.
     */
    public function canEditOnStatus(): bool
    {
        return $this->status->allowsEditing();
    }
    public function getDueDate(): ?string { return $this->dueDate; }
    public function setDueDate(?string $dueDate): self { $this->dueDate = $dueDate; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    public function getRemitToAddress(): ?string { return $this->remitToAddress; }
    public function setRemitToAddress(?string $remitToAddress): self { $this->remitToAddress = $remitToAddress; return $this; }

    public function getAddresses(): Collection { return $this->billAddresses; }

    protected function newAddress(string $type): AbstractPurchaseDocumentAddress
    {
        $address = (new VendorBillAddress())->setType($type);
        $address->setBill($this);
        $this->billAddresses->add($address);

        return $address;
    }

    /** The structured Remit To snapshot, if this bill's own panel has one. */
    public function getRemitToAddressSnapshot(): ?VendorBillAddress
    {
        /** @var ?VendorBillAddress $address */
        $address = $this->getAddress(VendorBillAddress::TYPE_REMIT_TO);

        return $address;
    }

    /** Frozen once saved with one, otherwise resolved live off the vendor's own remit-to address. */
    public function getEffectiveRemitToAddress(): ?VendorBillAddress
    {
        /** @var ?VendorBillAddress $address */
        $address = $this->getEffectiveAddress(VendorBillAddress::TYPE_REMIT_TO);

        return $address;
    }

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

    public function getWarehouse(): ?Warehouse { return $this->warehouse; }
    public function setWarehouse(?Warehouse $warehouse): self { $this->warehouse = $warehouse; return $this; }

    public function getTaxProvince(): ?string { return $this->taxProvince; }

    /**
     * Take the tax province from the receiving warehouse. The ONLY way this column is ever written.
     *
     * There is deliberately no `setTaxProvince()`. A public string setter is what let a person type
     * 'AB' onto a Surrey warehouse's bill, and a setter kept "for imports" would keep that door
     * open — the same argument the class docblock makes for there being no `setStatus()`.
     *
     * Refused off Draft. The freeze is a rule about the DOCUMENT, so it lives here rather than only
     * in the controller that happens to guard the edit screen today: an approved bill is an
     * authorisation for money to leave, and re-deriving its province afterwards would restate the
     * tax behind an authorisation that has already been given. `PurchaseOrder::issue()` freezes
     * `$vendorAddress` at the same kind of boundary for the same reason.
     *
     * Normalised on the way in, the way every other province in this application is: a value object
     * comparing 'BC' === 'BC' never has to look anything up. `Warehouse::setProvince()` has already
     * resolved it once; doing it again here costs nothing and means this method is safe whatever
     * wrote the warehouse row.
     */
    public function deriveTaxProvinceFrom(?Warehouse $warehouse): self
    {
        if ($this->status !== VendorBillStatus::Draft) {
            throw new \DomainException(sprintf(
                'Bill %s is %s. Its tax province was frozen when it left Draft and cannot be re-derived — '
                    . 'moving a warehouse does not restate tax that has already been authorised.',
                $this->documentLabel(),
                $this->status->value,
            ));
        }

        $this->warehouse = $warehouse;

        $resolved = RegionSeedData::resolveProvinceAnyCountry(trim((string) $warehouse?->getProvince()));
        $this->taxProvince = $resolved !== '' ? $resolved : null;

        return $this;
    }

    /**
     * The highest tax class across this bill's goods — 'S' beats 'G' beats 'E'.
     *
     * What a freight row is taxed at by default, because freight on mixed goods is charged at the
     * highest class among them. The sell side's `AbstractSalesDocument::getHighestTaxClass()` states
     * the same rule for the same position in the document, and both run it through the SHARED
     * `TaxContext::resolveHighestTaxClass()` so there is one priority order rather than two.
     */
    public function getHighestTaxClass(): string
    {
        $codes = [];
        foreach ($this->lines as $line) {
            $codes[] = $line->getTaxCode();
        }

        return TaxContext::resolveHighestTaxClass($codes);
    }

    /**
     * Refuse to approve a TAXABLE bill whose province nobody has recorded (queue item 32).
     *
     * Approving is authorising money to leave. With no province the shared calculators are handed a
     * blank, no calculator claims it, the failure is logged and the tax is $0 — and a $0 derived
     * from nothing is indistinguishable on the document from a genuine zero. That is the exact
     * shape of the defect this work exists to close, so the last moment it can still be caught is
     * the moment the money is authorised.
     *
     * **An exempt bill is let through**, and that is a decision rather than an oversight. Refusing
     * every province-less bill would block a legitimate one — all-exempt goods, a foreign supplier
     * charging no Canadian tax — to guard a calculation that was never going to run: with a highest
     * tax class of 'E' there is no rate to get wrong and the answer is $0 whatever the province
     * says. The guard fires exactly where something is at stake.
     *
     * The remedy is named in the message rather than left to be guessed, because the fix is on a
     * different screen from the one the refusal appears on: record the receiving warehouse's
     * address, then re-save the draft, which re-derives.
     *
     * Which remedy depends on where the gap actually is, and that is ASKED rather than assumed
     * (queue item 65). This message used to state "has no province on file" of the warehouse
     * whenever the BILL had no province — two different facts. Once somebody records the address,
     * the warehouse has a province and the bill still does not, and telling them to go record an
     * address they have already recorded sends them back to a screen that is already correct. The
     * outstanding step in that state is re-saving the draft, so that is what it says.
     *
     * ## It also depends on WHICH STATE the bill is in, and that is the second half
     *
     * Every remedy above ends in the same place: a draft, saved. `approve()` accepts a DISPUTED
     * bill as well as a draft, and a disputed bill cannot be saved — `VendorBillController::save()`
     * refuses to edit anything past Draft, and `deriveTaxProvinceFrom()` refuses off Draft — so on
     * a disputed bill this message named a step nobody could take. That bill could not be approved,
     * could not be given a province, and the one instruction on its screen could not be followed.
     *
     * So the sentence splits in two. **What is missing** is a fact about the document and its
     * warehouse: the same question in every state, answered once below. **How this bill reaches a
     * draft that can supply it** is the part that varies, and {@see self::routeToASavableDraft()}
     * answers it from the status instead of assuming Draft. A draft bill's refusal is unchanged to
     * the byte.
     */
    private function assertTaxProvinceKnownIfTaxable(): void
    {
        if ($this->taxProvince !== null || $this->getHighestTaxClass() === 'E') {
            return;
        }

        // Three pieces, because the sentence has to be reassembled in a different order depending on
        // the bill's state and one of the gaps carries a step on ANOTHER screen:
        //
        //   $gap           what is missing. A fact about this document and its warehouse — the same
        //                  question and the same answer in every state.
        //   $elsewhere     the step that is not on a bill screen at all, or '' when there is none.
        //   $onTheDraft    what to do once there is a draft to do it on.
        $elsewhere = '';

        if (!$this->warehouse instanceof Warehouse) {
            $gap = 'No receiving warehouse is named on it.';
            $onTheDraft = 'Name one on the draft and save it';
        } elseif (trim((string) $this->warehouse->getProvince()) !== '') {
            $gap = sprintf(
                'Its receiving warehouse, %s, records %s, but this bill was entered before that.',
                $this->warehouse->getName(),
                $this->warehouse->getProvince(),
            );
            $onTheDraft = 'Save this draft again';
        } else {
            $gap = sprintf(
                'Its receiving warehouse, %s, has no province on file.',
                $this->warehouse->getName(),
            );
            $elsewhere = 'Record the address under Config > Warehouses';
            $onTheDraft = 're-save this draft';
        }

        throw new \DomainException(sprintf(
            'Bill %s has taxable goods but no tax province. %s %s — the province is taken from the warehouse, '
                . 'and approving without one would authorise a tax figure derived from nothing.',
            $this->documentLabel(),
            $gap,
            $this->routeToASavableDraft($elsewhere, $onTheDraft),
        ));
    }

    /**
     * The remedy, in the order THIS bill's state makes it true in.
     *
     * A draft is already a draft, so the two pieces join exactly as they always have and a draft
     * bill's refusal is unchanged to the byte. A disputed bill has to be returned to draft first and
     * says so, naming {@see self::returnToDraft()} — a control on the bill's own page, the same
     * page the refusal is read on. A disputed bill carrying a payment cannot be returned at all, so
     * rather than send somebody at an action that would be refused, it names the step that unblocks
     * that one.
     *
     * The middle case is read from {@see self::canReturnToDraft()} rather than re-derived here, so
     * this sentence and the control it sends the reader to cannot disagree about whether it works.
     */
    private function routeToASavableDraft(string $elsewhere, string $onTheDraft): string
    {
        if ($this->status === VendorBillStatus::Draft) {
            return $elsewhere === ''
                ? $onTheDraft
                : sprintf('%s, then %s', $elsewhere, $onTheDraft);
        }

        if ($this->canReturnToDraft()) {
            $return = 'return it to draft — the control is on this bill\'s own page — and then';

            return $elsewhere === ''
                ? sprintf('%s %s', ucfirst($return), lcfirst($onTheDraft))
                : sprintf('%s, %s %s', $elsewhere, $return, $onTheDraft);
        }

        if ($this->status === VendorBillStatus::Disputed) {
            $blocked = sprintf(
                '$%s has been paid against it, so it cannot be returned to draft. Delete or move that payment on '
                    . 'this bill\'s Payments screen, then return it to draft and',
                $this->getAmountPaid(),
            );

            return $elsewhere === ''
                ? sprintf('%s %s', $blocked, lcfirst($onTheDraft))
                : sprintf('%s. %s %s', $elsewhere, $blocked, $onTheDraft);
        }

        // Unreachable today: approve() accepts Draft and Disputed only, and every other state was
        // reached THROUGH approve(), which means this assertion already passed on the way in. Stated
        // rather than left to fall off the end, because a seventh status would land here.
        return $elsewhere === ''
            ? $onTheDraft
            : sprintf('%s, then %s', $elsewhere, $onTheDraft);
    }

    /** @return Collection<int, VendorBillLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(VendorBillLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setBill($this);
        }

        return $this;
    }

    public function removeLine(VendorBillLine $line): self
    {
        $this->lines->removeElement($line);

        return $this;
    }

    /** @return Collection<int, VendorBillLog> */
    public function getLogs(): Collection { return $this->logs; }

    public function addLog(VendorBillLog $log): self
    {
        if (!$this->logs->contains($log)) {
            $this->logs->add($log);
            $log->setBill($this);
        }

        return $this;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * The status seam (HasStatus). Shared bodies are on AbstractPurchaseDocument.
     *
     * `dispute()` and `void()` are gone as PUBLIC methods — `Disputed` and `Void` are both real
     * statuses in this document's own vocabulary, so `NoStatusVerbIsReachableCest` forbids a
     * publicly-callable method of either name once this class is on the seam (owner rulings R1/R2).
     * Their guards moved into `assertStatusChangeAllowed()` below; a caller reaches both exclusively
     * through `setStatus('Disputed'|'Void', ...)` now.
     *
     * `approve()` and `returnToDraft()` stay public and unchanged in shape: `Open` and `Draft` are
     * real statuses too, but neither method's name is one of the verbs the test derives from them
     * ("approve" answers to no case here — the sell side's own docblock makes the identical point
     * about `issue()` not being a status in `invoice`'s vocabulary; "returnToDraft" is not "draft").
     * Both now write through the gate rather than assigning the status themselves.
     */

    /**
     * THE GATE, made PUBLIC on this document. The shared body is on `AbstractPurchaseDocument`.
     *
     * `$comment` for a move to Disputed is the bare REASON, not a finished sentence — `dispute()`
     * used to take one as its own required `string $reason` parameter, and the validation and the
     * "Bill disputed: " wording both moved here with it, checked and formatted together so neither
     * can be reached without the other. Checked before the from-state guard in
     * `assertStatusChangeAllowed()`, in the same order `dispute()` threw it, so a caller who supplied
     * no reason sees THIS sentence regardless of where the bill currently sits — and so that
     * formatting AFTER validation never lets a blank reason slip through disguised as a non-blank
     * comment (a pre-formatted "Bill disputed: " with nothing after the colon would otherwise pass
     * a blank check run on the finished string).
     */
    public function setStatus(string $status, DocumentActor $actor, ?string $comment = null): string
    {
        if ($status === VendorBillStatus::Disputed->value) {
            $reason = trim((string) $comment);
            if ($reason === '') {
                throw new \DomainException('Disputing a bill needs a reason — what is wrong with it.');
            }

            $comment = sprintf('Bill disputed: %s', $reason);
        }

        return parent::setStatus($status, $actor, $comment);
    }

    /** Everything `dispute()` and `void()` used to refuse, in the one place a caller can reach. */
    protected function assertStatusChangeAllowed(string $from, string $to): void
    {
        if ($to === VendorBillStatus::Open->value) {
            $this->assertTaxProvinceKnownIfTaxable();
        }

        if ($to === VendorBillStatus::Void->value && $this->hasPaidAnything()) {
            throw new \DomainException(sprintf(
                'Bill %s has $%s paid against it and cannot be voided. Credit or refund it instead.',
                $this->documentLabel(),
                $this->getAmountPaid(),
            ));
        }

        if ($to === VendorBillStatus::Draft->value && $this->hasPaidAnything()) {
            throw new \DomainException(sprintf(
                'Bill %s has $%s paid against it and cannot be returned to draft — a draft can be rewritten, and '
                    . 'money has already gone out against these figures. Delete or move the payment first, or raise '
                    . 'a debit memo against the vendor.',
                $this->documentLabel(),
                $this->getAmountPaid(),
            ));
        }

        $this->assertMovableFrom($from, $to, ...match ($to) {
            VendorBillStatus::Open->value => [VendorBillStatus::Draft->value, VendorBillStatus::Disputed->value],
            VendorBillStatus::Disputed->value => self::disputableFromValues(),
            VendorBillStatus::Void->value => [
                VendorBillStatus::Draft->value,
                VendorBillStatus::Open->value,
                VendorBillStatus::Disputed->value,
            ],
            VendorBillStatus::Draft->value => [VendorBillStatus::Disputed->value],
            default => [],
        });
    }

    /** The sentences `approve()`, `dispute()`, `void()` and `returnToDraft()` write with no reason. */
    protected function defaultStatusComment(string $from, string $to): string
    {
        return match ($to) {
            VendorBillStatus::Open->value => 'Bill approved for payment.',
            VendorBillStatus::Void->value => 'Bill voided.',
            VendorBillStatus::Draft->value => 'Dispute closed; bill returned to draft for correction.',
            default => parent::defaultStatusComment($from, $to),
        };
    }

    /** `AbstractPurchaseDocument::assertMovableFrom()`'s refusal reads "A vendor bill cannot...". */
    protected function documentNoun(): string
    {
        return 'vendor bill';
    }

    protected function readStatus(): string
    {
        return $this->status->value;
    }

    protected function writeStatus(string $status): void
    {
        $this->status = VendorBillStatus::from($status);
    }

    /**
     * Constructs AND attaches, unlike the sell side's `queueActivityLogEntry()` pattern — this class
     * has not migrated onto that mechanism (see `$logs` above), so the row `setStatus()`'s shared
     * body only fills in fields on has to already be in `$this->logs` for the fields to be saved.
     */
    public function newLogEntry(): ?DocumentLog
    {
        $log = new VendorBillLog();
        $this->addLog($log);

        return $log;
    }

    /**
     * Draft -> Open. Approving a bill is authorising money to leave, which is why the summary of
     * whatever match was run is written onto the timeline rather than left on a screen nobody
     * revisits.
     *
     * `$matchSummary` is passed in rather than computed here for the reason the actor is: matching
     * a bill against its PO and its receipts is a decision about three documents, and an entity
     * that decided it alone would be deciding it in the one place a screen cannot show the working.
     */
    public function approve(DocumentActor $actor, string $matchSummary = ''): self
    {
        $summary = trim($matchSummary);

        $this->setStatus(
            VendorBillStatus::Open->value,
            $actor,
            $summary !== '' ? sprintf('Bill approved for payment. %s', $summary) : null,
        );

        return $this;
    }

    /**
     * The states a dispute will accept — named once, because three screens ask.
     *
     * It was written out three times: here, again as `$canDispute` in
     * `ExceptionController::movesForBillLine()`, and a third time in `bill_detail.html.twig` as the
     * literal strings `['Draft', 'Open', 'Partially Paid']`. All three agreed, which is the only
     * state three hand-kept copies of one rule are ever found in until the day one of them does not
     * — and the two screens fail in opposite directions when that happens: the worklist offers a
     * move that throws, or the detail page hides a form that would have worked.
     *
     * @var list<VendorBillStatus>
     */
    public const DISPUTABLE_FROM = [
        VendorBillStatus::Draft,
        VendorBillStatus::Open,
        VendorBillStatus::PartiallyPaid,
    ];

    /** {@see self::DISPUTABLE_FROM} as the plain strings `assertStatusChangeAllowed()` reads. */
    private static function disputableFromValues(): array
    {
        return array_map(static fn (VendorBillStatus $s): string => $s->value, self::DISPUTABLE_FROM);
    }

    /**
     * Would a dispute (`setStatus('Disputed', ...)`) accept this bill as it stands — the question
     * both screens that offer a dispute control are really asking.
     *
     * Asked rather than re-derived, so a control is shown exactly when the action behind it would
     * succeed. See {@see self::DISPUTABLE_FROM}.
     */
    public function canBeDisputed(): bool
    {
        return \in_array($this->status, self::DISPUTABLE_FROM, true);
    }

    /**
     * Disputed -> Draft. Un-dispute it: take the park off and put the document back in somebody's
     * hands to correct.
     *
     * ## Why this exists
     *
     * Disputing is *parking*, and until this method a park had two exits and no way back.
     * `approve()` accepts a disputed bill — "we argued, we lost, pay it" — and `void()` accepts one
     * — "the bill was wrong, withdraw it". The third answer is the one an AP clerk actually reaches
     * for most often and the only one that had no route: *the vendor sent a corrected invoice, and
     * this document needs changing to match it.* Changing a document means editing it, editing
     * means Draft, and nothing moved a bill back there.
     *
     * That gap was not only inconvenient, it was a dead end. `approve()` refuses a taxable bill with
     * no tax province and tells the reader to re-save the draft; `VendorBillController::save()`
     * refuses to edit anything past Draft and
     * {@see self::deriveTaxProvinceFrom()} refuses off Draft. A bill disputed while still a draft,
     * with taxable goods and no province, could therefore be neither approved nor fixed nor put
     * back into a state where it could be, and the only instruction on its screen named a step that
     * did not exist. See {@see self::routeToASavableDraft()}, which now names this one.
     *
     * ## Refused once money has gone out, and that is the whole guard
     *
     * The same rule, for the same reason, as `void()`: once a payment has been made the bill is a
     * real accounting record. Draft is where the figures can be rewritten, and rewriting the
     * figures behind money that has already left is how a payment ends up settling a total that no
     * longer exists. Credit it with a debit memo, or amend the payment; do not re-draft it.
     *
     * Nothing weaker is needed. A disputed bill that carries no payment has authorised nothing —
     * `applyDerivedStatus()` only ever moves between Open, Partially Paid and Paid, so the *only*
     * way past Draft is `approve()`, and an approval with no money behind it is an authorisation
     * nobody acted on. Withdrawing it here is a named action with an actor and a timeline entry,
     * not drift, and `approve()` runs its full guard again on the way back out.
     *
     * ## What it does NOT accept, deliberately
     *
     * Disputed only. Open -> Draft would un-authorise a live payable with nothing recorded about
     * why; the way to take an approved bill out of the payment run is to dispute it, which is
     * exactly what {@see self::dispute()} is for and what this then undoes.
     */
    public function returnToDraft(DocumentActor $actor, ?string $reason = null): self
    {
        $reason = trim((string) $reason);

        $this->setStatus(
            VendorBillStatus::Draft->value,
            $actor,
            $reason !== '' ? sprintf('Dispute closed; bill returned to draft for correction. %s', $reason) : null,
        );

        return $this;
    }

    /**
     * Would {@see self::returnToDraft()} accept this bill as it stands.
     *
     * The `canBeDisputed()` rule applied to the opposite move, and it exists for the same reason: a
     * control is offered exactly when the action behind it would succeed, so the bill's page cannot
     * show a button that throws. The refusal message in
     * {@see self::routeToASavableDraft()} reads it too, so the sentence telling somebody to return
     * a bill to draft and the control that does it can never disagree.
     */
    public function canReturnToDraft(): bool
    {
        return $this->status === VendorBillStatus::Disputed && !$this->hasPaidAnything();
    }

    /*
     * `void()` is gone as a public method — `Void` is a real status in this vocabulary, so
     * `NoStatusVerbIsReachableCest` forbids it once this class is on the seam. Its guard is in
     * `assertStatusChangeAllowed()` above; reach it through
     * `setStatus('Void', $actor, 'Bill voided: ' . $reason)`, or `setStatus('Void', $actor)` for the
     * bare 'Bill voided.' `defaultStatusComment()` writes with no reason.
     */

    /**
     * Record a brand-new payment and apply the whole of it to this bill in one step — the
     * single-bill convenience button (#708 kept this exact call shape on purpose, mirroring
     * `Invoice::recordPayment()`).
     *
     * This replaces `recordPaymentToDate()`, which took a cumulative figure because there was
     * nothing to sum. Now there is. The cumulative shape was defensible only while a bill could not
     * hold payment rows — it made two clerks entering the same cheque overwrite one another rather
     * than double it, at the cost of making a correction impossible without arithmetic done by
     * hand. Rows give both: `amendApplication()` corrects one, and two rows are two rows.
     */
    public function recordPayment(DocumentActor $actor, VendorBillPayment $payment, ?string $note = null): self
    {
        $payment->setVendor($this->vendor);
        $this->applyPayment($actor, $payment, $payment->getAmount(), $payment->getPaidAt(), $note);

        return $this;
    }

    /**
     * Claim part or all of an ALREADY-RECORDED payment against this bill — what the multi-bill
     * picker calls once per bill it is splitting a payment across, and what `recordPayment()` above
     * calls once, in full, for the single-bill path.
     *
     * Every rule this can fail on — Void, wrong vendor, wrong currency, not enough of the payment
     * left — is `VendorBillPayment::applyTo()`'s, asked before anything is written here.
     */
    public function applyPayment(
        DocumentActor $actor,
        VendorBillPayment $payment,
        string $amount,
        ?\DateTimeImmutable $appliedAt = null,
        ?string $note = null,
    ): VendorBillPaymentApplication {
        $application = $payment->applyTo($this, $amount, $appliedAt);
        $this->applications->add($application);

        $this->addLog(
            (new VendorBillLog())
                ->setUserName($actor->displayName)
                ->setComment(sprintf(
                    'Payment of $%s via %s recorded.%s',
                    $application->getAmount(),
                    $payment->getMethod(),
                    self::suffix($note ?? $payment->getComment()),
                ))
                ->setType('System'),
        );

        return $application;
    }

    /**
     * A claim corrected — the wrong figure typed, the wrong date, the wrong method.
     *
     * The amount and date belong to THIS application; the method and comment belong to the
     * underlying payment and are shared with every other bill it also settles. The new values are
     * applied here rather than by the caller so that the entry can say what changed: "was $40.00" is
     * the only part of a correction anybody needs from the history, and a caller that had already
     * written the new values over the old ones could no longer supply it.
     */
    public function amendApplication(
        DocumentActor $actor,
        VendorBillPaymentApplication $application,
        \DateTimeImmutable $appliedAt,
        string $method,
        string $amount,
        ?string $comment,
    ): self {
        $this->assertHolds($application);
        self::assertPositive($amount);

        $payment = $application->getPayment();

        // The common case — this is the only claim this payment makes — grows or shrinks the pool
        // alongside the application, mirroring `Invoice::amendApplication()`'s identical argument:
        // it restores the pre-#708 behaviour exactly, and a payment split across several bills
        // cannot take the shortcut without inventing or discarding money elsewhere.
        if ($payment->getApplications()->count() === 1) {
            $payment->setAmount(self::money(self::cents($amount)));
        } else {
            $available = self::cents($payment->getUnappliedBalance()) + self::cents($application->getAmount());
            if (self::cents($amount) > $available) {
                throw new \DomainException(sprintf(
                    'This payment has $%s available to apply here, including what this row already'
                    . ' claims. Record a further payment, or reduce what it claims elsewhere,'
                    . ' before correcting this row to $%s.',
                    self::money($available),
                    $amount,
                ));
            }
        }

        $wasAmount = $application->getAmount();
        $wasMethod = $payment->getMethod();

        $application->setAmount(self::money(self::cents($amount)))->setAppliedAt($appliedAt);
        $payment->setMethod($method)->setComment($comment);

        return $this->addLog(
            (new VendorBillLog())
                ->setUserName($actor->displayName)
                ->setComment(sprintf(
                    'Payment updated to $%s via %s (was $%s via %s).%s',
                    $amount,
                    $method,
                    $wasAmount,
                    $wasMethod,
                    self::suffix($comment),
                ))
                ->setType('System'),
        );
    }

    /**
     * A claim taken back off the bill — recorded twice, claimed against the wrong document, or a
     * cheque that never cleared. The underlying payment is untouched and its freed amount becomes
     * available to apply elsewhere; this is the half of "move" that always runs first.
     *
     * The row is deleted rather than flagged, because `$applications` is orphanRemoval and a claim
     * that did not happen is not a claim of zero. What survives is the entry written here.
     */
    public function withdrawApplication(DocumentActor $actor, VendorBillPaymentApplication $application, ?string $reason = null): self
    {
        $this->assertHolds($application);

        $payment = $application->getPayment();
        $this->applications->removeElement($application);
        $payment->withdrawApplication($application);

        return $this->addLog(
            (new VendorBillLog())
                ->setUserName($actor->displayName)
                ->setComment(sprintf(
                    'Payment of $%s via %s deleted.%s',
                    $application->getAmount(),
                    $payment->getMethod(),
                    self::suffix($reason),
                ))
                ->setType('System'),
        );
    }

    /**
     * Move a claim onto a different bill — queue item 34, and the reason it is not a delete
     * followed by a re-key.
     *
     * ## What a move is, and what it is not
     *
     * The underlying PAYMENT SURVIVES. Its id, the date the money left, the method, the reference
     * and the admin who recorded it all stay exactly as they were, and anything pointing at the
     * payment still points at the same payment (#708: realised as withdraw-then-reapply on that same
     * payment rather than a re-pointed claim row, now that a claim's own id was never the thing this
     * feature exists to preserve). That is the whole feature: deleting and re-entering loses every
     * one of those unless somebody copies them by hand first, and a mis-keyed reference is how a
     * cheque goes missing in a reconciliation six weeks later.
     *
     * ## Two timeline entries, not one
     *
     * The bill that lost the money and the bill that gained it are two documents whose history two
     * different people read. Each says where the payment went or came from, by number, so neither
     * timeline ends in an unexplained change of balance.
     */
    public function moveApplication(
        DocumentActor $actor,
        VendorBillPaymentApplication $application,
        VendorBill $target,
        ?string $reason = null,
    ): self {
        $this->assertHolds($application);

        if ($target === $this) {
            throw new \DomainException(sprintf(
                'That payment is already on %s. Choose a different bill to move it to.',
                $this->documentLabel(),
            ));
        }

        $payment = $application->getPayment();
        $amount = $application->getAmount();
        $appliedAt = $application->getAppliedAt();

        // Checked BEFORE either collection is touched, so a refused move leaves the claim exactly
        // where it was rather than withdrawing it first and discovering the target will not take it.
        $payment->assertApplicableTo($target);

        $this->applications->removeElement($application);
        $payment->withdrawApplication($application);

        $newApplication = $payment->applyTo($target, $amount, $appliedAt);
        $target->applications->add($newApplication);

        $suffix = self::suffix($reason);

        $target->addLog(
            (new VendorBillLog())
                ->setUserName($actor->displayName)
                ->setComment(sprintf(
                    'Payment of $%s via %s moved in from bill %s. It keeps its original date (%s) and reference.%s',
                    $amount,
                    $payment->getMethod(),
                    $this->documentLabel(),
                    $appliedAt->format('Y-m-d'),
                    $suffix,
                ))
                ->setType('System'),
        );

        return $this->addLog(
            (new VendorBillLog())
                ->setUserName($actor->displayName)
                ->setComment(sprintf(
                    'Payment of $%s via %s moved to bill %s.%s',
                    $amount,
                    $payment->getMethod(),
                    $target->documentLabel(),
                    $suffix,
                ))
                ->setType('System'),
        );
    }

    /*
     * ------------------------------------------------------------------------------------------
     * PayableDocument
     * ------------------------------------------------------------------------------------------
     */

    /**
     * Null when this bill may hold a payment, otherwise the reason it may not.
     *
     * The rule lives in {@see VendorBillStatus::acceptsPayment()} and the WORDING lives here,
     * because the enum has no bill to name and a refusal that cannot say which bill it is about is
     * half a message.
     */
    public function paymentRefusal(): ?string
    {
        if ($this->status->acceptsPayment()) {
            return null;
        }

        return sprintf(
            'Bill %s is %s; it is owed nothing and cannot take a payment.',
            $this->documentLabel(),
            $this->status->value,
        );
    }

    /** Public because a refusal raised outside this class still has to name this bill. */
    public function getDocumentLabel(): string
    {
        return $this->documentLabel();
    }

    /**
     * `vendor:12`. Compared for equality and never parsed — see
     * {@see PayableDocument::getPaymentCounterpartyKey()} for why this is a key and not a name.
     */
    public function getPaymentCounterpartyKey(): string
    {
        $vendor = $this->getVendor();

        // The object identity is the fallback, and it is not decoration. Two vendors that have not
        // been persisted yet both have a null id, so 'vendor:' . null makes them the SAME
        // counterparty — and the one rule that exists to stop money being moved to somebody who was
        // never paid would wave it through. In this application that is only reachable in a unit
        // test, which is exactly where a rule most needs to be checkable.
        return 'vendor:' . ($vendor->getId() ?? 'unsaved-' . spl_object_id($vendor));
    }

    /** @return Collection<int, VendorBillPaymentApplication> */
    public function getApplications(): Collection { return $this->applications; }

    /**
     * Everything applied against this bill, summed in whole cents and formatted back.
     *
     * Derived, never stored. A stored copy beside these rows would be a second answer to a question
     * the rows already answer, and it would drift the first time one was corrected or deleted —
     * which is the whole reason the screen exists. Two places holding one fact is the shape behind
     * #589, #590 and #591 in this repository.
     */
    public function getAmountPaid(): string
    {
        $cents = 0;
        foreach ($this->applications as $application) {
            $cents += self::cents($application->getAmount());
        }

        return self::money($cents);
    }

    /** @return Collection<int, DebitMemoApplication> */
    public function getDebitApplications(): Collection { return $this->debitApplications; }

    /** Everything debited against this bill by one or more debit memos (#771). */
    public function getAmountDebited(): string
    {
        $cents = 0;
        foreach ($this->debitApplications as $application) {
            $cents += self::cents($application->getAmount());
        }

        return self::money($cents);
    }

    /** A claim belongs to the bill it is being amended, withdrawn or moved on, or the call is refused. */
    private function assertHolds(VendorBillPaymentApplication $application): void
    {
        if (!$this->applications->contains($application)) {
            throw new \DomainException(sprintf(
                'That payment is not on bill %s.',
                $this->documentLabel(),
            ));
        }
    }

    /**
     * A payment of nothing, or of a negative amount, is not a payment.
     *
     * Negative money going the other way is a refund or a debit memo — this application has
     * `DebitMemo` for exactly that — and recording it as a negative payment would net it away
     * invisibly instead of leaving a document behind.
     */
    private static function assertPositive(string $amount): void
    {
        if (self::cents($amount) <= 0) {
            throw new \DomainException('A payment has to be a positive amount. Record a debit memo against the vendor instead.');
        }
    }

    /** ' Comment: x', or nothing at all — the one place the optional note is formatted. */
    private static function suffix(?string $note): string
    {
        $note = trim((string) $note);

        return $note === '' ? '' : ' Comment: ' . $note;
    }

    /**
     * What the money implies the status should be — the computation `VendorBillStatusDeriver` used
     * to hold as `statusFor()`. It is now ORCHESTRATION only, deciding WHEN to recalculate; the
     * computation lives here, and the write and the timeline row are `HasStatus::applyDerivedStatus()`'s,
     * inherited from `AbstractPurchaseDocument`.
     *
     * Null off the three decided states `VendorBillStatus::isDerivable()` names as reachable: Draft,
     * Void and Disputed are judgements, not projections — a part payment against a disputed bill
     * does not settle the dispute.
     */
    public function deriveStatus(): ?string
    {
        if (!$this->status->isDerivable()) {
            return null;
        }

        if ($this->isSettled()) {
            return VendorBillStatus::Paid->value;
        }

        return $this->hasPaidAnything()
            ? VendorBillStatus::PartiallyPaid->value
            : VendorBillStatus::Open->value;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Reads
     * ------------------------------------------------------------------------------------------
     */

    /**
     * What is still owed. Negative when the bill has been overpaid, which is information. Nets
     * both money actually paid AND debit landed here by a debit memo (#771) — a memo applying its
     * balance in full settles the bill exactly as a cash payment would, mirroring
     * `Invoice::getBalance()` (#603).
     */
    public function getBalance(): string
    {
        return self::money(self::cents($this->total) - self::cents($this->getAmountPaid()) - self::cents($this->getAmountDebited()));
    }

    /** Does payment plus applied debit cover the total. True for a bill for nothing at all, which is covered by nothing. */
    public function isSettled(): bool
    {
        return self::cents($this->getAmountPaid()) + self::cents($this->getAmountDebited()) >= self::cents($this->total);
    }

    public function hasPaidAnything(): bool
    {
        return self::cents($this->getAmountPaid()) + self::cents($this->getAmountDebited()) > 0;
    }

    /**
     * Recomputes subtotal, tax and total from what is actually on the document, in whole cents.
     *
     * ```
     * subtotal  Σ line.subtotal              the goods
     * charges   Σ charge_lines[].amount      freight, brokerage, duty, one-offs
     * tax       Σ tax_lines[].amount         the frozen breakdown, calculated + the vendor's own row
     * total     subtotal + charges + tax
     * ```
     *
     * ## Tax is no longer left alone
     *
     * This method used to end with *"tax stays what the vendor charged"*, and `$tax` was whatever an
     * admin typed into a box. The owner's 2026-09-11 ruling made tax SHARED with the sell side, so
     * it is now the sum of `tax_lines` — computed by `PurchaseTaxBreakdown` through the same
     * calculators an invoice uses. The vendor's own figure is not lost: it is entered as a manual
     * tax row, which sits beside the calculated ones and moves this total, and which a printed bill
     * can show and a person can argue with. What is gone is the un-itemised number that agreed with
     * nothing.
     *
     * Callers set `chargeLines` and `taxLines` first and then call this, exactly as the sell side's
     * save writes `fee_lines` and `tax_lines` before it totals: the document's figures are read off
     * the document rather than passed in beside it, so two callers cannot write two answers.
     */
    public function recalculateTotals(): self
    {
        $subtotal = 0;
        foreach ($this->lines as $line) {
            $subtotal += self::cents($line->getSubtotal());
        }

        $charges = 0;
        foreach ($this->getChargeLineRows() as $charge) {
            $charges += self::cents(number_format($charge->amount, 2, '.', ''));
        }

        $this->subtotal = self::money($subtotal);
        $this->total = self::money($subtotal + $charges + self::cents($this->tax));

        return $this;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return $this->documentLabel();
    }

    private function documentLabel(): string
    {
        return $this->billNumber !== '' ? $this->billNumber : '#' . (string) $this->id;
    }

}
