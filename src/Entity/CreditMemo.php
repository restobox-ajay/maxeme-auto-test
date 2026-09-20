<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Document\CommercialDocument;
use App\Contract\Status\HasStatus;
use App\Enum\CreditMemoStatus;
use App\Service\DocumentActor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A credit note: the fifth AbstractSalesDocument subtype (#586), beside Cart, Estimate, SalesOrder
 * and Invoice.
 *
 * ## It is a document with a balance, not a negative invoice
 *
 * This is the single most important thing about the class and the reason it exists rather than a
 * `-1` multiplier somewhere. Zoho Books' shape, followed deliberately: a credit note has its own
 * number, its own series, its own status, and a BALANCE that is spent — applied to invoices,
 * refunded to the customer, or left sitting as customer credit.
 *
 * The negative-invoice design was considered and is wrong here for reasons that are structural, not
 * stylistic:
 *
 *  - `InvoiceInventoryBucketResolver` maps a status straight onto a bucket (Pending →
 *    `pending_quantity`, Processing/Completed → `approved_quantity`). A negative invoice would push
 *    negative numbers into those columns, and `ProductInventory::adjustPending()` clamps at zero, so
 *    the negative would be silently swallowed on some rows and not others.
 *  - Every quantity aggregate in the app — `SalesOrder::uninvoicedQuantityFor()`, the sales-hold
 *    split, `SalesOrderBackorderReservationSubject`'s arithmetic — assumes positive quantities. A
 *    negative invoice would read as un-invoicing goods that were shipped.
 *  - A credit RELEASES a hold. That is a different operation from holding a negative amount, and it
 *    is expressed here as a subtraction inside the invoice's own target (see
 *    InvoiceReservationSubject::stockedQuantityFor()), not as a second document holding -4.
 *
 * ## Balance is DERIVED
 *
 *     balance = total − SUM(applications.amount) − SUM(refunds.amount)
 *
 * Not stored, for the same reason no other cached figure in this app is trusted without a
 * recomputation beside it: a second copy of a number is a second answer waiting to disagree with
 * the rows. What IS stored is $status, and it is written in exactly one place — settle() — which
 * every balance-moving method calls on its way out.
 *
 * ## $invoice is PROVENANCE. credit_memo_application is where the money lands
 *
 * They are genuinely different things and conflating them is the mistake this design is shaped to
 * avoid. "Raised from INV-123" says which document the goods and figures were copied off. Where the
 * balance is SPENT is a many-to-many with an amount, because one note pays down three invoices and
 * one invoice takes credit from two notes. A note raised from one invoice can be applied to another
 * entirely, and nothing here objects — see applyTo().
 *
 * $invoice is nullable, which is not an oversight either: goodwill credits, pricing corrections and
 * credits raised before anyone has decided where they land are all standalone notes, created Open
 * with their whole total as balance.
 *
 * ## What it does to inventory
 *
 * Two separate things, and neither of them is an adjustment:
 *
 *  - the invoice's hold nets out, always, through this note's LINES. Crediting 4 units of an invoice
 *    line drops `invoice_inventory_reservation.quantity` by 4 and `pending`/`approved` follow through
 *    the existing reconciler. No new reservation entity — #548 established the precedent when
 *    backorder needed the same treatment, and InventoryReservationSubject's docblock explains at
 *    length why a second copy of that diffing logic is how a bucket comes to disagree with its own
 *    ledger.
 *  - $restock says whether the GOODS came back. False is financial only and stock never moves. True
 *    produces `returned` inventory-detail rows, which sum into `quarantine_quantity` and are
 *    explicitly NOT sellable until somebody inspects and releases them — because a customer
 *    returning faulty goods is not the same as one returning sellable goods, and the document
 *    cannot know which it was.
 *
 * A standalone note with restock = true still puts stock back. The `returned` rows do not depend on
 * an invoice existing; what does not happen is any net-out of `invoice_inventory_reservation`,
 * because no invoice is holding those units and there is nothing to go looking for.
 *
 * ## $salesReturn, and the one thing this document is no longer allowed to do (#596)
 *
 * `restock` is NOT removed, and #596 is explicit that it must not be. A standalone credit with no
 * RMA behind it still needs it: goodwill credits, and credits where the goods are not worth the
 * freight to ship back. Everything in the paragraph above is still true of a note with no
 * $salesReturn on it, and CreditMemoRestockTest still asserts it.
 *
 * What #596 changes is OWNERSHIP once an RMA exists. A SalesReturn is the document that authorises
 * goods coming back and records that they arrived, and its receive() is what writes the `returned`
 * rows. So a note pointing at one must not ALSO restock, and setRestock() throws rather than
 * quietly ignoring the flag — see assertRestockAndReturnAreExclusive(), which is the whole
 * enforcement and is deliberately in one place so that removing it is a single visible edit.
 *
 * A silent skip was considered and rejected. If the note simply stopped restocking, an operator who
 * ticked the box would get a document that says the goods came back on it, a ledger that credits
 * them to a different document, and nothing anywhere saying the two disagree. If both were allowed
 * to fire, the same physical units enter the ledger twice and `quarantine_quantity` reads double,
 * with no row saying which half was the mistake. A refusal at the setter is the only one of the
 * three outcomes an admin can act on.
 *
 * ## No timeline table
 *
 * Invoice, SalesOrder and Estimate each carry a `*_log` table, and this deliberately does not. #586
 * enumerates four tables and this is not one of them, and the history that matters here is already
 * recorded twice over: `credit_memo_application.applied_at` and `credit_memo_refund` are themselves
 * the record of every event that moves the balance, and AuditLogSubscriber picks up status changes
 * on this entity with the acting user attached, because — unlike InvoiceLog and its two siblings —
 * nothing excludes it. If this document ever grows notes an admin types by hand, that is when it
 * needs a log of its own.
 */
