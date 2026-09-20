<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Document\DocumentLog;
use App\Contract\Payment\PayableDocument;
use App\Contract\Status\HasStatus;
use App\Enum\InvoicePaymentStatus;
use App\Enum\InvoiceShippingStatus;
use App\Enum\InvoiceStatus;
use App\Service\DocumentActor;
use App\Service\MandatoryCaptureGuard;
use App\Service\OverInvoicingGuard;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The fourth AbstractSalesDocument subtype (issue #539).
 *
 * A sales order used to be its own invoice: it carried the payment status, the payment-driven
 * fulfilment statuses and the inventory holds, and printed itself as an invoice. That is true for
 * a webstore, where one order is one invoice paid all at once, and false for a distribution
 * business, where one accepted order is shipped and billed across several invoices over weeks.
 *
 * So the order keeps only "how much of this has been invoiced", and everything that was really a
 * question about money or goods moves here. The ecom path is unchanged in behaviour: checkout
 * writes one order and one invoice atomically, so 1:1 still holds wherever it always did.
 *
 * See docs/plans/2026-08-20-invoice-as-separate-entity.md.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice')]
#[ORM\AttributeOverrides([
    // The base widened this for Cart. An invoice is always dated — it is a document raised on a
    // day — so it narrows the column back, exactly as SalesOrder and Estimate do.
    new ORM\AttributeOverride(name: 'documentDate', column: new ORM\Column(length: 10)),
])]
#[ORM\AssociationOverrides([
    new ORM\AssociationOverride(
        name: 'company',
        joinColumns: [new ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false)],
    ),
])]
class Invoice extends AbstractSalesDocument implements PayableDocument, HasStatus
{
    /**
     * Which vocabulary governs an invoice. Read through late static binding by the shared bodies on
     * `AbstractSalesDocument`, so one `loadStatusVocab()` serves every sales document without any of
     * them passing a key around.
     */
    public const STATUS_VOCABULARY = 'invoice';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $documentNumber = '';

    #[ORM\Column(length: 20)]
    private string $status = 'Draft';

    /**
     * The order this invoice bills, when it bills one.
     *
     * Nullable because an invoice need not come from an order at all — an admin may raise a
     * standalone one, and #539 also allows attaching an existing invoice to an order later. It is
     * the presence of this link, not the invoice's existence, that makes an order partially or
     * fully invoiced.
     */
    #[ORM\ManyToOne(targetEntity: SalesOrder::class, inversedBy: 'invoices')]
    #[ORM\JoinColumn(name: 'sales_order_id', referencedColumnName: 'id', nullable: true)]
    private ?SalesOrder $salesOrder = null;

    /**
     * The calendar date this invoice was raised, as a plain 'Y-m-d' string — same reasoning as
     * AbstractSalesDocument::$documentDate, and not a date column for the same reason: a calendar
     * date has no instant, and storing one invites every layer that sees a midnight to convert it.
     *
     * Distinct from documentDate, which the base stamps at persist time. This is the accounting
     * date the invoice is booked under, and an admin may set it to something other than today.
     */
    #[ORM\Column(name: 'invoice_date', length: 10, nullable: true)]
    private ?string $invoiceDate = null;

    /** When payment falls due, derived from the term at issue time but editable afterwards. */
    #[ORM\Column(name: 'due_date', length: 10, nullable: true)]
    private ?string $dueDate = null;

    /**
     * Has the money arrived. Separate from $status, which is about goods — see InvoiceStatus.
     *
     * Authoritative here since stage 4, and DERIVED: it is a projection of $payments against
     * $total, written only by InvoicePaymentStatusDeriver through applyDerivedPaymentStatus(), and
     * there is no setPaymentStatus() for anything else to disagree with the payment rows through.
     * The column exists rather than being computed on every read because the grids sort and filter
     * on it, which a method cannot do.
     */
    #[ORM\Column(name: 'payment_status', length: 40, enumType: InvoicePaymentStatus::class)]
    private InvoicePaymentStatus $paymentStatus = InvoicePaymentStatus::NotPaid;

    /**
     * Have the goods gone. The third axis, separate from $status and from $paymentStatus alike.
     *
     * Derived, on exactly the terms $paymentStatus is: written only by
     * InvoiceShippingStatusDeriver through applyDerivedShippingStatus(), with no
     * setShippingStatus() beside it for a caller to disagree with the shipment rows through. The
     * column exists rather than being computed per read for the same reason the payment one does —
     * the invoice grid sorts and filters on it, and a method cannot appear in an ORDER BY.
     *
     * $status is NOT this value under another name, which is the confusion the column is most
     * likely to invite. $status is a lifecycle an admin drives; this is a fact about how much of
     * what was billed has physically left, and it can say Partially Shipped under a Processing
     * invoice or Not Shipped under a Pending one that was never picked. The one place they do meet
     * is the degraded case: with no shipped-quantity provider active, Completed is the only
     * evidence core has that anything shipped, so the deriver reads it — see InvoiceShippingStatus.
     */
    #[ORM\Column(name: 'shipping_status', length: 40, enumType: InvoiceShippingStatus::class)]
    private InvoiceShippingStatus $shippingStatus = InvoiceShippingStatus::NotShipped;

    /**
     * How this invoice is to be settled, and on what terms. Copied from the order that raised it and
     * editable here afterwards — unlike $paymentStatus these are terms of the sale rather than an
     * answer about money, so they are written by a caller and not derived from anything.
     */
    #[ORM\Column(name: 'payment_method', length: 120, nullable: true)]
    private ?string $paymentMethod = null;

    #[ORM\Column(name: 'payment_term', length: 120, nullable: true)]
    private ?string $paymentTerm = null;

