<?php

namespace App\Entity;

use App\Contract\Document\CommercialDocument;
use App\Contract\Document\DocumentLog;
use App\Contract\Status\HasStatus;
use App\Enum\SalesOrderStatus;
use App\Exception\StatusTransitionRefused;
use App\Service\DisplayNumber;
use App\Service\DocumentActor;
use App\Service\QuantityScale;
use App\Service\Uom\LineDenomination;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The base widened company and documentDate so a cart could share it. An order has both, always, so it
 * takes them back here — in the mapping, which is what keeps the database enforcing it, and for
 * company in setCompany(), because PHP is where this codebase states its invariants (see
 * Fee::assertValid()) and a null would otherwise only be caught at flush.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order')]
#[ORM\AttributeOverrides([
    new ORM\AttributeOverride(name: 'documentDate', column: new ORM\Column(length: 10)),
])]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(
        name: 'company',
        joinColumns: [new ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false)],
    ),
])]
class SalesOrder extends AbstractSalesDocument implements CommercialDocument, HasStatus
{
    /**
     * Which status vocabulary governs this document (queue item 64).
     *
     * Read through late static binding by the shared bodies on `AbstractSalesDocument`, which is how
     * one `loadStatusVocab()` serves every sales document without any of them passing a key around.
     */
    public const STATUS_VOCABULARY = 'sales_order';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $orderNumber = '';

    // Free-text on purpose — see SalesOrderStatus docblock for why this isn't enum-typed.
    #[ORM\Column(length: 32)]
    private string $status = 'Draft';

    /*
     * There is no $invoiceDate here any more (#539 stage 6).
     *
     * It was an invoice fact by name and by content, and once an order can be billed across several
     * invoices a single column on the order can only ever name one of their dates. It moved to
     * Invoice::$invoiceDate — same 'Y-m-d' string column, same reasoning about why a calendar date
     * is not a date_immutable (a midnight is an instant, and every layer that saw one was entitled
     * to convert it into the previous day west of UTC), on the entity that actually has one.
     *
     * Stage 1's backfill already carried every order's invoice date onto its invoice, COALESCEd with
     * the order's document date, so nothing was lost by dropping the column.
     */

    /**
     * How this order is to be settled, and on what terms — agreed when the order is taken.
     *
     * NOT an answer about money, and deliberately not beside a payment status any more (#539 stage
     * 4): whether anything has been paid is the invoice's question, and sales_order.payment_status
     * was dropped outright rather than left here to go quietly stale. These two are copied onto
     * every invoice raised from this order, and it is the invoice's copy that governs what is owed
     * and when it falls due.
     */
    #[ORM\Column(name: 'payment_method', length: 120, nullable: true)]
    private ?string $paymentMethod = null;

    #[ORM\Column(name: 'payment_term', length: 120, nullable: true)]
    private ?string $paymentTerm = null;