#[ORM\Entity]
#[ORM\Table(name: 'credit_memo')]
#[ORM\AttributeOverrides([
    // The base widened this for Cart. A credit note is always dated — it is a document raised on a
    // day — so it narrows the column back, exactly as SalesOrder, Estimate and Invoice do.
    new ORM\AttributeOverride(name: 'documentDate', column: new ORM\Column(length: 10)),
])]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(
        name: 'company',
        joinColumns: [new ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false)],
    ),
])]
class CreditMemo extends AbstractSalesDocument implements CommercialDocument, HasStatus
{
    /**
     * Which vocabulary governs a credit note. Read through late static binding by the shared bodies
     * on `AbstractSalesDocument`.
     */
    public const STATUS_VOCABULARY = 'credit_memo';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $documentNumber = '';

    #[ORM\Column(length: 20)]
    private string $status = 'Draft';

    /**
     * The configured classification, as a bare id rather than an association.
     *
     * `credit_memo_type` has been a configured table since #539 (`/admin/config/credit-memo-types`)
     * and has NO Doctrine entity: it is one of the three raw-SQL config tables driven entirely by
     * Admin\ConfigController's CONFIG_TABLES registry, alongside `payment_term` and
     * `shipping_zone`. Both test suites build their schema from entity metadata, so that table does
     * not exist in either of them — a ManyToOne here would produce a foreign key to a table the test
     * schema has never contained, and RawSqlTablesSurviveTheChainTest exists precisely because that
     * mismatch has bitten this codebase before.
     *
     * So it is an integer, resolved for display by the controller with the same raw query the config
     * screen uses, and the migration deliberately declares no FK constraint on it for the same
     * reason. Nullable because a note need not be classified, and because #586 is explicit that
     * `credit_memo_type` stays EMPTY until somebody configures it — nothing here backfills a row to
     * point at.
     */
    #[ORM\Column(name: 'credit_memo_type_id', nullable: true)]
    private ?int $creditMemoTypeId = null;

    /**
     * The invoice this note was raised FROM, if any. Provenance, not allocation — see the class
     * docblock.
     *
     * SET NULL rather than CASCADE, for the reason InvoiceLine::$salesOrderLine is: losing the
     * attribution must never delete the record of a credit that was actually given. In practice an
     * invoice is never deleted in this app at all.
     */
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    /**
     * The RMA this note credits, when the goods came back on one (#596).
     *
     * Nullable, and it is the null case that carries the meaning: a note with no sales return is
     * exactly the #586 credit note, unchanged — it may restock, and CreditMemoRestockSubscriber
     * still does the work. A note WITH one has handed that job to the return's receipt, and
     * setRestock() refuses to take it back.
     *
     * SET NULL rather than CASCADE, for $invoice's reason: losing the attribution must never delete
     * the record of a credit that was actually given.
     *
     * This is provenance, exactly like $invoice, and NOT an allocation. Where the balance lands is
     * still credit_memo_application. Nor is it a quantity link: an RMA that took back four units may
     * be credited for three, or for none, or at a different price entirely, because a restocking fee
     * and a partial credit are ordinary commercial outcomes and neither document derives figures
     * from the other.
     */
    #[ORM\ManyToOne(targetEntity: SalesReturn::class)]
    #[ORM\JoinColumn(name: 'sales_return_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SalesReturn $salesReturn = null;