    /**
     * Optimistic-lock counter, for the same reason SalesOrder carries one (#417) — an invoice is
     * edited from an admin form, so two admins can compute a save from the same stale render.
     * Present from the start because adding a version column to a table that already has rows is
     * meaningfully more awkward than declaring it now, and stage 2 opens the edit screen that
     * needs it.
     */
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    /**
     * Ordered explicitly, for the reason Estimate::$lines is: a collection with no ORDER BY comes
     * back in whatever order the database chose, and everything positional about a document row
     * hangs off its position.
     *
     * @var Collection<int, InvoiceLine>
     */
    #[ORM\OneToMany(targetEntity: InvoiceLine::class, mappedBy: 'invoice', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    /**
     * The claims against this invoice from one or more payments (#539 stage 4; became claims rather
     * than raw payment rows at #708, once one payment needed to settle several invoices). These are
     * what $paymentStatus is derived from, so nothing else may decide whether this invoice is paid.
     *
     * Ordered by the date each slice was applied, which is the order the payments screen shows a
     * running balance in — an unordered collection would put the balance column in whatever order
     * the database chose.
     *
     * @var Collection<int, InvoicePaymentApplication>
     */
    #[ORM\OneToMany(targetEntity: InvoicePaymentApplication::class, mappedBy: 'invoice', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['appliedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $applications;

    /**
     * The other side of $balance (#603): credit landed here from one or more credit notes. Read-only
     * from this side — CreditMemo::applyTo()/withdrawApplication() are the only writers (see
     * CreditMemoApplication's own docblock), so this carries no cascade and no orphanRemoval; an
     * invoice never owns or deletes these rows, it only reads what has landed on it.
     *
     * @var Collection<int, CreditMemoApplication>
     */
    #[ORM\OneToMany(targetEntity: CreditMemoApplication::class, mappedBy: 'invoice')]
    #[ORM\OrderBy(['appliedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $creditApplications;

    /**
     * This document's own frozen addresses — see AbstractDocumentAddress for why they are copies
     * rather than a foreign key into the address book.
     *
     * @var Collection<int, InvoiceAddress>
     */
    #[ORM\OneToMany(targetEntity: InvoiceAddress::class, mappedBy: 'invoice', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $invoiceAddresses;

    public function __construct()
    {
        parent::__construct();
        $this->lines = new ArrayCollection();
        $this->applications = new ArrayCollection();
        $this->creditApplications = new ArrayCollection();
        $this->invoiceAddresses = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    /** An invoice always bills someone, so callers keep the non-null contract SalesOrder gives them. */
    public function getCompany(): Company { return $this->company; }

    /** The parameter stays nullable — PHP forbids narrowing one — so the narrowing is the throw. */
    public function setCompany(?Company $company): static
    {
        if (!$company instanceof Company) {
            throw new \InvalidArgumentException('An invoice must have a company; only a cart may be unattached.');
        }

        return parent::setCompany($company);
    }

    public function getDocumentNumber(): string { return $this->documentNumber; }
    public function setDocumentNumber(string $documentNumber): self { $this->documentNumber = $documentNumber; return $this; }

    /**
     * Narrowed to non-null for CommercialDocument, which promises a date rather than a maybe-date.
     *
     * The nullable return on the base belongs to Cart, which is not a document and does not
     * implement the contract. An invoice narrows the column back to NOT NULL in the
     * AttributeOverride above and SalesDocumentDateStamp fills it at persist time, so a stored
     * invoice always has one; before that stamp runs this reads '', which is the same "not dated
     * yet" the stamp itself tests for with trim().
     */
    public function getDocumentDate(): string { return (string) parent::getDocumentDate(); }

    public function getStatus(): string { return $this->status; }

    /** The enum is the frozen core contract, not the storage. Null for a value it no longer knows. */
    public function getStatusEnum(): ?InvoiceStatus { return InvoiceStatus::tryFrom($this->status); }

    /**
     * May money be recorded against this invoice — {@see InvoiceStatus::acceptsPayment()}, asked of
     * the document rather than of the column.
     *
     * Here rather than left to each caller because the column is a plain string now, so every caller
     * would otherwise write the same `getStatusEnum()?->...` dance. A stored value the enum does not
     * know answers false: an invoice nobody can classify is not one to take money against.
     */
    public function acceptsPayment(): bool
    {
        return $this->getStatusEnum()?->acceptsPayment() === true;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Status. There is ONE gate: setStatus(). The two status-named verbs are gone.
     * ------------------------------------------------------------------------------------------
     *
     * `cancel()` and `complete()` used to live here. They are gone, and their bodies are below in
     * {@see self::assertStatusChangeAllowed()} and {@see self::defaultStatusComment()}. Owner
     * rulings R1 and R2, `STATUS-SEAM-HANDOFF.md`:
     *
     *   *"The purpose of the exercise is there is only 1 gate where set status can happen, and any
     *   changes we do is only at one place. Not fixing 56 instances of handrolling."*
     *   *"Status names cannot be verbs and MUST be moved. It's about status names."*
     *
     * The criterion is the NAME. `Cancelled` and `Completed` are statuses in this document's
     * vocabulary, so `cancel()` and `complete()` had to stop being reachable. `issue()`,
     * `issueAwaitingPayment()`, `paymentReceived()` and `startProcessing()` are named after no
     * status this invoice can hold, so they stay public — the owner, on the first of them:
     * *"Issue is not a status. Yes it calls set status."* What changed for those four is only that
     * they now reach the column through the gate instead of writing it themselves.
     *
     * Nothing about WHAT an invoice does changed. An invoice holding payments still cannot be
     * cancelled; a draft still cannot be completed; the over-invoicing guard still runs before
     * issuing; the timeline still says "Invoice cancelled." and "Fulfilment completed." The rules
     * simply stopped being reachable from six places and became reachable from one.
     *
     * The one thing a caller gained is the cancel REASON. `cancel()` took it as an argument and
     * composed the sentence; the gate takes a comment, so a caller with a reason composes
     * "Invoice cancelled: Customer changed their mind." and passes it, and a caller with nothing to
     * add passes nothing and gets the same default sentence as before. That is the shape
     * `SalesOrder` already uses for its void reason.
     *
     * A Doctrine subscriber is the wrong place to enforce a transition, because by onFlush the
     * change is made and the caller's intent is gone. That same fact is why the gate writes the
     * InvoiceLog entry rather than leaving it to the caller: "Cancelled by the stale-unpaid sweep"
     * and "Cancelled by Priya, customer changed their mind" are the same changeset and different
     * history. Audit logging and inventory reconciliation already happen path-independently through
     * subscribers and are deliberately NOT re-implemented here.
     *
     * The actor is passed in rather than resolved here: an entity cannot reach the security
     * context, and an ambient lookup would leave the sweep unable to sign its own work.
     */

    /**
     * The gate, made PUBLIC on this document. The shared body is on `AbstractSalesDocument`.
     *
     * What an invoice will and will not accept is in {@see self::assertStatusChangeAllowed()}, one
     * method down. It is the old verbs, and nothing else.
     */
    public function setStatus(string $status, DocumentActor $actor, ?string $comment = null): string
    {
        return parent::setStatus($status, $actor, $comment);
    }

    /**
     * Everything the six transitions refused, in the one place a caller can reach.
     *
     * Three rules, in the order the verbs asked them — and the order is load-bearing, because two of
     * them could each be the first thing a caller trips over:
     *
     *  1. **The over-invoicing claim.** `issue()` and `issueAwaitingPayment()` both ran
     *     {@see self::assertRoomToIssue()} BEFORE their from-state check, so a draft with no room
     *     left on its order line is told that rather than being told it cannot move. It is here
     *     rather than left in those two methods because the gate is now a way to reach the write:
     *     `setStatus('Pending', ...)` on a draft IS issuing, and a guard the caller can walk around
     *     by picking the other door is not a guard. `assertRoomToIssue()` itself only acts on a
     *     Draft, so the two paths that are not issuing — `paymentReceived()` and a direct move from
     *     On Hold — pass through it untouched, exactly as they did before.
     *
     *  2. **`cancel()`'s payments guard**, before its from-state check, in that order because that
     *     is the order `cancel()` asked them in. An invoice with money against it cannot be
     *     cancelled at all (#539 stage 4, settled with the client, matching Zoho Books;
     *     re-confirmed by the owner for #31). Once a payment has been allocated to an invoice it is
     *     a real accounting record: it is never withdrawn out from under the money, and an admin who
     *     genuinely means to withdraw it deletes the payments first — a deliberate act with its own
     *     timeline entries rather than something a Cancel button does silently to a settled balance.
     *
     *     This is one half of a pair, and the other half is `InvoiceStatus::acceptsPayment()`: a
     *     cancelled invoice cannot take a payment, and an invoice holding payments cannot be
     *     cancelled. The guard exists on both sides so that neither can be reached around the other,
     *     which is also why a cancelled invoice never holds payment rows to orphan — it could not
     *     have been cancelled while holding any. **That statement is only true while this guard is
     *     on the gate.** It was the whole reason this document could not join the seam until the
     *     verbs came with it: a public setter beside a guarded `cancel()` is two doors, and the
     *     unguarded one wins.
     *
     *     The refusal names the number, the amount and the ONE way out that exists. It deliberately
     *     does not offer moving a payment to another invoice: there is no route that does that, and
     *     a message describing a screen the app does not have sends a person looking for it.
     *
     *  3. **The from-states.** These are the `...$allowedFrom` argument lists the six verbs passed
     *     to `transitionTo()`, transcribed — not a revival of the `transitions` map the owner
     *     deleted in R4. The difference is what the deleted map could not do and this can: it sits
     *     in the document's own code, beside the two rules above, which read the payment rows and
     *     the order line and which no `from -> to` grid could ever have expressed.
     *
     *     Where two verbs reached the same status the list here is their UNION — Pending is
     *     `issue()`'s Draft plus `paymentReceived()`'s On Hold — because the gate is asked for a
     *     TARGET and cannot know which verb the caller had in mind. The verbs keep their own
     *     narrower lists (see {@see self::transitionTo()}), so `$invoice->issue()` on an On Hold
     *     invoice still answers "allowed from: Draft" word for word. Nothing new becomes reachable:
     *     both from-states were already legal ways to arrive at Pending.
     *
     * **Draft is reachable from nowhere**, which is not a new refusal but the absence of an old
     * permission: no verb ever wrote Draft, so nothing could ever return an invoice to it. Stating
     * it costs one branch and closes the door the gate would otherwise have opened.
     *
     * Every refusal here is a plain `\DomainException` carrying the sentence `transitionTo()` wrote,
     * byte for byte, because `InvoiceTransitionsTest` pins those sentences and R5 is that a
     * reorganisation changes no result. A terminal `Cancelled` would be better expressed as
     * {@see \App\Exception\StatusTransitionRefused}, so that `canTransitionTo()` answered false out
     * of it — but that is a wording change as well as a type change, so it is a separate decision.
     */
    protected function assertStatusChangeAllowed(string $from, string $to): void
    {
        if ($to === 'Pending' || $to === 'On Hold') {
            $this->assertRoomToIssue();
        }

        if ($to === 'Cancelled' && !$this->applications->isEmpty()) {
            throw new \DomainException(sprintf(
                'Invoice %s has %d payment(s) against it totalling $%s and cannot be cancelled.'
                . ' Delete them on this invoice\'s Payments screen first.',
                $this->documentLabel(),
                $this->applications->count(),
                $this->getAmountPaid(),
            ));
        }

        if ($to === 'Draft') {
            throw new \DomainException(sprintf(
                'Invoice %s cannot be put back to Draft. An invoice that has been issued is never'
                . ' unissued; cancel it and raise another.',
                $this->documentLabel(),
            ));
        }

        $this->assertMovableFrom($from, $to, ...match ($to) {
            'Pending' => ['Draft', 'On Hold'],
            'On Hold' => ['Draft'],
            // Completed is reversible because a shipment can be voided: the goods come back, so the
            // invoice is no longer finished. InvoiceCompletedWhenFullyShippedSubscriber walks it
            // back; a human may too.
            'Processing' => ['Pending', 'Completed'],
            'Completed' => ['Processing'],
            'Cancelled' => ['Draft', 'On Hold', 'Pending', 'Processing', 'Completed'],
            default => [],
        });
    }

    /**
     * The sentences the six verbs wrote, kept byte for byte.
     *
     * A caller that names only the target still gets the words the verb used to write, because a
     * reorganisation of where code lives does not reword a customer's history. A caller with
     * something to add — a cancel reason in particular — passes the whole comment and this is not
     * consulted; the four surviving verbs pass theirs, so in practice this serves the callers that
     * used to reach `cancel()` and `complete()`.
     *
     * Pending is the one target two verbs reached, and the from-state is what told them apart:
     * arriving from On Hold is `paymentReceived()`, from anywhere else it is `issue()`.
     */
    protected function defaultStatusComment(string $from, string $to): string
    {
        return match ($to) {
            'Pending' => $from === 'On Hold'
                ? 'Payment received; invoice released for fulfilment.'
                : 'Invoice issued.',
            'On Hold' => 'Invoice issued, awaiting payment.',
            'Processing' => 'Fulfilment started.',
            'Completed' => 'Fulfilment completed.',
            'Cancelled' => 'Invoice cancelled.',
            default => parent::defaultStatusComment($from, $to),
        };
    }

    /**
     * An invoice derives NOTHING on this axis, and that is a finding rather than a stub.
     *
     * The second door exists for the caller that names no target: something happened elsewhere, the
     * document works out its own status from its own facts, and the caller neither knows nor needs
     * the rules or the outcome. `SalesOrder` has such a status — its invoice set decides Draft,
     * Approved, Partially Invoiced, Invoiced and Closed — and `CreditMemo` has one, because its
     * BALANCE decides Open versus Closed.
     *
     * This column is not that. All six of its values answer *have the goods gone*, and every move
     * between them is somebody naming it: Issue, Issue awaiting payment, Payment received, Start
     * processing, Complete, Cancel. There is no fact about an invoice from which a new status falls
     * out unasked — `StripeOrderPaymentApplier` calling `paymentReceived()` when money lands is a
     * caller naming a target after an event, not the invoice recomputing itself.
     *
     * The vocabulary says the same thing structurally and it is checkable rather than a matter of
     * opinion: `CoreStatusVocabularyProvider::invoice()` flags no status `derived`, and
     * {@see AbstractSalesDocument::applyDerivedStatus()} refuses to write one that is not. So a
     * non-null answer here could not be written even if it were computed — it would need a
     * vocabulary change, which is a design decision and a behaviour change, not this reorganisation.
     *
     * ## `$paymentStatus` is a SEPARATE axis, and stays one
     *
     * Established rather than assumed, because the two look adjacent. `Invoice::$paymentStatus` is
     * its own column with its own enum (Not Paid / Partially Paid / Paid), derived by
     * {@see \App\Service\InvoicePaymentStatusDeriver} through
     * {@see self::applyDerivedPaymentStatus()}. It answers *did the money arrive*, this one answers
     * *did the goods go*, and the vocabulary provider says so outright. They are deliberately not
     * folded together: goods shipped on credit terms are Completed and Not Paid at the same time,
     * and a cancelled invoice keeps saying what actually happened to its money rather than being
     * rewritten by the cancellation. That deriver is untouched by this change.
     */
    public function deriveStatus(): ?string
    {
        return null;
    }

    /**
     * The invoice's own status column, for the shared bodies on `AbstractSalesDocument`.
     *
     * Protected, so neither is a second door around `setStatus()`. The column is a plain string, so
     * both are a straight pass-through — no conversion either way. `ShippedVocabulariesMatchTheirEnumsTest`
     * still pins every `invoice` vocabulary slug against an `InvoiceStatus` case in both directions,
     * and the gate's typo guard rejects an unknown target before anything is written.
     */
    protected function readStatus(): string
    {
        return $this->status;
    }

    protected function writeStatus(string $status): void
    {
        $this->status = $status;
    }

    /** This invoice's pending timeline entry, queued for `setStatus()` to fill. */
    public function newLogEntry(): ?DocumentLog
    {
        return $this->queueActivityLogEntry();
    }

    protected function statusDocumentLabel(): string
    {
        return 'Invoice ' . $this->documentLabel();
    }

    /**
     * Draft -> Pending. Issuing is what makes an invoice real: it starts holding stock.
     *
     * It is also where this invoice CLAIMS its quantity off the order line it bills, which is why
     * OverInvoicingGuard is consulted here and not at save (#31). Two drafts for one order line may
     * both exist; only one of them may be issued if issuing the second would carry the line past
     * what was ordered.
     *
     * The check itself has moved ONTO THE GATE, and that move is the whole reason it is still a
     * guard. It used to be safe here because `transitionTo()` was private and there was no
     * `setStatus()`, so this was the only way in; there is a gate now, and `setStatus('Pending')`
     * on a draft is issuing by another name. See `assertStatusChangeAllowed()`.
     */
    public function issue(DocumentActor $actor): self
    {
        $this->assertRoomToIssue();

        return $this->transitionTo($actor, 'Invoice issued.', 'Pending', 'Draft');
    }

    /**
     * Draft -> On Hold. Issued, but awaiting an up-front payment that has not arrived.
     *
     * Separate from issue() because it is a different claim about the same document: On Hold holds
     * no stock and is what the stale-unpaid sweep looks for.
     *
     * Guarded all the same, and for a reason the "holds no stock" part does not cover: an On Hold
     * invoice has been given to the customer and counts toward its order's invoiced quantity, so it
     * makes the same claim on the order line that Pending does (#31).
     */
    public function issueAwaitingPayment(DocumentActor $actor): self
    {
        $this->assertRoomToIssue();

        return $this->transitionTo(
            $actor,
            'Invoice issued, awaiting payment.',
            'On Hold',
            'Draft',
        );
    }

    /**
     * The over-invoicing check, run only when the transition it guards could otherwise happen.
     *
     * The Draft test is load-bearing rather than an optimisation. An invoice already at Pending or
     * Processing is one of its order's COUNTING invoices, so its own quantity is inside
     * uninvoicedQuantityFor() and the guard would refuse it for holding the quantity it legitimately
     * holds — answering a re-press of Issue with "nothing left to invoice on this order" when the
     * true answer is "this invoice is already issued". transitionTo() gives that answer, in its own
     * words, and it is the one the person needs.
     *
     * So the order is: is this a transition at all, then is there room for it. The write still
     * cannot be reached around the guard, because every path into it passes through here first.
     */
    private function assertRoomToIssue(): void
    {
        if ($this->status === 'Draft') {
            OverInvoicingGuard::assertIssuable($this, $this->documentLabel());
            // Section 5 of the 2026-09-14 lot/serial/expiry plan: a line whose product requires
            // lot/serial capture on the way out must have it before this invoice leaves Draft,
            // unless it is still exempt as backordered. See MandatoryCaptureGuard's own docblock.
            MandatoryCaptureGuard::assertCaptured($this, $this->documentLabel());
        }
    }

    /** On Hold -> Pending, once the up-front payment lands. */
    public function paymentReceived(DocumentActor $actor): self
    {
        return $this->transitionTo(
            $actor,
            'Payment received; invoice released for fulfilment.',
            'Pending',
            'On Hold',
        );
    }

    /** Pending -> Processing. */
    public function startProcessing(DocumentActor $actor): self
    {
        return $this->transitionTo($actor, 'Fulfilment started.', 'Processing', 'Pending');
    }

    /** True once this invoice no longer counts toward its order's invoiced quantity. */
    public function isCancelled(): bool
    {
        return $this->status === 'Cancelled';
    }

    /**
     * Written but not issued — sent to nobody, holding nothing, counting for nothing.
     *
     * Beside isCancelled() because the templates need the same shape of question: which of this
     * invoice's screens are worth offering. Since #31 the Payments screen is not one of them on a
     * draft, and the templates ask this rather than comparing a status string.
     */
    public function isDraft(): bool
    {
        return $this->status === 'Draft';
    }

    /**
     * `HasStatus::canEditOnStatus()`. May this invoice's lines and fields still be changed on the
     * standalone edit screen (#full-parity, 2026-09-12 — widened from Draft-only, per the owner:
     * "well add it cuz zoho allows us to edit it")?
     *
     * The rule itself lives on `InvoiceStatus::allowsEditing()`; this delegates rather than
     * hardcoding the two exceptions a second time. An unrecognised legacy status (`getStatusEnum()`
     * returning null) reads as editable, the same permissive default every other unrecognised-value
     * read in this class uses.
     */
    public function canEditOnStatus(): bool
    {
        return $this->getStatusEnum()?->allowsEditing() ?? true;
    }

    /**
     * Attach this invoice to an order it was not raised from (#539 stage 5).
     *
     * An invoice can exist before anyone decides which order it bills — raised standalone, imported,
     * typed up from a supplier's paperwork — and the issue asks for a way to relate one to an
     * existing sales order afterwards. This is that action, and it is an action for the same reasons
     * every other transition here is one: the from-state has to be checked where the caller's intent
     * still exists, and the timeline entry has to say who did it.
     *
     * $attributions is the order line each of this invoice's lines bills, keyed by
     * spl_object_id() of the invoice line — worked out by InvoiceOrderMatcher, which owns the
     * SKU-matching rule. It is passed in rather than derived here for the reason the actor is:
     * matching an invoice to an order is a decision about two documents, and an entity that decided
     * it alone would be deciding it in the one place a screen cannot show the working. A line with
     * no entry is attributed to nothing, which is a real answer — an invoice may carry a SKU the
     * order does not have — and such a line draws down nothing.
     *
     * Writing that per-row attribution is the whole point. The order's uninvoiced quantity is
     * derived from InvoiceLine::$salesOrderLine; an invoice attached without it would appear on the
     * order and deduct nothing.
     *
     * @param array<int, SalesOrderLine> $attributions
     */
    public function linkToOrder(DocumentActor $actor, SalesOrder $order, array $attributions = []): self
    {
        if ($this->salesOrder instanceof SalesOrder) {
            throw new \DomainException(sprintf(
                'Invoice %s is already linked to order %s. Unlink it from that order first.',
                $this->documentLabel(),
                $this->salesOrder->getOrderNumber(),
            ));
        }

        if ($this->company !== $order->getCompany()) {
            throw new \DomainException(sprintf(
                'Invoice %s bills %s but order %s is for %s.',
                $this->documentLabel(),
                $this->getCompany()->getName(),
                $order->getOrderNumber(),
                $order->getCompany()->getName(),
            ));
        }

        // Both sides, so the order can see the invoice — and therefore recompute its own derived
        // status — within the same request that attached it.
        $order->addInvoice($this);

        foreach ($this->lines as $line) {
            $line->setSalesOrderLine($attributions[spl_object_id($line)] ?? null);
        }

        $order->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf('Invoice %s was linked to this order.', $this->documentLabel()))
            ->setType('System');

        $this->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf('Linked to order %s.', $order->getOrderNumber()))
            ->setType('System');

        return $this;
    }

    /**
     * Detach this invoice from the order it bills — linked to the wrong one, or linked in error.
     *
     * The per-row attribution goes with it, deliberately: leaving InvoiceLine::$salesOrderLine
     * pointing at rows of an order this invoice no longer belongs to would keep drawing that order's
     * quantity down through a link nothing displays any more.
     *
     * Not the same thing as cancelling. The invoice survives, keeps its number and its money, and is
     * simply no longer anybody's instalment.
     */
    public function unlinkFromOrder(DocumentActor $actor, ?string $reason = null): self
    {
        $order = $this->salesOrder;
        if (!$order instanceof SalesOrder) {
            throw new \DomainException(sprintf(
                'Invoice %s is not linked to an order.',
                $this->documentLabel(),
            ));
        }

        foreach ($this->lines as $line) {
            $line->setSalesOrderLine(null);
        }

        $order->removeInvoice($this);

        $order->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf('Invoice %s was unlinked from this order.%s', $this->documentLabel(), $this->suffix($reason)))
            ->setType('System');

        $this->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf('Unlinked from order %s.%s', $order->getOrderNumber(), $this->suffix($reason)))
            ->setType('System');

