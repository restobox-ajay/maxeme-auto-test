<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Document\CommercialDocument;
use App\Enum\SalesReturnStatus;
use App\Service\QuantityScale;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The RMA: the authorisation a customer writes on the box, and the record that the box arrived
 * (#596).
 *
 * ## The gap this closes
 *
 * Most of the return story was already built before this class existed. `returned` detail rows map
 * into `quarantine_quantity` (#581); an inspector releases or writes off held stock from the
 * adjustment screen (#585); the money is a credit note with its own number, its own balance,
 * applications and refunds (#586). What was missing sat before all of it: **`returned` rows existed
 * ONLY as a side effect of `credit_memo.restock`.** Goods could come back if and only if a credit
 * was issued in the same act.
 *
 * Four ordinary situations were therefore inexpressible, and the first is not an edge case at all:
 *
 *  - a customer ships something back BEFORE anyone has decided whether to credit it;
 *  - goods arrive and the credit is DECLINED — out of warranty, not our fault, wrong item;
 *  - goods arrive in one accounting period and the credit is issued in the next;
 *  - goods arrive against a return nobody authorised.
 *
 * Dynamics splits this into three documents — Sales Return Order, Posted Return Receipt, Credit
 * Memo. #586 collapsed the last two, which is right for a standalone credit and wrong the moment an
 * authorised return exists. This class is the first, and it carries the receipt as a STATUS rather
 * than as a fourth table: a receipt with no bin, lot or serial of its own has nothing to store that
 * `inventory_movement_group` does not already store better, and the movement group is what the
 * receipt actually is here.
 *
 * ## Ownership, and the one rule that must not bend
 *
 * `restock` stays on the credit note and is NOT removed. A standalone credit with no RMA still needs
 * it — goodwill credits, and credits where the goods are not worth the freight to ship back.
 *
 * What changes is OWNERSHIP once an RMA exists. This document's receive() creates the `returned`
 * rows, and **a credit note carrying a `sales_return_id` REFUSES to restock** — see
 * CreditMemo::setRestock(), which throws rather than quietly ignoring the flag. Not a preference: if
 * both were allowed to fire, the same physical units would enter the ledger twice and
 * `quarantine_quantity` would read double, with no row anywhere saying which half was the mistake.
 *
 * ## How the receipt reaches the movement layer
 *
 * The same way #586's restock does, and deliberately not one step further. Core dispatches
 * SalesReturnReceivedEvent after its own flush; InventoryDepthBundle's SalesReturnReceiptSubscriber
 * is the only thing that knows the event means stock. Core therefore names no bundle class, and
 * deleting InventoryDepthBundle leaves returns being authorised and received with no dimensional
 * ledger for the goods to land in — which is the truthful outcome on an instance that has no
 * dimensional ledger, rather than a broken one.
 *
 * ## `received_quantity` MUST NOT MOVE
 *
 *     receive 10      received 10  approved 0  quarantine 0   available 10
 *     sell + ship 2   received 10  approved 2  quarantine 0   available  8
 *     return 2        received 10  approved 2  quarantine 2   available  6
 *     credit applied  received 10  approved 0  quarantine 2   available  8   correct
 *
 * Ten physical units, two held pending inspection, eight sellable. The shipment never decremented
 * `received` — the invoice's `approved` hold absorbed the departure, and this app has no mechanism
 * that decrements Starting Inventory on shipment — so crediting it on the way back would count
 * those units as having arrived twice. The subscriber's movement is `null → returned`, neither side
 * of which is `available`, so MovementRequest::receivedDelta() answers zero without needing to know
 * anything about returns.
 *
 * ## No timeline table
 *
 * `sales_return_log` is not one of this issue's tables, for CreditMemo's reason: the three stamps
 * below — requested, authorised, received — ARE the history, each written by exactly one named
 * transition, and AuditLogSubscriber picks up the status column with the acting user attached
 * because nothing excludes this entity. A log becomes worth having when an admin can type free text
 * into the timeline, which is a different feature.
 *
 * ## A CommercialDocument (#636)
 *
 * It is raised to a named customer, it carries its own number and its own rows, and it is the
 * document the two sides of a return conversation quote at each other — so it takes the contract
 * every other sell-side document now takes, and anything cross-cutting (numbering, search, an audit
 * trail, a PDF) can treat it like one.
 *
 * It does not extend `AbstractSalesDocument`, and should not: that base is priced. It carries
 * subtotal, tax, total, fee lines, tax lines, coupon codes, a shipping method and a fulfillment
 * region, and an RMA has an opinion about none of them. The contract is the part that generalises;
 * the storage is not, which is the same conclusion `AbstractPurchaseDocument` reached from the
 * other side.
 *
 * Two of the six methods are answered honestly rather than perfectly, and both are written up on
 * the methods themselves: `getTotal()` is null because the money is the `CreditMemo` and never this
 * document, and `getCounterpartyName()` is a live read because #596 gave this row no identity
 * snapshot and adding one is a schema change #636 rules out.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_return')]
#[ORM\Index(name: 'idx_sales_return_company', fields: ['company'])]
#[ORM\Index(name: 'idx_sales_return_status', fields: ['status'])]
class SalesReturn implements CommercialDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The number the customer writes on the box, which is the practical point of the whole document
     * — a parcel arriving with no reference is a parcel nobody can match to anything.
     *
     * Its own series, allocated by SalesReturnNumberGenerator. Unique, and never reused: a declined
     * return keeps its number for exactly the reason a voided credit note does.
     */
    #[ORM\Column(length: 32, unique: true)]
    private string $documentNumber = '';

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false)]
    private Company $company;

    #[ORM\Column(length: 20, enumType: SalesReturnStatus::class)]
    private SalesReturnStatus $status = SalesReturnStatus::Requested;

    /**
     * The order the goods were originally sold on, when anyone knows.
     *
     * Provenance only. It does NOT change what was ordered and nothing derives an order status from
     * it — SalesOrderStatus has no returned or shipped case and #596 adds none. An order's statuses
     * past Approved are derived from its INVOICES by SalesOrderStatusDeriver, and a return is not an
     * invoice: the customer was still billed, the sale still happened, and whether the money comes
     * back is the credit note's business. Writing "Returned" onto the order would be a fact about
     * goods stored in a column whose other values are all facts about billing.
     */
    #[ORM\ManyToOne(targetEntity: SalesOrder::class)]
    #[ORM\JoinColumn(name: 'sales_order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SalesOrder $salesOrder = null;

    /**
     * The invoice that billed the goods, when the RMA was raised from one.
     *
     * Nullable and genuinely optional, which is half of why this document exists: a customer who
     * telephones about a broken item has not told anybody which of four invoices billed it, and
     * refusing to raise the RMA until somebody finds out is how goods arrive with no paperwork.
     */
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    /**
     * The building the goods actually arrived at. NULL until receipt, and set BY receipt.
     *
     * A direct warehouse rather than the fulfillment-region name every AbstractSalesDocument
     * carries, and the difference is deliberate. A region name is resolved through
     * OrderInventoryBucketResolver::resolveLineWarehouse(), which answers null when no warehouse
     * claims that region — and CreditMemoRestockSubscriber's honest response to a null is to SKIP
     * the line with a warning, because a credit note is a financial document that must issue whether
     * or not stock could be placed. A receipt has no such fallback available to it: "the goods
     * arrived and we did not record where" is not a receipt.
     *
     * So the operator picks the warehouse on the receive form, out of the active ones, and the
     * transition refuses without it. One less indirection, and the failure is at the point where
     * somebody is standing next to the box and can answer.
     */
    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Warehouse $warehouse = null;

    /**
     * Three stamps, one per irreversible thing that happened, each written by exactly one named
     * transition below and by nothing else.
     *
     * They are not redundant with $status. Status says where the document IS; these say when it got
     * there, and the pair "received on the 3rd, credited on the 12th" is precisely the case #596
     * exists to make expressible — a status alone cannot say that the goods and the money landed in
     * different accounting periods.
     */
    #[ORM\Column(name: 'requested_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'authorised_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $authorisedAt = null;

    #[ORM\Column(name: 'received_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $receivedAt = null;

    /** Why the customer says the goods are coming back. Per line as well; see SalesReturnLine. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    /**
     * Internal working notes — what the inspector saw, why it was declined, who authorised it by
     * telephone.
     *
     * Appended to rather than replaced by decline(), so a refusal reason cannot silently erase the
     * note explaining why the return was authorised in the first place.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * Ordered explicitly, for the reason every other line collection in this app is: a collection
     * with no ORDER BY comes back in whatever order the database chose, and a receiving screen whose
     * rows move between page loads is one an operator stops trusting.
     *
     * @var Collection<int, SalesReturnLine>
     */
    #[ORM\OneToMany(targetEntity: SalesReturnLine::class, mappedBy: 'salesReturn', cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    public function getCompany(): Company { return $this->company; }
    public function setCompany(Company $company): self { $this->company = $company; return $this; }

    public function getStatus(): SalesReturnStatus { return $this->status; }

    public function getSalesOrder(): ?SalesOrder { return $this->salesOrder; }
    public function setSalesOrder(?SalesOrder $salesOrder): self { $this->salesOrder = $salesOrder; return $this; }

    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function setInvoice(?Invoice $invoice): self { $this->invoice = $invoice; return $this; }

    public function getWarehouse(): ?Warehouse { return $this->warehouse; }

    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function getAuthorisedAt(): ?\DateTimeImmutable { return $this->authorisedAt; }
    public function getReceivedAt(): ?\DateTimeImmutable { return $this->receivedAt; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): self { $this->reason = $reason; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }

    /*
     * ------------------------------------------------------------------------------------------
     * CommercialDocument (#636)
     *
     * getDocumentNumber() and getLines() are already above and already the right shape; these four
     * are what the contract adds. Every one of them reads state this row already holds — #636 is an
     * interface change with no migration behind it, and the two compromises below are recorded
     * rather than papered over with a column.
     * ------------------------------------------------------------------------------------------
     */

    /**
     * The day the return was raised.
     *
     * Derived from the `requested_at` instant, which is this document's only date. It is a genuine
     * conversion and not a free one: `$requestedAt` is a timestamp, the app pins PHP's default zone
     * to UTC, and an RMA raised at 8pm in a zone behind UTC therefore reports the following day.
     * The alternative is a calendar-date column, which is a schema change #636 rules out — so the
     * lossy conversion is done in one place, here, where it can be read, instead of by each caller
     * that wants a date off a return.
     */
    public function getDocumentDate(): string
    {
        return $this->requestedAt->format('Y-m-d');
    }

    /**
     * The customer the return is from.
     *
     * The one place this document falls short of the contract's letter, which asks for the party
     * "as it was named when the document was raised — a snapshot, never a live join". An RMA has no
     * `company_snapshot`: #596 gave it a NOT NULL `company_id` and nothing else, so the live name is
     * the only name it has, and freezing one needs a column. Renaming a customer therefore does
     * rewrite what an old RMA displays — worth knowing, and worth much less than it would be on an
     * invoice, because the money side of a return is the `CreditMemo`, which is an
     * AbstractSalesDocument and does carry the frozen identity.
     */
    public function getCounterpartyName(): string
    {
        return $this->company->getName();
    }

    /** Null for AbstractSalesDocument's reason: the sell side states no per-document currency. */
    public function getCurrency(): ?string
    {
        return null;
    }

    /**
     * Null, always — and the most important null in this class.
     *
     * An RMA states no amount. The whole reason #596 exists is that goods can come back before,
     * without, or in a different accounting period from the money, so the document that authorises
     * the return deliberately holds no total; the money is the `CreditMemo`, with its own number,
     * balance, applications and refunds. Reporting '0.00' here would say a return settled at zero,
     * which is exactly what a DECLINED return and a not-yet-credited return would then be
     * indistinguishable from.
     */
    public function getTotal(): ?string
    {
        return null;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Transitions
     * ------------------------------------------------------------------------------------------
     *
     * Named actions, and there is no setStatus(). The register is SalesOrder::approve() and
     * CreditMemo::issue()/void(), and the three reasons they give apply unchanged:
     *
     *  - an illegal move is refused where the caller's INTENT still exists. By the time a Doctrine
     *    listener sees the changeset the change is made and "who was trying to do what" is gone;
     *  - the side effects belong INSIDE the method. This is the part this repo actually judges a
     *    transition by, and it is why receive() takes the warehouse: stamping receivedAt and
     *    recording where the goods landed are the same event, and a caller that could do one without
     *    the other would eventually do exactly that;
     *  - the from-state is only knowable before the change is made, which is what lets the refusal
     *    say what the document actually was.
     *
     * What is NOT in here is the stock. An entity has no entity manager and no movement service, so
     * receive() records the receipt and the CONTROLLER dispatches SalesReturnReceivedEvent after
     * flushing — see the class docblock, and CreditMemoIssuedEvent for the same seam stated at
     * length.
     */

    /**
     * Requested → Authorised. We agree to take the goods back.
     *
     * This is the transition that makes the document useful to anybody outside the building: from
     * here the number goes to the customer and gets written on the box.
     *
     * A return with no lines is refused. An authorisation is a promise about WHAT may be sent, and
     * one that names nothing authorises everything — the parcel then arrives and the receiving clerk
     * has nothing to check it against, which is the state this document exists to end.
     *
     * Deliberately not idempotent, for SalesOrder::approve()'s reason: authorising a return that is
     * already authorised is a mistake in the caller, and swallowing it hides a double-submitted form
     * rather than fixing one.
     */
    public function authorise(?\DateTimeImmutable $at = null): self
    {
        if ($this->status !== SalesReturnStatus::Requested) {
            throw new \DomainException(sprintf(
                'Return %s is %s; only a requested return can be authorised.',
                $this->documentLabel(),
                $this->status->value,
            ));
        }

        if (QuantityScale::compare($this->totalUnits(), 0) <= 0) {
            throw new \DomainException(sprintf(
                'Return %s authorises nothing: it has no lines with a quantity on them. '
                . 'Add what the customer may send back before authorising it.',
                $this->documentLabel(),
            ));
        }

        $this->status = SalesReturnStatus::Authorised;
        $this->authorisedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /**
     * Authorised → Received. The goods are physically in the building.
     *
     * The warehouse is a PARAMETER and not a setter called beforehand, because "the goods arrived"
     * and "they arrived here" are one fact. A separate setWarehouse() would let a caller record a
     * receipt with no destination, or change the destination of a receipt already recorded, and
     * neither is a thing that can happen to a parcel.
     *
     * ## What this does NOT do
     *
     * It does not touch `received_quantity`, and nothing downstream of it does either — see the
     * class docblock for the arithmetic. It does not credit anything: the money is a credit note
     * raised separately, possibly in the next period, which is the entire reason these are two
     * documents. And it does not make one unit sellable. Every unit lands in `returned`, which sums
     * into `quarantine_quantity`, and stays there until a person rules on it.
     *
     * ## Receiving is allowed only from Authorised
     *
     * "Goods arrived against a return nobody authorised" is listed in #596 as a case that must be
     * EXPRESSIBLE, and it is: raise the RMA after the fact, authorise it, receive it. That is a
     * two-click path with a document at the end of it. Letting receive() jump from Requested would
     * be the same act with no record that anyone ever agreed to it, which is the audit hole rather
     * than the fix for one.
     */
    public function receive(Warehouse $warehouse, ?\DateTimeImmutable $at = null): self
    {
        if ($this->status !== SalesReturnStatus::Authorised) {
            throw new \DomainException(sprintf(
                'Return %s is %s; only an authorised return can be received. '
                . 'Goods that arrived against no authorisation still need one — raise it, authorise it, then receive.',
                $this->documentLabel(),
                $this->status->value,
            ));
        }

        if (QuantityScale::compare($this->totalUnits(), 0) <= 0) {
            throw new \DomainException(sprintf(
                'Return %s has no units on it. There is nothing to receive.',
                $this->documentLabel(),
            ));
        }

        $this->status = SalesReturnStatus::Received;
        $this->receivedAt = $at ?? new \DateTimeImmutable();
        $this->warehouse = $warehouse;

        return $this;
    }

    /**
     * Requested, Authorised or Received → Declined. Terminal.
     *
     * Reachable from Authorised AND Received, which is the case #596 names: goods arriving is not
     * agreement that they are creditable. The box is opened, it is the wrong item or plainly
     * customer damage, and no credit note is going to be raised.
     *
     * ## Requested is in that list on purpose, and was not always
     *
     * This used to start at Authorised. `close()` starts at Received, `receive()` at Authorised, and
     * SalesReturnController has no delete route — so a Requested return had exactly one exit:
     * authorise it, then decline it. Getting rid of an RMA raised against the wrong customer meant
     * first asserting an agreement with that customer that never happened.
     *
     * The sharp form was the EMPTY return. `authorise()` refuses a return with no units on it, and
     * `getUnits()` rounds, so a line typed as 0.40 survives the editor's "at least one line" check
     * and still sums to nothing. Such a document could not be authorised, therefore could not be
     * declined, therefore could not be closed, and could not be deleted. It was permanent.
     *
     * The buy side had the identical defect and was fixed first; VendorReturn::decline() and
     * VendorReturnStatus carry the same argument from that end. `SalesReturnStatus` is where the
     * reasoning for reusing Declined rather than adding a second terminal state lives — it argued
     * against a "Cancelled" case before this change existed, and that argument is what this follows.
     *
     * The from-state is recorded in $notes below, so a draft abandoned before anybody agreed and a
     * parcel refused on the dock stay legible as the different events they are: "Declined (was
     * Requested)" against "Declined (was Received)".
     *
     * ## What is still refused
     *
     * A terminal return. Closed and Declined are final, and re-declining one would overwrite the
     * history of a matter already settled. `authorise()`, `receive()` and `close()` are untouched:
     * stock still only moves at receipt, and receipt still only comes from Authorised, so no draft
     * can reach the ledger through here.
     *
     * ## It deliberately moves no stock, and invents no disposition
     *
     * A declined return that was already received leaves its units sitting in `returned` — present,
     * counted in `quarantine_quantity`, not sellable, and not the customer's to have back for free.
     * That is an uncomfortable state and it is the correct one: what happens to those goods is a
     * commercial decision (ship them back at the customer's cost, scrap them, sell them as B-stock)
     * that this document cannot make and must not fake.
     *
     * Auto-scrapping them would destroy goods on a status change. Auto-restocking them would put
     * unexamined goods on the shelf. Writing a disposition on the lines would claim somebody had
     * inspected them. So this does none of it, records the refusal, and leaves the units visible —
     * SalesReturnController surfaces the stranded quantity on the detail screen precisely so they
     * are not merely correct-and-invisible.
     */
    public function decline(?string $reason = null, ?\DateTimeImmutable $at = null): self
    {
        if ($this->status->isTerminal()) {
            throw new \DomainException(sprintf(
                'Return %s is already %s and cannot be declined.',
                $this->documentLabel(),
                $this->status->value,
            ));
        }

        $previous = $this->status;
        $this->status = SalesReturnStatus::Declined;

        $reason = trim((string) $reason);
        $note = $reason !== ''
            ? sprintf('[%s] Declined (was %s): %s', ($at ?? new \DateTimeImmutable())->format('Y-m-d'), $previous->value, $reason)
            : sprintf('[%s] Declined (was %s).', ($at ?? new \DateTimeImmutable())->format('Y-m-d'), $previous->value);

        // Appended, never replaced. The note explaining why the return was authorised in the first
        // place is the context somebody reading the refusal a month later needs most.
        $this->notes = trim((string) $this->notes) === '' ? $note : $this->notes . "\n" . $note;

        return $this;
    }

    /**
     * Received → Closed. Terminal. The matter is finished.
     *
     * Only from Received, on purpose. Closing a return whose goods never arrived would be saying
     * "this is dealt with" about a parcel that is either still in transit or was never sent, and the
     * status that means "we are not doing this" already exists and is Declined.
     *
     * Closing does NOT require a credit note to exist and does not check for one. A return closed
     * with no credit is an ordinary outcome — an exchange, a warranty replacement shipped on a new
     * order, a goodwill write-off already handled elsewhere — and coupling the two documents'
     * lifecycles would mean this one could not be tidied up without inventing money.
     *
     * It does not move stock either. Units received against a closed return are still in `returned`
     * unless somebody has ruled on them, and closing the paperwork is not that ruling.
     */
    public function close(): self
    {
        if ($this->status !== SalesReturnStatus::Received) {
            throw new \DomainException(sprintf(
                'Return %s is %s; only a received return can be closed. '
                . 'A return that is not going to happen is declined, not closed.',
                $this->documentLabel(),
                $this->status->value,
            ));
        }

        $this->status = SalesReturnStatus::Closed;

        return $this;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Questions the screens and the subscriber ask
     * ------------------------------------------------------------------------------------------
     */

    /** True while lines may still be added, edited or removed. */
    public function isDraft(): bool
    {
        return $this->status->allowsLineEditing();
    }

    /** Have the goods physically arrived? True for Received, and stays true once declined or closed. */
    public function hasBeenReceived(): bool
    {
        return $this->receivedAt instanceof \DateTimeImmutable;
    }

    /**
     * Units received and then refused — stock in the building that nobody has ruled on and no credit
     * is coming for.
     *
     * Answered from the receipt rather than by counting `inventory_detail` rows, and the difference
     * matters: those rows are the LEDGER's answer and they move as soon as anybody acts on the
     * goods, whereas this is the DOCUMENT's answer to "how much did we take in and then refuse",
     * which never changes. The detail screen shows this figure to send somebody looking, and the
     * stock screen is where they find what is actually left.
     */
    public function strandedUnits(): string
    {
        if ($this->status !== SalesReturnStatus::Declined || !$this->hasBeenReceived()) {
            return QuantityScale::canonical(0);
        }

        return $this->totalUnits();
    }

    /** Every unit named on the document. What receive() hands the ledger. */
    public function totalUnits(): string
    {
        $units = QuantityScale::canonical(0);
        foreach ($this->lines as $line) {
            $units = QuantityScale::add($units, $line->getUnits());
        }

        return $units;
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

    /** @return Collection<int, SalesReturnLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(SalesReturnLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setSalesReturn($this);
        }

        return $this;
    }

    public function removeLine(SalesReturnLine $line): self
    {
        $this->lines->removeElement($line);

        return $this;
    }
}