    /** Free text: "damaged in transit", "wrong item shipped", "goodwill". Shown on the document. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    /**
     * Did the goods come back?
     *
     * A choice ON THE NOTE rather than a rule derived from anything, because nothing else knows. A
     * customer returning faulty goods and a customer returning sellable goods produce the same
     * credit and different physical outcomes, and only the person raising the note can say which
     * happened. False credits the money and leaves stock alone; true additionally writes `returned`
     * detail rows through CreditMemoRestockSubscriber.
     *
     * Since #596 it may only be true while $salesReturn is null. See setRestock().
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $restock = false;

    /**
     * Ordered explicitly, for the reason Estimate::$lines and Invoice::$lines are: a collection with
     * no ORDER BY comes back in whatever order the database chose.
     *
     * @var Collection<int, CreditMemoLine>
     */
    #[ORM\OneToMany(targetEntity: CreditMemoLine::class, mappedBy: 'creditMemo', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, CreditMemoApplication> */
    #[ORM\OneToMany(targetEntity: CreditMemoApplication::class, mappedBy: 'creditMemo', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['appliedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $applications;

    /** @var Collection<int, CreditMemoRefund> */
    #[ORM\OneToMany(targetEntity: CreditMemoRefund::class, mappedBy: 'creditMemo', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['refundedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $refunds;

    /** @var Collection<int, CreditMemoAddress> */
    #[ORM\OneToMany(targetEntity: CreditMemoAddress::class, mappedBy: 'creditMemo', cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    /** A credit note always credits someone, so callers keep the non-null contract Invoice gives them. */
    public function getCompany(): Company { return $this->company; }

    /** The parameter stays nullable — PHP forbids narrowing one — so the narrowing is the throw. */
    public function setCompany(?Company $company): static
    {
        if (!$company instanceof Company) {
            throw new \InvalidArgumentException('A credit note must have a company; only a cart may be unattached.');
        }

        return parent::setCompany($company);
    }

    public function getDocumentNumber(): string { return $this->documentNumber; }
    public function setDocumentNumber(string $documentNumber): self { $this->documentNumber = $documentNumber; return $this; }

    /**
     * Narrowed to non-null for CommercialDocument, which promises a date rather than a maybe-date.
     *
     * The nullable return on the base belongs to Cart, which is not a document and does not
     * implement the contract. A credit note is raised on a day and narrows the column back to
     * NOT NULL in the AttributeOverride above; SalesDocumentDateStamp fills it at persist time.
     */
    public function getDocumentDate(): string { return (string) parent::getDocumentDate(); }

    public function getStatus(): string { return $this->status; }

    /** The enum is the frozen core contract, not the storage. Null for a value it no longer knows. */
    public function getStatusEnum(): ?CreditMemoStatus { return CreditMemoStatus::tryFrom($this->status); }