        return $this;
    }

    /**
     * A draft is entirely inert: it holds no inventory and does not draw down its order's sales
     * hold. Anything that asks "how much of this order is invoiced" must skip drafts as well as
     * cancellations, which is why this is stated once, here, rather than at each caller.
     *
     * ONE reading answers both questions anyone asks of an invoice's quantity — "is this a charge
     * against the customer" and "is this a claim on the order line" — and #31 did not add a second.
     * The buy side needs two (`VendorBillStatus::counts()` and `::claimsOrderedQuantity()`, which
     * disagree about exactly one case, the draft) because a draft bill already feeds its match and
     * exception screens. A draft invoice feeds nothing and is neither: it is a person part way
     * through deciding what to bill. So {@see \App\Service\OverInvoicingGuard} measures its
     * remainder with this same method, at issue, and "remaining to invoice" keeps counting issued
     * invoices exactly as it always has.
     */
    public function countsTowardInvoicedQuantity(): bool
    {
        return $this->status !== 'Draft' && $this->status !== 'Cancelled';
    }

    /**
     * The same rule as {@see countsTowardInvoicedQuantity()}, as a list, for the callers that have
     * to ask it of many rows at once in DQL rather than of one loaded entity.
     *
     * The two exclusions are stated twice, which is the lesser of the two evils available here and
     * worth saying why. The method above answers about `$this->status`, a plain string column, by
     * EXCLUSION — so a value that is not a known status still counts. This answers by INCLUSION,
     * because `IN (:statuses)` is the only shape DQL has. Making the method delegate to this list
     * would have quietly changed its answer for any row whose status is not one of the enum's cases,
     * from counting to not counting, which is a data-dependent behaviour change nothing asked for.
     *
     * So the list is derived from the enum by the same exclusion instead: a case added to
     * InvoiceStatus joins it by default, and the only way the two can disagree is if somebody adds
     * a status that should not count and updates neither — which leaves both reading "it counts",
     * the same answer, rather than two different ones.
     *
     * @return list<string>
     */
    public static function invoicedQuantityStatuses(): array
    {
        return array_values(array_map(
            static fn (InvoiceStatus $status): string => $status->value,
            array_filter(
                InvoiceStatus::cases(),
                static fn (InvoiceStatus $status): bool => $status !== InvoiceStatus::Draft
                    && $status !== InvoiceStatus::Cancelled,
            ),
        ));
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Payments (#539 stage 4; became claims against a shared pool at #708 — see InvoicePayment's
     * own docblock for the split)
     * ------------------------------------------------------------------------------------------
     *
     * Recording, applying, amending, withdrawing and moving are named actions for the same three
     * reasons every other transition on this entity is one: an illegal one is refused where the
     * intent still exists, the derived payment status stays underivable by hand, and each writes its
     * own timeline entry. Money arriving is exactly the kind of event someone reads a document's
     * history to find.
     *
     * There is no addApplication(): a collection mutator would let a caller attach a claim without
     * saying who did it, and "who recorded this payment" is the whole reason the timeline exists.
     */

    /**
     * Record a brand-new payment and apply the whole of it to this invoice in one step — the
     * single-invoice convenience button (#708 kept this exact call shape on purpose: every existing
     * caller — `StripeOrderPaymentApplier`, the demo/seed commands, the admin screen's "record"
     * branch — builds an `InvoicePayment` and calls this, unchanged, whether or not it ends up
     * settling one invoice or many).
     */
    public function recordPayment(DocumentActor $actor, InvoicePayment $payment, ?string $note = null): self
    {
        $payment->setCompany($this->company);
        $this->applyPayment($actor, $payment, $payment->getAmount(), $payment->getReceivedAt(), $note);

        return $this;
    }

    /**
     * Claim part or all of an ALREADY-RECORDED payment against this invoice — what the multi-invoice
     * picker calls once per invoice it is splitting a payment across, and what `recordPayment()`
     * above calls once, in full, for the single-invoice path.
     *
     * Every rule this can fail on — cancelled, draft, wrong customer, wrong currency, not enough of
     * the payment left — is `InvoicePayment::applyTo()`'s, asked before anything is written here.
     */
    public function applyPayment(
        DocumentActor $actor,
        InvoicePayment $payment,
        string $amount,
        ?\DateTimeImmutable $appliedAt = null,
        ?string $note = null,
    ): InvoicePaymentApplication {
        $application = $payment->applyTo($this, $amount, $appliedAt);
        $this->applications->add($application);

        $this->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf(
                'Payment of $%s via %s recorded.%s',
                $application->getAmount(),
                $payment->getMethod(),
                $this->suffix($note ?? $payment->getComment()),
            ))
            ->setType('System');

        return $application;
    }

    /**
     * A claim corrected — the wrong figure typed, the wrong date, the wrong method.
     *
     * The amount and date belong to THIS application; the method and comment belong to the
     * underlying payment and are shared with every other invoice it also settles — see
     * `App\Contract\Payment\PaymentApplication`'s own docblock for why the split is drawn there.
     * The new values are applied here rather than by the caller so that the entry can say what
     * changed: "was $40.00" is the only part of a correction anyone needs from the history, and a
     * caller that had already written the new values over the old ones could no longer supply it.
     */
    public function amendApplication(
        DocumentActor $actor,
        InvoicePaymentApplication $application,
        \DateTimeImmutable $appliedAt,
        string $method,
        string $amount,
        ?string $comment,
    ): self {
        $this->assertHolds($application);
        $this->assertPositive($amount);

        $payment = $application->getPayment();

        // The common case — this is the only claim this payment makes — grows or shrinks the pool
        // alongside the application, which is what restores the pre-#708 behaviour exactly:
        // correcting the one number on the one row an admin sees IS correcting the payment. A
        // payment split across several invoices cannot take that shortcut without silently
        // inventing or discarding money on whichever invoice is not the one being edited, so it is
        // capped instead — the payment's unapplied balance PLUS what this row already claims, since
        // that amount is being replaced rather than added to.
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

        $this->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf(
                'Payment updated to $%s via %s (was $%s via %s).%s',
                $amount,
                $method,
                $wasAmount,
                $wasMethod,
                $this->suffix($comment),
            ))
            ->setType('System');

        return $this;
    }

    /**
     * A claim taken back off the invoice — recorded twice, claimed against the wrong document, or a
     * cheque that bounced. The underlying payment is untouched and its freed amount becomes
     * available to apply elsewhere; this is the half of "move" that always runs first.
     *
     * The row is deleted rather than flagged: $applications is orphanRemoval, and a claim that did
     * not happen is not a claim of zero. What survives is the timeline entry written here and the
     * audit log's own record of the deletion, which is where "there used to be $40 here" belongs.
     */
    public function withdrawApplication(DocumentActor $actor, InvoicePaymentApplication $application, ?string $reason = null): self
    {
        $this->assertHolds($application);

        $payment = $application->getPayment();
        $this->applications->removeElement($application);
        $payment->withdrawApplication($application);

        $this->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf(
                'Payment of $%s via %s deleted.%s',
                $application->getAmount(),
                $payment->getMethod(),
                $this->suffix($reason),
            ))
            ->setType('System');

        return $this;
    }

    /**
     * Move a claim onto a different invoice — queue item 34, the sell side's mirror of
     * `VendorBill::moveApplication()`. Same underlying payment, same date, method, reference and
     * actor; only which invoice this slice settles changes.
     *
     * Realised as withdraw-then-reapply on the SAME payment (#708) rather than a re-pointed row, now
     * that a claim's identity is not what queue item 34 cared about preserving — the PAYMENT's is,
     * and `InvoicePayment::applyTo()`/`withdrawApplication()` never touch its id, its date, its
     * method or its reference. `InvoicePaymentStatusSubscriber` reads the new application's change
     * set at the next flush and re-derives BOTH invoices from it — this one, because it is no longer
     * in `$this->applications`, and `$target`, because the new claim points at it.
     */
    public function moveApplication(
        DocumentActor $actor,
        InvoicePaymentApplication $application,
        Invoice $target,
        ?string $reason = null,
    ): self {
        $this->assertHolds($application);

        if ($target === $this) {
            throw new \DomainException(sprintf(
                'That payment is already on %s. Choose a different invoice to move it to.',
                $this->documentLabel(),
            ));
        }

        $payment = $application->getPayment();
        $amount = $application->getAmount();
        $appliedAt = $application->getAppliedAt();

        // Checked BEFORE either collection is touched, so a refused move leaves the claim exactly
        // where it was rather than withdrawing it first and discovering the target will not take it
        // — see InvoicePayment::assertApplicableTo()'s own docblock for the defect this closes.
        $payment->assertApplicableTo($target);

        $this->applications->removeElement($application);
        $payment->withdrawApplication($application);

        $newApplication = $payment->applyTo($target, $amount, $appliedAt);
        $target->applications->add($newApplication);

        $suffix = $this->suffix($reason);

        $target->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf(
                'Payment of $%s via %s moved in from invoice %s. It keeps its original date (%s) and reference.%s',
                $amount,
                $payment->getMethod(),
                $this->documentLabel(),
                $appliedAt->format('Y-m-d'),
                $suffix,
            ))
            ->setType('System');

        $this->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf(
                'Payment of $%s via %s moved to invoice %s.%s',
                $amount,
                $payment->getMethod(),
                $target->documentLabel(),
                $suffix,
            ))
            ->setType('System');

        return $this;
    }

    /*
     * ------------------------------------------------------------------------------------------
     * PayableDocument — one line each, mirroring VendorBill's own adapters
     * ------------------------------------------------------------------------------------------
     */

    /** Null when this invoice may take a payment; otherwise the reason, in the customer's language. */
    public function paymentRefusal(): ?string
    {
        if ($this->status === 'Cancelled') {
            return sprintf('Invoice %s is cancelled; it is owed nothing and cannot take a payment.', $this->documentLabel());
        }

        if (!$this->acceptsPayment()) {
            return sprintf(
                'Invoice %s is still a draft; it has not been issued to anybody, so no payment can be'
                . ' recorded against it. Issue the invoice first.',
                $this->documentLabel(),
            );
        }

        return null;
    }

    /** Public because a refusal raised outside this class still has to name this invoice. */
    public function getDocumentLabel(): string
    {
        return $this->documentLabel();
    }

    /**
     * `company:7`. Compared for equality and never parsed — see
     * {@see PayableDocument::getPaymentCounterpartyKey()} for why this is a key and not a name.
     */
    public function getPaymentCounterpartyKey(): string
    {
        $company = $this->getCompany();

        return 'company:' . ($company->getId() ?? 'unsaved-' . spl_object_id($company));
    }

    /** @return Collection<int, InvoicePaymentApplication> */
    public function getApplications(): Collection { return $this->applications; }

    /** @return Collection<int, CreditMemoApplication> */
    public function getCreditApplications(): Collection { return $this->creditApplications; }

    /** Everything applied against this invoice, summed in whole cents and formatted back. */
    public function getAmountPaid(): string
    {
        $cents = 0;
        foreach ($this->applications as $application) {
            $cents += self::cents($application->getAmount());
        }

        return self::money($cents);
    }

    /** Everything credited against this invoice by one or more credit notes (#603). */
    public function getAmountCredited(): string
    {
        $cents = 0;
        foreach ($this->creditApplications as $application) {
            $cents += self::cents($application->getAmount());
        }

        return self::money($cents);
    }

    /**
     * What is still owed. Negative when the invoice has been overpaid, which is information.
     *
     * Nets both money actually received AND credit landed here by a credit note (#603) — a note
     * applying its balance in full settles the invoice exactly as a cash payment would, and this is
     * the one figure everything else (the payment status, the AR exposure calculator, the payments
     * screen) reads, so both terms have to be here for either of those to be right.
     */
    public function getBalance(): string
    {
        return self::money(self::cents($this->getTotal()) - self::cents($this->getAmountPaid()) - self::cents($this->getAmountCredited()));
    }

    /**
     * Do payments plus applied credit cover the total — the money question alone, with no view on
     * cancellation.
     *
     * True for an invoice owing nothing at all: a zero total is covered by no payment, and the
     * alternative would leave such an invoice unsettleable and its order open forever.
     */
    public function paymentCoversTotal(): bool
    {
        return self::cents($this->getAmountPaid()) + self::cents($this->getAmountCredited()) >= self::cents($this->getTotal());
    }

    /** Has anything at all been received or credited. Separates Partially Paid from Not Paid. */
    public function hasReceivedMoney(): bool
    {
        return self::cents($this->getAmountPaid()) + self::cents($this->getAmountCredited()) > 0;
    }

    /**
     * Is this invoice still owed — the question SalesOrderStatusDeriver asks when deciding Closed.
     *
     * Distinct from the payment status, deliberately. A cancelled invoice is settled here whatever
     * its payments say, because it is owed nothing and never will be; the alternative would hold an
     * order open forever on an invoice nobody is going to pay. Its payment status meanwhile keeps
     * reporting what actually happened to its money, which is what an admin needs to see.
     *
     * Computed from the payment rows rather than read off $paymentStatus, so that the order's
     * derivation does not depend on this invoice's own derivation having been written first. The two
     * listeners can then run in either order and reach the same answer.
     */
    public function isFullyPaid(): bool
    {
        return $this->status === 'Cancelled' || $this->paymentCoversTotal();
    }

    private function assertHolds(InvoicePaymentApplication $application): void
    {
        if (!$this->applications->contains($application)) {
            throw new \DomainException(sprintf(
                'That payment does not belong to invoice %s.',
                $this->documentLabel(),
            ));
        }
    }

    private function assertPositive(string $amount): void
    {
        if (self::cents($amount) <= 0) {
            throw new \DomainException('A payment must be for more than $0.00.');
        }
    }

    /**
     * The invoice was emailed to someone (#539 stage 6).
     *
     * Not a status transition — sending a copy changes nothing about the document — but it is a
     * mutation a person expects to find on the timeline ("we sent this on the 3rd, to that address,
     * with that subject line"), so it follows the same rule as every other one on this entity: the
     * method that makes the change writes its own entry, and there is no way to make the change
     * without producing it.
     *
     * `$toCustomer` marks the entry as one the buyer was notified of, which is what separates the
     * copy that went to them from the internal copy an admin sent themselves.
     */
    public function recordSent(DocumentActor $actor, string $recipient, string $subject, bool $toCustomer): self
    {
        $this->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf('Invoice emailed to %s. Subject: %s', $recipient, $subject))
            ->setType('System')
            ->setRecipientNotified($toCustomer);

        return $this;
    }

    /** " Comment: x" when there is something to say, and nothing at all when there is not. */
    private function suffix(?string $note): string
    {
        return trim((string) $note) !== '' ? ' Comment: ' . trim((string) $note) : '';
    }

    /**
     * Money is compared in whole cents, never as floats: 0.10 + 0.20 is not 0.30 in binary floating
     * point, and an invoice a hundredth of a cent short is an invoice that never reads as paid.
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

    /**
     * The shared body of the four surviving verbs. It no longer writes anything itself.
     *
     * Two things happen here and the order matters. First the verb's OWN from-state list is
     * checked — `issue()` accepts only a Draft, `paymentReceived()` only an On Hold — which is
     * narrower than the union {@see self::assertStatusChangeAllowed()} applies to the same target,
     * and which is what keeps "An invoice cannot go from On Hold to Pending (allowed from: Draft)"
     * saying Draft rather than listing both. Then the move goes through the gate, which re-asks its
     * own rules, writes the column and writes the timeline row.
     *
     * It used to do the write itself — `$this->status = $target` and an `addLog()` — which is
     * precisely the second door owner ruling R1 exists to close. The refusal below is the one it
     * always threw, word for word.
     */
    private function transitionTo(
        DocumentActor $actor,
        string $comment,
        string $target,
        string ...$allowedFrom,
    ): self {
        $this->assertMovableFrom($this->status, $target, ...$allowedFrom);

        $this->setStatus($target, $actor, $comment);

        return $this;
    }

    /**
     * "An invoice cannot go from X to Y (allowed from: ...)", written once.
     *
     * Shared by the verbs above and by the gate, so the two cannot drift into two dialects of the
     * same refusal. An empty `$allowedFrom` never reaches here: the gate answers the targets nothing
     * may reach — Draft — with its own sentence, before this is called.
     */
    private function assertMovableFrom(string $from, string $target, string ...$allowedFrom): void
    {
        if (in_array($from, $allowedFrom, true)) {
            return;
        }

        throw new \DomainException(sprintf(
            'An invoice cannot go from %s to %s (allowed from: %s).',
            $from,
            $target,
            implode(', ', $allowedFrom),
        ));
    }

    public function getSalesOrder(): ?SalesOrder { return $this->salesOrder; }
    public function setSalesOrder(?SalesOrder $salesOrder): self { $this->salesOrder = $salesOrder; return $this; }

    public function getInvoiceDate(): ?string { return $this->invoiceDate; }
    public function setInvoiceDate(?string $invoiceDate): self { $this->invoiceDate = $invoiceDate; return $this; }

    public function getDueDate(): ?string { return $this->dueDate; }
    public function setDueDate(?string $dueDate): self { $this->dueDate = $dueDate; return $this; }

    public function getPaymentStatus(): InvoicePaymentStatus { return $this->paymentStatus; }

    /**
     * Writes a payment status InvoicePaymentStatusDeriver computed from the payment rows.
     *
     * Public because the deriver is a service and PHP has no friend classes; narrow enough that
     * misuse is loud rather than silent. There is deliberately no setPaymentStatus() beside it —
     * this one takes only a value that was derived, and returns true when the status actually
     * changed so the deriver can write one timeline entry per real transition rather than one per
     * flush that happened to touch the invoice.
     */
    public function applyDerivedPaymentStatus(InvoicePaymentStatus $status): bool
    {
        if ($this->paymentStatus === $status) {
            return false;
        }

        $this->paymentStatus = $status;

        return true;
    }

    public function getShippingStatus(): InvoiceShippingStatus { return $this->shippingStatus; }

    /**
     * Writes a shipping status InvoiceShippingStatusDeriver computed from the shipment rows.
     *
     * The exact counterpart of applyDerivedPaymentStatus() above, and public for the same reason:
     * the deriver is a service and PHP has no friend classes. There is deliberately no
     * setShippingStatus() beside it — this one takes only a value that was derived, and returns
     * true when the status actually changed so the deriver can write one timeline entry per real
     * transition rather than one per flush that happened to touch the invoice.
     */
    public function applyDerivedShippingStatus(InvoiceShippingStatus $status): bool
    {
        if ($this->shippingStatus === $status) {
            return false;
        }

        $this->shippingStatus = $status;

        return true;
    }

    public function getPaymentMethod(): ?string { return $this->paymentMethod; }
    public function setPaymentMethod(?string $paymentMethod): self { $this->paymentMethod = $paymentMethod; return $this; }
    public function getPaymentTerm(): ?string { return $this->paymentTerm; }
    public function setPaymentTerm(?string $paymentTerm): self { $this->paymentTerm = $paymentTerm; return $this; }

    /** No setVersion(): Doctrine owns this column, same contract SalesOrder states. */
    public function getVersion(): int { return $this->version; }

    /** @return Collection<int, InvoiceLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(InvoiceLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setInvoice($this);
        }

        return $this;
    }

    /** Detaching the line is the delete: $lines is orphanRemoval. */
    public function removeLine(InvoiceLine $line): self
    {
        $this->lines->removeElement($line);

        return $this;
    }

    /** @return Collection<int, InvoiceAddress> */
    public function getAddresses(): Collection
    {
        return $this->invoiceAddresses;
    }

    protected function newAddress(string $type): AbstractDocumentAddress
    {
        $address = (new InvoiceAddress())->setType($type);
        $address->setInvoice($this);
        $this->invoiceAddresses->add($address);

        return $address;
    }
}