    /**
     * Optimistic-lock counter (#417). A dedicated integer, not a reuse of any existing
     * updated-at-style timestamp: Doctrine's #[ORM\Version] only accepts an int/bigint/smallint
     * or a datetime column it fully owns (ClassMetadata::setVersionMapping() rejects any other
     * type), and this entity has no such timestamp column of its own to begin with — only the
     * generic AuditLog rows (written by AuditLogSubscriber, elsewhere) carry an occurred_at.
     * Doctrine increments this by 1 and checks it automatically inside the UPDATE's WHERE clause
     * on every flush() of this entity, throwing OptimisticLockException when zero rows match —
     * i.e. when the row was written by someone else after this copy was loaded.
     *
     * This alone does not fix #417: it only catches a race INSIDE one request's own
     * load-then-flush span. The actual bug — admin B's page was rendered, unedited, from before
     * admin A's save already landed — has no such span to catch, because B's own load already
     * sees A's committed row. OrderController::edit() closes that gap itself, by round-tripping
     * this value through the edit form as a hidden field and explicitly locking the freshly
     * loaded $order against the SUBMITTED version (EntityManager::lock(), LockMode::OPTIMISTIC)
     * before applying a single field from the post. This column's automatic flush()-time check
     * is what backs that up for the (much narrower) window between that explicit check and this
     * same request's own commit.
     *
     * Composes with, rather than replaces, the request-scoped write lock #396 already added to
     * OrderController::edit() (the forced no-op `UPDATE sales_order SET id = id WHERE id = 0`):
     * that lock only serializes concurrent WRITES against each other on SQLite, which has no
     * row-level locking of its own — it does nothing to detect that one of those serialized
     * writes was computed from stale data. The version check is what detects that.
     *
     * No composite key, no inheritance hierarchy and no other #[ORM\Version] field on this
     * entity or AbstractSalesDocument — none of Doctrine's documented restrictions on a version
     * field apply here. See SalesOrderVersionMappingTest for the empirical check.
     */
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    /**
     * Ordered explicitly for the same reason Estimate::$lines is — the rebuild-every-save that
     * masked its absence here is not a guarantee, it is a coincidence.
     *
     * @var Collection<int, SalesOrderLine>
     */
    #[ORM\OneToMany(targetEntity: SalesOrderLine::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    /**
     * The invoices raised against this order (#539).
     *
     * No cascade: remove and no orphanRemoval, unlike every other collection here. An invoice is an
     * accounting document that outlives the order it bills — cancelling is how one is withdrawn,
     * and it keeps its number forever — so deleting an order must never take its invoices with it.
     *
     * Ordered oldest first, which is the order they were raised in and the order the order page
     * lists them in.
     *
     * @var Collection<int, Invoice>
     */
    #[ORM\OneToMany(targetEntity: Invoice::class, mappedBy: 'salesOrder', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $invoices;

    /**
     * This document's own frozen addresses — see AbstractDocumentAddress for why they are copies
     * rather than a foreign key into the address book.
     *
     * @var Collection<int, SalesOrderAddress>
     */
    #[ORM\OneToMany(targetEntity: SalesOrderAddress::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $orderAddresses;

    public function __construct()
    {
        parent::__construct();
        $this->lines = new ArrayCollection();
        $this->orderAddresses = new ArrayCollection();
        $this->invoices = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    /** An order always has a buyer, so callers keep the non-null contract they had before Cart. */
    public function getCompany(): Company { return $this->company; }

    /**
     * The parameter stays nullable — PHP forbids narrowing one — so the narrowing is the throw.
     */
    public function setCompany(?Company $company): static
    {
        if (!$company instanceof Company) {
            throw new \InvalidArgumentException('A sales order must have a company; only a cart may be unattached.');
        }

        return parent::setCompany($company);
    }

    public function getOrderNumber(): string { return $this->orderNumber; }
    public function setOrderNumber(string $orderNumber): self { $this->orderNumber = $orderNumber; return $this; }

    /**
     * The CommercialDocument view of $orderNumber — the only sales document that needs one.
     *
     * An adapter and not a rename. The property name is what Doctrine recognises in DQL and in
     * array criteria, and the admin order search, the sort and OrderNumberGenerator's uniqueness
     * check all name `orderNumber`; the alias that was tried once broke every one of them with
     * "Unrecognized field", which is the story AbstractSalesDocument's docblock tells at length.
     * So the column keeps the name the sell side calls it and the contract gets its own view of it,
     * exactly as PurchaseOrder does for $poNumber on the buy side.
     */
    public function getDocumentNumber(): string { return $this->orderNumber; }

    /**
     * Narrowed to non-null for CommercialDocument, which promises a date rather than a maybe-date.
     *
     * The nullable return on the base belongs to Cart, which is not a document and does not
     * implement the contract. An order has a date always, and takes the column back to NOT NULL in
     * the AttributeOverride above; SalesDocumentDateStamp fills it at persist time.
     */
    public function getDocumentDate(): string { return (string) parent::getDocumentDate(); }

    public function getStatus(): string { return $this->status; }

    /** Typed accessor for new code. Returns null for legacy/quote-era strings that predate this enum. */
    public function getStatusEnum(): ?SalesOrderStatus { return SalesOrderStatus::tryFrom($this->status); }

    /**
     * `HasStatus::canEditOnStatus()`. Replaces the private `OrderController::isOrderLockedForEditing()`,
     * which took a raw status string; the rule itself lives on `SalesOrderStatus::allowsEditing()`. A
     * legacy/quote-era status this enum does not know reads as editable, matching that method's own
     * former behaviour (a string comparison against 'closed'/'void' that any other value fails).
     */
    public function canEditOnStatus(): bool
    {
        return $this->getStatusEnum()?->allowsEditing() ?? true;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Status. There is ONE gate: setStatus(). There are no status verbs.
     * ------------------------------------------------------------------------------------------
     *
     * `approve()` and `void()` used to live here. They are gone, and their bodies are below in
     * setStatus() and the private hook it calls. Owner ruling R2, `STATUS-SEAM-HANDOFF.md`:
     *
     *   *"SetStatus needs to be the new place for approve and approve() should be removed."*
     *   *"Status names cannot be verbs and MUST be moved. It's about status names."*
     *
     * Nothing about WHAT the order does changed. Only a Draft may be approved; a void order is
     * final and may not be voided twice; the timeline still says "Order approved." and
     * "Order voided (was Approved)." The rules simply stopped being reachable from two places and
     * became reachable from one.
     *
     * The one thing a caller gained is the void REASON. `void()` took it as an argument and composed
     * the sentence; the gate takes a comment, so a caller with a reason composes
     * "Order voided (was Approved): Customer changed their mind." and passes it, and a caller with
     * nothing to add passes nothing and gets the same default sentence as before.
     *
     * Two of this document's statuses are a human's to set — Approved and Void — and every other one
     * is derived from its invoice set by SalesOrderStatusDeriver, through applyDerivedStatus(). That
     * is the second door, and it is chosen by what the caller knows rather than by which method it
     * happens to have found: a caller that NAMES a target uses setStatus(); a caller that wants a
     * recalculation and neither knows nor needs the answer uses applyDerivedStatus().
     *
     * A Doctrine subscriber is the wrong place to enforce a transition, because by onFlush the
     * change is made and the caller's intent is gone. That same fact is why the gate writes the
     * SalesOrderLog entry rather than leaving it to the caller: "Voided by the stale-unpaid sweep"
     * and "Voided by Priya, customer changed their mind" are the same changeset and different
     * history. Audit logging (AuditLogSubscriber) and inventory reconciliation
     * (InventoryReconciliationSubscriber) already happen path-independently and are deliberately
     * NOT re-implemented here.
     */

    /**
     * The gate, made PUBLIC on this document. The shared body is on `AbstractSalesDocument`.
     *
     * The shared body is protected, because `Cart` extends the same class and has no status at all
     * — see the note on `AbstractSalesDocument::setStatus()`. An order's write door is open, and
     * this line is where it says so, as `Estimate`, `Invoice` and `CreditMemo` each do in theirs.
     *
     * What an order will and will not accept is in {@see self::assertStatusChangeAllowed()}, one
     * method down. It is the old verbs, and nothing else.
     */
    public function setStatus(string $status, DocumentActor $actor, ?string $comment = null): string
    {
        return parent::setStatus($status, $actor, $comment);
    }

    /**
     * Everything `approve()` and `void()` refused, in the one place a caller can reach.
     *
     * Read as two rules, which is all the two verbs ever were:
     *
     *  1. **Void is final, and voiding a void order is a mistake rather than a no-op.** A voided
     *     order is out of reporting totals and is never backdated away. The two refusals are
     *     deliberately different, and the difference is load-bearing rather than cosmetic: asking
     *     for any OTHER status out of Void is the document saying it does not go there from here,
     *     which is `StatusTransitionRefused` — so `canTransitionTo()` answers false and a picker
     *     offers nothing. Asking to void it a second time is the caller making the same mistake
     *     twice, which is a plain `DomainException` and says so. Both sentences are `void()`'s, word
     *     for word.
     *
     *  2. **Only a Draft can be approved, and only by somebody asking.** This is `approve()`'s guard
     *     and it is deliberately stricter than anything a vocabulary could have said: the DERIVER
     *     has to be able to write Approved from every live state — that is exactly what it does when
     *     the last counting invoice is cancelled off an order — so no `from -> to` table could also
     *     express "by a human, and only from Draft". That is why the map could not hold this rule,
     *     and why it is here. `applyDerivedStatus()` does not come through this method at all, so the
     *     deriver is untouched by it.
     *
     *     It is a plain `DomainException`, not a refusal about reachability, and that is what it was
     *     before too: an order stranded on a quote-era value is still OFFERED Approve by the status
     *     control, and pressing it still comes back with this sentence. The escape from a legacy
     *     value is Void, and then the deriver returns the order to Draft —
     *     `UnknownStatusIsNotADeadEndCest` pins both halves.
     *
     * Neither rule enumerates legal predecessors, which is what keeps a stranded document
     * repairable: `void()` asked only "am I already Void" and `approve()` only "am I a Draft", so a
     * row holding a value the vocabulary has never known may still be voided and may still be
     * saved. That is the section 7 ruling, and it survives the map's deletion because the verbs
     * never depended on the map for it.
     *
     * Approving is deliberately not idempotent: approving an order that is already live is a mistake
     * in the caller, not a no-op, and swallowing it would put a second "Approved" row on the timeline
     * of an order nothing happened to.
     *
     * Voiding an order does NOT cancel its invoices. An invoice is an accounting document with a life
     * of its own, and a caller that wants both — the stale-unpaid sweep, a customer cancelling an
     * unpaid ecom order — composes the two rather than having one imply the other.
     */
    protected function assertStatusChangeAllowed(string $from, string $to): void
    {
        if ($from === 'Void') {
            if ($to === 'Void') {
                throw new \DomainException(sprintf('Order %s is already void.', $this->documentLabel()));
            }

            throw StatusTransitionRefused::move($this->statusDocumentLabel(), $from, $to, []);
        }

        if ($to === 'Approved' && $from !== 'Draft') {
            throw new \DomainException(sprintf(
                'Only a Draft order can be approved; %s is %s.',
                $this->documentLabel(),
                $from,
            ));
        }
    }

    /**
     * The sentences `approve()` and `void()` wrote, kept byte for byte.
     *
     * A caller that names only the target still gets the words the verb used to write — "Order
     * approved.", "Order voided (was Approved)." — because a reorganisation of where code lives does
     * not reword a customer's history. A caller with something to add, a void reason in particular,
     * passes the whole comment and this is not consulted.
     */
    protected function defaultStatusComment(string $from, string $to): string
    {
        return match ($to) {
            'Approved' => 'Order approved.',
            'Void' => sprintf('Order voided (was %s).', $from),
            default => parent::defaultStatusComment($from, $to),
        };
    }

    /**
     * What this order's own invoices say its status should be (queue item 64).
     *
     * This is `SalesOrderStatusDeriver::statusFor()`, moved. The important discovery was that it was
     * already PURE — no constructor dependencies, reading only the order's own methods — so nothing
     * stopped the logic living here, and having it here is what lets `applyDerivedStatus()` take no
     * status argument at all. The deriver keeps its ORCHESTRATION: deciding WHEN to run is still
     * `recalculate()`'s, called by `SalesOrderDerivedStatusSubscriber` on every write to an order or
     * to any of its invoices.
     *
     * | Status              | When                                                                |
     * |---------------------|---------------------------------------------------------------------|
     * | Draft               | not approved (whatever its invoices say)                            |
     * | Approved            | approved, and nothing counts as invoiced                            |
     * | Partially Invoiced  | some but not all ordered quantity invoiced                          |
     * | Invoiced            | all quantity invoiced, one or more of those invoices not fully paid |
     * | Closed              | all quantity invoiced and every one of those invoices fully paid    |
     * | Void                | never derived — only void() writes it                               |
     *
     * Returns NULL for a void order, which is how a document says it is out of the deriver's reach.
     * Void is a judgement about the order that nothing about its invoices may undo. Returning the
     * order's own status instead would be a no-op rather than a refusal, and would lose that.
     *
     * A status this order's enum does not know — the quote-era strings, a pre-#539 fulfilment case —
     * reads as not approved, i.e. Draft. That absorption is deliberate and predates this change: a
     * legacy row must still load and still save.
     */
    public function deriveStatus(): ?string
    {
        if ($this->isStatus('Void')) {
            return null;
        }

        $current = $this->getStatusEnum();

        if ($current === null || !$current->isApprovedOrLater()) {
            return 'Draft';
        }

        if (!$this->hasCountingInvoices()) {
            return 'Approved';
        }

        if (!$this->isFullyInvoiced()) {
            return 'Partially Invoiced';
        }

        foreach ($this->getCountingInvoices() as $invoice) {
            if (!$invoice->isFullyPaid()) {
                return 'Invoiced';
            }
        }

        return 'Closed';
    }

    /**
     * The order's own status column, for the shared `setStatus()` on `AbstractSalesDocument`.
     *
     * Protected, so it is not a second door around `setStatus()`. There is still no public status
     * setter on this class and there never was one.
     */
    protected function readStatus(): string
    {
        return $this->status;
    }

    protected function writeStatus(string $status): void
    {
        $this->status = $status;
    }

    /** This order's pending timeline entry, queued for `setStatus()` to fill. */
    public function newLogEntry(): ?DocumentLog
    {
        return $this->queueActivityLogEntry();
    }

    /**
     * The exact sentence #539 has been writing on every derived move since it landed, kept
     * byte-for-byte. See the hook on AbstractSalesDocument for why this is preserved rather than
     * standardised: a shape-only change does not reword a customer's history.
     */
    protected function derivedStatusComment(string $from, string $to): string
    {
        return sprintf('Order status changed from %s to %s.', $from, $to);
    }

    protected function statusDocumentLabel(): string
    {
        return 'Order ' . $this->documentLabel();
    }

    private function documentLabel(): string
    {
        return $this->orderNumber !== '' ? $this->orderNumber : '#' . (string) $this->id;
    }

    public function getPaymentMethod(): ?string { return $this->paymentMethod; }
    public function setPaymentMethod(?string $paymentMethod): self { $this->paymentMethod = $paymentMethod; return $this; }
    public function getPaymentTerm(): ?string { return $this->paymentTerm; }
    public function setPaymentTerm(?string $paymentTerm): self { $this->paymentTerm = $paymentTerm; return $this; }

    /**
     * No setVersion(): Doctrine reads/writes this column itself, by reflection, during
     * flush() — a public setter would only invite something to hand-roll a value that has to
     * come from the database to mean anything.
     */
    public function getVersion(): int { return $this->version; }

    /** @return Collection<int, SalesOrderLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(SalesOrderLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setOrder($this);
        }

        return $this;
    }

    /**
     * Detaching the line is the delete: $lines is orphanRemoval, so an order that no longer holds
     * a line no longer has it in the database either. Same contract Estimate::removeLine() has,
     * and what lets an order edit drop the rows a save omitted without deleting the ones it kept.
     *
     * REFUSED while an invoice bills the line (item 63). See lineDeletionRefusal() for the whole
     * argument; the refusal is here, on the entity, because this is the one method that deletes an
     * order line and a guard at the screen is a guard the next caller does not get.
     *
     * @throws \DomainException when a live invoice is attributed to $line
     */
    public function removeLine(SalesOrderLine $line): self
    {
        $refusal = $this->lineDeletionRefusal($line);
        if ($refusal !== null) {
            throw new \DomainException($refusal);
        }

        $this->lines->removeElement($line);

        return $this;
    }

    /**
     * Why $line may not be deleted, or null when it may (item 63).
     *
     * ## The defect
     *
     * `invoice_line.sales_order_line_id` is `ON DELETE SET NULL`, and its own docblock says why:
     * losing the attribution must never silently delete the billing record of goods that were
     * actually sent. Nothing held up the other end of that bargain. Deleting an order line that an
     * invoice billed left the invoice line pointing at nothing — the customer still charged for the
     * units, and invoicedQuantityFor(), which attributes by exactly that foreign key, no longer
     * counting them against anything at all.
     *
     * ## Why refusing, and not cascading the delete
     *
     * Cascading would delete an issued invoice's line, which changes what a customer was billed
     * because somebody tidied up an order. An invoice is the accounting record; the order is an
     * operations document. That split is not invented here — it is the same one that makes
     * REDUCING an invoiced line legal and deliberate, on the stated policy that the excess already
     * billed is a discrepancy to resolve with a credit. Reducing leaves the attribution intact and
     * the two documents able to disagree in the open. Deleting destroys the link that lets anyone
     * see the disagreement at all, which is the difference between a discrepancy and a silence.
     *
     * ## Where the line is drawn, and why not at "any invoice line that ever existed"
     *
     * A CANCELLED invoice charges nothing and never will, so it has no claim to make on an order
     * line and must not hold one hostage. Drafts DO count: a draft's line is issued later and bills
     * then, so orphaning it now is the same defect one step down the road. So the rule is every
     * invoice that can still charge somebody, which is every invoice on this order bar the
     * cancelled ones. Invoices unlinked from the order are not a gap in that scan —
     * Invoice::unlinkFromOrder() nulls the attribution on every one of its lines as it goes, so an
     * unlinked invoice holds no claim to find.
     */
    public function lineDeletionRefusal(SalesOrderLine $line): ?string
    {
        $billing = [];
        foreach ($this->invoices as $invoice) {
            if ($invoice->isCancelled()) {
                continue;
            }

            $units = QuantityScale::canonical(0);
            foreach ($invoice->getLines() as $invoiceLine) {
                if ($invoiceLine->getSalesOrderLine() === $line) {
                    $units = QuantityScale::add($units, $invoiceLine->getQuantity());
                }
            }

            if (QuantityScale::compare($units, 0) > 0) {
                $billing[] = sprintf(
                    '%s (%s)',
                    $invoice->getDocumentNumber(),
                    (new DisplayNumber())->qty($units),
                );
            }
        }

        if ($billing === []) {
            return null;
        }

        return sprintf(
            'The line for %s on order %s is billed on %s and cannot be deleted: %s would go on charging'
            . ' the customer for units attributed to no order line at all. Reduce the line quantity instead'
            . ' — an order may say less than was invoiced, and the difference is settled with a credit note'
            . ' — or deal with the invoice first, by cancelling it or crediting those units back.',
            $line->getName() !== '' ? $line->getName() : ($line->getSku() ?? 'this product'),
            $this->getOrderNumber(),
            implode(', ', $billing),
            count($billing) === 1 ? 'it' : 'they',
        );
    }

    /** @return Collection<int, Invoice> */
    public function getInvoices(): Collection { return $this->invoices; }

    /**
     * Keeps both sides in sync so an order created and invoiced in one request sees its own
     * invoice — every quantity and payment question this class answers reads the collection, and a
     * stale one answers them about an order that has not been invoiced.
     */
    public function addInvoice(Invoice $invoice): self
    {
        if (!$this->invoices->contains($invoice)) {
            $this->invoices->add($invoice);
            $invoice->setSalesOrder($this);
        }

        return $this;
    }

    /**
     * Detach an invoice from this order (#539 stage 5).
     *
     * Both sides again, so the order stops counting it in the same request. The invoice is NOT
     * deleted — this collection carries no orphanRemoval for exactly that reason: an accounting
     * document outlives the order it was linked to, and unlinking one is a statement about the link
     * rather than about the invoice.
     *
     * Called by Invoice::unlinkFromOrder(), which is where the timeline entries and the from-state
     * check live.
     */
    public function removeInvoice(Invoice $invoice): self
    {
        $this->invoices->removeElement($invoice);
        $invoice->setSalesOrder(null);

        return $this;
    }

    /**
     * The invoices that count toward this order being invoiced: neither drafts nor cancellations.
     *
     * Stated here rather than at each caller because "which invoices count" is one rule, and
     * stage 2 derives the order's whole status from it.
     *
     * @return list<Invoice>
     */
    public function getCountingInvoices(): array
    {
        return array_values(array_filter(
            $this->invoices->toArray(),
            static fn (Invoice $invoice): bool => $invoice->countsTowardInvoicedQuantity(),
        ));
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Invoiced quantity (#539 stage 2)
     * ------------------------------------------------------------------------------------------
     *
     * Derived on every read, never stored. A stored "uninvoiced quantity" column is a second answer
     * to a question the invoice lines already answer, and it drifts the first time an invoice is
     * edited — which the create-invoice screen makes an ordinary thing to do.
     *
     * Attribution is by InvoiceLine::$salesOrderLine, not by SKU. An invoice MAY carry a line that
     * is not on the order at all (a fee, an extra added at ship time), and such a line draws down
     * nothing: it has no order line to draw down. Matching on SKU instead would silently credit
     * those against whichever order row happened to share the code.
     *
     * Quantities are decimal strings, summed in whole units of the COLUMN's scale and spelt back
     * out at it. This paragraph used to end "when something does, this is one of the places that has
     * to widen with it — two decimals here would quietly round a third of a case away", and the day
     * came: a line for 12.3456 reported 12.35 uninvoiced, which reserved 12.35 of stock against a
     * shelf holding 12.3456 and refused the invoice as 0.0044 short of itself. Exactly the failure
     * the note predicted, four decimal places down.
     *
     * Summed exactly rather than as floats for the reason `App\Service\QuantityScale` gives: an
     * order billed across three invoices must reconcile to zero remaining, and a float sum leaves a
     * ten-thousandth behind that nothing can ever invoice.
     */

    /** How much of one order line has been invoiced by invoices that count. */
    public function invoicedQuantityFor(SalesOrderLine $line): string
    {
        $total = QuantityScale::canonical(0);

        foreach ($this->getCountingInvoices() as $invoice) {
            foreach ($invoice->getLines() as $invoiceLine) {
                if ($invoiceLine->getSalesOrderLine() === $line) {
                    $total = QuantityScale::add($total, $invoiceLine->getQuantity());
                }
            }
        }

        return $total;
    }

    /**
     * What is left to invoice on one order line: ordered minus invoiced, floored at zero.
     *
     * Floored because an invoice line's quantity may not exceed the order's remaining quantity, so
     * a negative here means data written before that rule existed (or edited around it) — and a
     * negative remainder would then let the NEXT invoice quietly borrow quantity back.
     */
    public function uninvoicedQuantityFor(SalesOrderLine $line): string
    {
        $remaining = QuantityScale::sub($line->getQuantity(), $this->invoicedQuantityFor($line));

        return QuantityScale::compare($remaining, 0) < 0 ? QuantityScale::canonical(0) : $remaining;
    }

    /**
     * The same two figures, re-expressed in the unit the LINE was written in (#644).
     *
     * The printed sales order shows Ordered / Invoiced / Remaining side by side, and a row that
     * counted the first in boxes and the other two in eaches would be unreadable — 40, 240, 240
     * against a line of 40 boxes. So the whole row is stated in one denomination, the line's own.
     *
     * Both are DERIVED, exactly as the base-unit versions above are, and neither is stored. On a
     * line entered in base units they answer what the originals answer, character for character.
     */
    public function invoicedQuantityInLineUnitFor(SalesOrderLine $line): string
    {
        return LineDenomination::toEnteredQuantity(
            $this->invoicedQuantityFor($line),
            $line->getUnitOfMeasure(),
            LineDenomination::baseUnitOf($line->getProduct()),
        );
    }

    public function uninvoicedQuantityInLineUnitFor(SalesOrderLine $line): string
    {
        return LineDenomination::toEnteredQuantity(
            $this->uninvoicedQuantityFor($line),
            $line->getUnitOfMeasure(),
            LineDenomination::baseUnitOf($line->getProduct()),
        );
    }

    /**
     * How much of the charge under $slug has been invoiced by invoices that count (#539 stage 5).
     *
     * The product-line counterpart above attributes by InvoiceLine::$salesOrderLine; a charge row
     * lives in a JSON snapshot and has no foreign key to carry, so it attributes by slug — the key
     * a charge row already has for exactly this purpose. See getChargeSlugs() for what that means
     * when one document carries two rows under one slug.
     *
     * An invoice charge row whose slug is not on this order draws down nothing, the same way an
     * invoice PRODUCT line with no order line behind it does: #539 allows an invoice to carry a
     * charge the order never had, added at ship time, and such a row has nothing to draw down.
     */
    public function invoicedChargeQuantityFor(string $slug): string
    {
        $total = QuantityScale::canonical(0);

        foreach ($this->getCountingInvoices() as $invoice) {
            $total = QuantityScale::add($total, $invoice->chargeQuantityFor($slug));
        }

        return $total;
    }

    /**
     * What is left to invoice of the charge under $slug. Floored at zero, for the reason
     * uninvoicedQuantityFor() is floored.
     */
    public function uninvoicedChargeQuantityFor(string $slug): string
    {
        $remaining = QuantityScale::sub($this->chargeQuantityFor($slug), $this->invoicedChargeQuantityFor($slug));

        return QuantityScale::compare($remaining, 0) < 0 ? QuantityScale::canonical(0) : $remaining;
    }

    /**
     * True when no ordered quantity is left to invoice — of goods OR of charges.
     *
     * By quantity only, never by amount: prices are editable at invoice level, so an invoice total
     * need not match its share of the order and an amount comparison would call a discounted
     * invoice incomplete forever.
     *
     * Charges count here because #539 stage 5 makes a charge a quantified row like any other: a
     * flat fee is a row of quantity 1, part of it may be billed now and the rest later, and the
     * order is not Invoiced until every row it carries — charges included — has reached its ordered
     * quantity. Leaving them out would have declared an order fully invoiced while its freight was
     * still unbilled.
     *
     * An order with neither lines nor charges is vacuously fully invoiced, which is why
     * hasCountingInvoices() and not this is what separates Approved from Invoiced in the deriver.
     */
    public function isFullyInvoiced(): bool
    {
        foreach ($this->lines as $line) {
            if ((float) $this->uninvoicedQuantityFor($line) > 0.0) {
                return false;
            }
        }

        foreach ($this->getChargeSlugs() as $slug) {
            if ((float) $this->uninvoicedChargeQuantityFor($slug) > 0.0) {
                return false;
            }
        }

        return true;
    }

    /** True when at least one invoice counts toward this order's invoiced quantity. */
    public function hasCountingInvoices(): bool
    {
        return $this->getCountingInvoices() !== [];
    }

    /** @return Collection<int, SalesOrderAddress> */
    public function getAddresses(): Collection
    {
        return $this->orderAddresses;
    }

    protected function newAddress(string $type): AbstractDocumentAddress
    {
        $address = (new SalesOrderAddress())->setType($type);
        $address->setOrder($this);
        $this->orderAddresses->add($address);

        return $address;
    }
}