    /** `HasStatus::canEditOnStatus()`. The rule lives on `CreditMemoStatus::allowsEditing()`. */
    public function canEditOnStatus(): bool
    {
        return $this->getStatusEnum()?->allowsEditing() ?? true;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Status. There is ONE gate: setStatus(). The status-named verb is gone.
     * ------------------------------------------------------------------------------------------
     *
     * `void()` used to live here. It is gone, and its two refusals are below in
     * {@see self::assertStatusChangeAllowed()}. Owner rulings R1 and R2, `STATUS-SEAM-HANDOFF.md`:
     * the criterion is the NAME, and `Void` is a status in this note's vocabulary.
     *
     * `issue()` is not, so it stays public — it now asks the gate for the move instead of writing
     * the column. `settle()` is not either, and it was never a verb: it is the SECOND door, the one
     * for a caller that names no target, and it is now `applyDerivedStatus()`.
     *
     * Nothing about what a credit note does changed. A note with applications or refunds against it
     * still cannot be voided; only a draft can be issued; a zero-total note still has nothing to
     * credit; the balance still decides Open versus Closed.
     */

    /**
     * The gate, made PUBLIC on this document. The shared body is on `AbstractSalesDocument`.
     */
    public function setStatus(string $status, DocumentActor $actor, ?string $comment = null): string
    {
        return parent::setStatus($status, $actor, $comment);
    }

    /**
     * Everything `issue()` and `void()` refused, in the one place a caller can reach.
     *
     * Read as four rules, and the ORDER inside each pair is load-bearing because both members could
     * be the first thing a caller trips over:
     *
     *  1. **Only a draft can be issued, and then only if there is something to credit.** Both were
     *     `issue()`'s, in this order, so an Open note with a zero total is still told it is Open
     *     rather than told it is empty. They are here rather than left in `issue()` because the gate
     *     is now another way to reach the write: `setStatus('Open', ...)` IS issuing, and a guard a
     *     caller can walk around by picking the other door is not a guard.
     *
     *  2. **A note is never voided twice, and never once anything has been spent.** Both were
     *     `void()`'s, in this order, word for word. The second is the same rule
     *     `Invoice::assertStatusChangeAllowed()` applies to an invoice holding payments, for the
     *     same reason: an allocation that has happened is a real accounting record. Voiding around
     *     it would leave the other invoice still believing it had received credit, with the note
     *     that gave it saying it holds nothing. An admin who genuinely means to unwind it withdraws
     *     the applications and deletes the refunds first, each a deliberate act with a row of its
     *     own.
     *
     *     The already-void refusal is about the SELF-MOVE, which is why the shared gate runs this
     *     method before its no-op short-circuit rather than after. Folding `void()` in without that
     *     ordering would have turned a loud mistake into a silent nothing.
     *
     *  3. **Closed is not set by hand.** It is derived — `CoreStatusVocabularyProvider` flags it so
     *     — and {@see self::deriveStatus()} is the only thing that reaches it. `settle()` writes it
     *     through the derived door, which does not consult this method, so refusing here closes the
     *     gate on it without touching the deriver.
     *
     *  4. **Draft is reachable from nowhere.** Not a new refusal but the absence of an old
     *     permission: nothing ever wrote Draft, so nothing could ever return a note to it.
     *
     * Between them these seal Void without a branch of its own: an already-void note is refused Open
     * by rule 1, Void by rule 2, and Closed and Draft by rules 3 and 4. Every refusal is a plain
     * `\DomainException` carrying the sentence the verb wrote, byte for byte — `CreditMemoBalanceTest`
     * pins two of them, and R5 is that a reorganisation changes no result.
     */
    protected function assertStatusChangeAllowed(string $from, string $to): void
    {
        if ($to === 'Open') {
            if ($from !== 'Draft') {
                throw new \DomainException(sprintf(
                    'Credit note %s is %s; only a draft can be issued.',
                    $this->documentLabel(),
                    $from,
                ));
            }

            if (self::cents($this->total) <= 0) {
                throw new \DomainException(sprintf(
                    'Credit note %s has a total of $%s. There is nothing to credit.',
                    $this->documentLabel(),
                    (string) $this->total,
                ));
            }
        }

        if ($to === 'Void') {
            if ($from === 'Void') {
                throw new \DomainException(sprintf('Credit note %s is already void.', $this->documentLabel()));
            }

            $spent = self::cents($this->getAmountApplied()) + self::cents($this->getAmountRefunded());
            if ($spent > 0) {
                throw new \DomainException(sprintf(
                    'Credit note %s has $%s applied and $%s refunded against it and cannot be voided.'
                    . ' Withdraw its applications and delete its refunds first.',
                    $this->documentLabel(),
                    $this->getAmountApplied(),
                    $this->getAmountRefunded(),
                ));
            }
        }

        if ($to === 'Closed') {
            throw new \DomainException(sprintf(
                'Credit note %s cannot be set to Closed by hand. Closed is what its balance says once'
                . ' the credit is spent.',
                $this->documentLabel(),
            ));
        }

        if ($to === 'Draft') {
            throw new \DomainException(sprintf(
                'Credit note %s cannot be put back to Draft. A note that has been issued is never'
                . ' unissued; void it instead.',
                $this->documentLabel(),
            ));
        }
    }

    /**
     * What this note's own balance says it is. THE SECOND DOOR — see {@see self::settle()}.
     *
     * This is `settle()`'s body, moved, and it is a real derivation rather than a stub: the note is
     * a document with a BALANCE, and the balance decides which of Open and Closed it is. Both are
     * flagged `derived` in the vocabulary for exactly that reason, and `applyDerivedStatus()` writes
     * only statuses that are.
     *
     * **Draft and Void answer null**, which is the same refusal `settle()` made with an early
     * return and matters just as much: a voided note holds nothing, so its balance equals its total,
     * and a naive "balance > 0 means Open" would resurrect it. A draft is inert and is issued by
     * somebody pressing something, not by arithmetic.
     */
    public function deriveStatus(): ?string
    {
        if ($this->status !== 'Open' && $this->status !== 'Closed') {
            return null;
        }

        return self::cents($this->getBalance()) <= 0
            ? 'Closed'
            : 'Open';
    }

    /**
     * The note's own status column, for the shared bodies on `AbstractSalesDocument`.
     *
     * Protected, so neither is a second door around `setStatus()`. The column is a plain string, so
     * both are a straight pass-through. `ShippedVocabulariesMatchTheirEnumsTest` still pins slug and
     * case against each other in both directions, and the gate's typo guard rejects an unknown
     * target before anything is written.
     */
    protected function readStatus(): string
    {
        return $this->status;
    }

    protected function writeStatus(string $status): void
    {
        $this->status = $status;
    }

    /**
     * No `newLogEntry()` override: the inherited null is correct.
     *
     * This document deliberately carries no `*_log` table — see the class docblock — so `setStatus()`
     * writes the column and no timeline row, which is exactly what `issue()` and `settle()` did
     * before. `AuditLogSubscriber` picks the status change up instead, with the acting user attached.
     */
    protected function statusDocumentLabel(): string
    {
        return 'Credit note ' . $this->documentLabel();
    }

    public function getCreditMemoTypeId(): ?int { return $this->creditMemoTypeId; }
    public function setCreditMemoTypeId(?int $creditMemoTypeId): self { $this->creditMemoTypeId = $creditMemoTypeId; return $this; }

    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function setInvoice(?Invoice $invoice): self { $this->invoice = $invoice; return $this; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): self { $this->reason = $reason; return $this; }

    public function getSalesReturn(): ?SalesReturn { return $this->salesReturn; }

    /**
     * Attach the RMA these goods came back on — and refuse if this note has already claimed to put
     * them back itself.
     *
     * Guarded on this side as well as on setRestock() because the two orderings are both real. A
     * screen that offers "raise a credit note from this return" sets the return first; a screen that
     * edits an existing restocking note and links it to a return afterwards sets it second. One
     * assertion, called from both, so neither ordering can slip past.
     */
    public function setSalesReturn(?SalesReturn $salesReturn): self
    {
        $this->assertRestockAndReturnAreExclusive($this->restock, $salesReturn);
        $this->salesReturn = $salesReturn;

        return $this;
    }

    public function isRestock(): bool { return $this->restock; }

    /**
     * Say whether the goods came back on THIS note — refused when an RMA already says they did.
     *
     * The refusal is the whole of #596's behavioural change to #586, and it is a throw rather than a
     * silent `false` for the reason the class docblock gives at length: a flag that reads as ticked
     * and does nothing is a lie the operator cannot see, whereas an error message names the document
     * that already owns the goods.
     */
    public function setRestock(bool $restock): self
    {
        $this->assertRestockAndReturnAreExclusive($restock, $this->salesReturn);
        $this->restock = $restock;

        return $this;
    }

    /**
     * The units may come back exactly once, and exactly one document may be the one that brought
     * them.
     *
     * **This method is the enforcement.** Deleting its body is the single edit that reintroduces the
     * double-restock, which is why it is one method called from two setters rather than two copies
     * of an `if`: a second copy elsewhere would let half the guard survive a deletion, and the test
     * that only checks one ordering would stay green while the bug was back.
     *
     * Two tests fail when it goes, and they fail for different reasons on purpose —
     * SalesReturnTransitionsTest::testACreditNoteCarryingAReturnRefusesToRestockWhicheverOrderTheyAreSet
     * proves the refusal itself in both orderings, and
     * SalesReturnReceiptTest::testACreditNoteCarryingAReturnRefusesToRestockSoTheUnitsDoNotComeBackTwice
     * proves what the refusal is FOR by counting the `returned` rows afterwards. Removing this body
     * turns the second one's count from 2 into 4, which is the double-restock stated as a number.
     */
    private function assertRestockAndReturnAreExclusive(bool $restock, ?SalesReturn $salesReturn): void
    {
        if (!$restock || !$salesReturn instanceof SalesReturn) {
            return;
        }

        throw new \DomainException(sprintf(
            'Credit note %s credits sales return %s, and that return\'s receipt is what puts the goods back. '
            . 'A note carrying a return may not also restock: the same units would enter the ledger twice and '
            . 'quarantine would read double. Untick "the goods came back" on this note, or detach the return '
            . 'and let the note own the stock instead.',
            $this->documentLabel(),
            $salesReturn->getDocumentNumber() !== '' ? $salesReturn->getDocumentNumber() : '#' . (string) $salesReturn->getId(),
        ));
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Transitions and allocations
     * ------------------------------------------------------------------------------------------
     *
     * This document is ON the seam. The gate is `setStatus()`, declared above; `void()` is deleted
     * and its two refusals are in `assertStatusChangeAllowed()`.
     *
     * A note said here for a while that the class could not join, because a public setter would let
     * a caller reach the write around `void()`'s guard. The premise was right and the conclusion was
     * backwards — the guard moves onto the gate — and the owner said so:
     *
     * > *"Invoice why is it off seam. it gets to keep those non-status name verbs but those verbs
     * > must call setStatus ... I don't think there's anything 'off seam'."*
     *
     * What survives of the old note is the part that was never about the seam: `settle()`. Every
     * method below that can move the balance ends by calling it, which is what makes "Open means
     * there is a balance" true by construction rather than by everybody remembering. It is now the
     * derived door — see `deriveStatus()`.
     */

    /**
     * Draft → Open. Issuing is what makes a credit note real: it starts crediting.
     *
     * The moment this returns, three things become true that were not true a line earlier — the
     * balance is spendable, the credited quantity starts netting out of the invoice's inventory
     * hold (InvoiceLine::getCreditedUnits() skips drafts), and a restock is due. The restock itself
     * is not done here: an entity has no entity manager and no movement service, so the controller
     * dispatches CreditMemoIssuedEvent after flushing and InventoryDepthBundle does the work.
     *
     * A zero-total note is refused. Nothing downstream can do anything with a balance of $0.00, and
     * a note issued by mistake with no lines on it is a document that will sit Open forever looking
     * like available credit.
     */
    public function issue(): self
    {
        // Both of its guards are on the gate now, in this order — see assertStatusChangeAllowed().
        // `issue()` is not a status name in this vocabulary, so it stays public (owner ruling R2);
        // what changed is that it asks the gate for the move instead of writing the column.
        //
        // The actor is System because this document has no timeline to sign. `newLogEntry()` is the
        // inherited null — a credit note carries no `*_log` table and AuditLogSubscriber picks its
        // status changes up instead — so the actor is accepted by the gate and then discarded.
        // Requiring one at every call site would be ceremony that records nothing; the day this
        // document grows a log of its own is the day that argument changes.
        $this->setStatus('Open', DocumentActor::system());

        return $this;
    }

    /**
     * Spend part or all of this note's balance against one invoice.
     *
     * Deliberately allows an invoice this note was NOT raised from: $invoice on the header is
     * provenance, and a customer's credit is the customer's, not one document's. What it does NOT
     * allow is crediting a different customer — the money belongs to a company and moving it between
     * companies is not an allocation, it is a gift.
     *
     * Nor a cancelled invoice: that invoice is owed nothing and never will be, so credit applied to
     * it would sit against a document that can never consume it, exactly as Invoice::recordPayment()
     * refuses a payment on the same grounds.
     *
     * Repeated applications to the same invoice are allowed and are separate rows. Two allocations
     * on different days are two facts, and collapsing them into one row would lose the second date.
     */
    public function applyTo(Invoice $invoice, string $amount, ?\DateTimeImmutable $appliedAt = null): CreditMemoApplication
    {
        if ($this->status !== 'Open') {
            throw new \DomainException(sprintf(
                'Credit note %s is %s. Only an open note has a balance to apply.',
                $this->documentLabel(),
                $this->status,
            ));
        }

        if ($invoice->getCompany() !== $this->company) {
            throw new \DomainException(sprintf(
                'Credit note %s belongs to %s and invoice %s to %s. A credit cannot cross customers.',
                $this->documentLabel(),
                $this->company?->getName() ?? '(none)',
                $invoice->getDocumentNumber(),
                $invoice->getCompany()->getName(),
            ));
        }

        if ($invoice->isCancelled()) {
            throw new \DomainException(sprintf(
                'Invoice %s is cancelled; it is owed nothing and cannot take a credit.',
                $invoice->getDocumentNumber(),
            ));
        }

        $this->assertPositive($amount, 'An application');

        if (self::cents($amount) > self::cents($this->getBalance())) {
            throw new \DomainException(sprintf(
                'Credit note %s has $%s left. It cannot apply $%s.',
                $this->documentLabel(),
                $this->getBalance(),
                $amount,
            ));
        }

        // #770: the note's own balance caps how much it has LEFT to give, but says nothing about
        // how much the invoice has left to RECEIVE — an invoice already paid down, or credited by an
        // earlier note, can take less than this note's own remaining balance. Both caps are real and
        // neither implies the other.
        if (self::cents($amount) > self::cents($invoice->getBalance())) {
            throw new \DomainException(sprintf(
                'Invoice %s has a balance of $%s. It cannot take a $%s credit.',
                $invoice->getDocumentNumber(),
                $invoice->getBalance(),
                $amount,
            ));
        }

        $application = (new CreditMemoApplication())
            ->setCreditMemo($this)
            ->setInvoice($invoice)
            ->setAmount(self::money(self::cents($amount)));

        if ($appliedAt instanceof \DateTimeImmutable) {
            $application->setAppliedAt($appliedAt);
        }

        $this->applications->add($application);
        // Both sides, in memory, for the same reason Invoice::applyPayment() adds to its own
        // $applications after building the claim: Doctrine only syncs an inverse collection like
        // Invoice::$creditApplications by re-querying, and $invoice->getBalance() has to be right
        // for the rest of THIS request without one.
        $invoice->getCreditApplications()->add($application);
        $this->settle();

        return $application;
    }

    /**
     * Take an allocation back off the note — applied to the wrong invoice, or for the wrong amount.
     *
     * The row is deleted rather than flagged, exactly as Invoice::withdrawApplication() deletes a payment claim:
     * $applications is orphanRemoval, and an allocation that did not happen is not an allocation of
     * zero. The audit log carries "there used to be $40 here".
     */
    public function withdrawApplication(CreditMemoApplication $application): self
    {
        if (!$this->applications->contains($application)) {
            throw new \DomainException(sprintf(
                'That application does not belong to credit note %s.',
                $this->documentLabel(),
            ));
        }

        $this->applications->removeElement($application);
        $application->getInvoice()->getCreditApplications()->removeElement($application);

        return $this->settle();
    }

    /**
     * Money paid back to the customer.
     *
     * Capped at the remaining balance for the same reason an application is: a note cannot give away
     * more than it holds, and a refund that overdrew it would leave a negative balance that reads as
     * Closed while the customer is owed money nobody has recorded.
     */
    public function recordRefund(CreditMemoRefund $refund): self
    {
        if ($this->status !== 'Open') {
            throw new \DomainException(sprintf(
                'Credit note %s is %s. Only an open note has a balance to refund.',
                $this->documentLabel(),
                $this->status,
            ));
        }

        $this->assertPositive($refund->getAmount(), 'A refund');

        if (self::cents($refund->getAmount()) > self::cents($this->getBalance())) {
            throw new \DomainException(sprintf(
                'Credit note %s has $%s left. It cannot refund $%s.',
                $this->documentLabel(),
                $this->getBalance(),
                $refund->getAmount(),
            ));
        }

        $refund->setCreditMemo($this);
        if (!$this->refunds->contains($refund)) {
            $this->refunds->add($refund);
        }

        return $this->settle();
    }

    /** A refund taken back off the note — recorded twice, or against the wrong document. */
    public function voidRefund(CreditMemoRefund $refund): self
    {
        if (!$this->refunds->contains($refund)) {
            throw new \DomainException(sprintf(
                'That refund does not belong to credit note %s.',
                $this->documentLabel(),
            ));
        }

        $this->refunds->removeElement($refund);

        return $this->settle();
    }

    /**
     * The balance moved; recompute what that makes this note. THE SECOND DOOR.
     *
     * This method is what the derived door is for, and it predates the seam by a long way: its four
     * callers — `applyTo()`, `withdrawApplication()`, `recordRefund()`, `voidRefund()` — name no
     * target and want no answer. Something happened to the balance and the note works out what it
     * now is. That is exactly the split owner ruling R3 draws:
     *
     * > *"It's after some stuff happened and we want to know if status changes (but the caller
     * > won't need to know the rules or the resulting end status) - and don't need to."*
     *
     * So the computation moved to {@see self::deriveStatus()} and this hands it to
     * {@see AbstractSalesDocument::applyDerivedStatus()}, which re-checks the target is one the
     * vocabulary flags `derived` — `Open` and `Closed` both are, and `CoreStatusVocabularyProvider`
     * says why: *"a credit note is a document with a BALANCE, and the balance decides which of the
     * two it is."* A no-op is silent, which is what it always was.
     *
     * The actor is System for the reason `issue()` gives: this document has no timeline, so
     * `newLogEntry()` is null and nothing is written with it. Nobody performed this anyway — the
     * application or the refund was the act, and this is its consequence.
     */
    private function settle(): self
    {
        $this->applyDerivedStatus(DocumentActor::system());

        return $this;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * The balance
     * ------------------------------------------------------------------------------------------
     */

    /** @return Collection<int, CreditMemoApplication> */
    public function getApplications(): Collection { return $this->applications; }

    /** @return Collection<int, CreditMemoRefund> */
    public function getRefunds(): Collection { return $this->refunds; }

    /** Everything this note has spent on invoices, summed in whole cents and formatted back. */
    public function getAmountApplied(): string
    {
        $cents = 0;
        foreach ($this->applications as $application) {
            $cents += self::cents($application->getAmount());
        }

        return self::money($cents);
    }

    /** Everything this note has paid back to the customer. */
    public function getAmountRefunded(): string
    {
        $cents = 0;
        foreach ($this->refunds as $refund) {
            $cents += self::cents($refund->getAmount());
        }

        return self::money($cents);
    }

    /**
     * What is still available to spend.
     *
     *     balance = total − applied − refunded
     *
     * A voided note is reported at $0.00 whatever its rows say, because it holds nothing: reporting
     * its total would put it in every "available customer credit" figure in the app.
     */
    public function getBalance(): string
    {
        if ($this->status === 'Void') {
            return '0.00';
        }

        return self::money(
            self::cents($this->total)
            - self::cents($this->getAmountApplied())
            - self::cents($this->getAmountRefunded()),
        );
    }

    /**
     * Does this note credit quantity back — the question InvoiceLine::getCreditedUnits() asks of
     * every line pointing at it.
     *
     * The mirror of Invoice::countsTowardInvoicedQuantity(), stated once here rather than at each
     * caller for the same reason: a draft is entirely inert and a voided note has said the credit
     * never happened, so neither may release an invoice's inventory hold. Open and Closed both do —
     * whether the money has been SPENT has nothing to do with whether the goods were credited.
     */
    public function countsTowardCreditedQuantity(): bool
    {
        return $this->status !== 'Draft' && $this->status !== 'Void';
    }

    /** True while lines may still be edited. */
    public function isDraft(): bool
    {
        return $this->status === 'Draft';
    }

    public function isVoid(): bool
    {
        return $this->status === 'Void';
    }

    private function assertPositive(string $amount, string $what): void
    {
        if (self::cents($amount) <= 0) {
            throw new \DomainException(sprintf('%s must be for more than $0.00.', $what));
        }
    }

    /**
     * Money is compared in whole cents, never as floats: 0.10 + 0.20 is not 0.30 in binary floating
     * point, and a credit note a hundredth of a cent short is one that never reads as fully spent.
     * Lifted verbatim from Invoice, where the same rule earned itself.
     */
    private static function cents(?string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function documentLabel(): string
    {
        return $this->documentNumber !== '' ? $this->documentNumber : '#' . (string) $this->id;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Lines and addresses
     * ------------------------------------------------------------------------------------------
     */

    /** @return Collection<int, CreditMemoLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(CreditMemoLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setCreditMemo($this);
        }

        return $this;
    }

    public function removeLine(CreditMemoLine $line): self
    {
        if ($this->lines->removeElement($line)) {
            // Detached from the invoice line as well, so the credited units stop netting out of that
            // invoice's hold in the same operation that removed the row. Leaving the inverse side
            // populated is how a deleted line goes on releasing stock until the next full reload.
            $line->setInvoiceLine(null);
        }

        return $this;
    }

    /** @return Collection<int, CreditMemoAddress> */
    public function getAddresses(): Collection
    {
        return $this->memoAddresses;
    }

    protected function newAddress(string $type): AbstractDocumentAddress
    {
        $address = (new CreditMemoAddress())->setType($type);
        $address->setCreditMemo($this);
        $this->memoAddresses->add($address);

        return $address;
    }
}
