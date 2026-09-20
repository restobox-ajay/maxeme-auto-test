<?php

declare(strict_types=1);

namespace ProcurementBundle\Enum;

/**
 * Where a vendor bill is in its life (#555) — the buy-side mirror of an invoice's two statuses,
 * folded into one column because the plan's schema says one column.
 *
 * That fold is worth being explicit about, because the sell side deliberately keeps them apart:
 * `InvoiceStatus` answers "have the goods gone" and `InvoicePaymentStatus` answers "has the money
 * arrived", and an invoice can be Completed and unpaid without contradiction. A bill has no
 * fulfilment of its own — the goods arriving is the *receipt's* business, and the three-way match
 * is where the two meet — so the only lifecycle a bill has left IS the money one, plus the two
 * exceptional states an AP clerk needs.
 *
 * So the six cases split the same way PurchaseOrderStatus's do:
 *
 *  - `Open`, `PartiallyPaid` and `Paid` are **derived** from `amountPaid` against `total` by
 *    VendorBillStatusDeriver. Nobody types them. There is no setStatus(), and the money figure is
 *    written by a named action that says who wrote it.
 *  - `Draft`, `Void` and `Disputed` are **decided**, by named actions with an actor and a timeline
 *    entry each. The deriver does not touch a bill in any of them: a disputed bill that gets paid
 *    in part is still disputed, and deriving it back to Partially Paid would quietly drop the
 *    dispute.
 *
 * Vendor payments and AP aging are explicitly out of scope for #555, so `amountPaid` is a figure
 * an admin records rather than a sum over payment rows. That is the one place this mirror is
 * shallower than the sell side, and it is where the AP payments job will deepen it — at which
 * point the derivation already exists and only its input changes.
 */
enum VendorBillStatus: string
{
    /** Entered but not approved. Owes nothing and appears in no payables total. */
    case Draft = 'Draft';

    /** Approved and payable. Nothing has been paid against it. Derived. */
    case Open = 'Open';

    /** Something has been paid, but not the whole total. Derived. */
    case PartiallyPaid = 'Partially Paid';

    /** Paid in full — including a bill for nothing at all, which is covered by no payment. Derived. */
    case Paid = 'Paid';

    /** Withdrawn. A bill entered in error, or a duplicate caught before it was paid. Terminal. */
    case Void = 'Void';

    /**
     * We are not paying this until somebody explains it. The state a three-way match exception
     * gets parked in, and the reason the deriver keeps its hands off: a part payment against a
     * disputed bill does not settle the dispute.
     */
    case Disputed = 'Disputed';

    /** Is this bill still owed — the question a payables total asks. */
    public function isPayable(): bool
    {
        return $this === self::Open || $this === self::PartiallyPaid || $this === self::Disputed;
    }

    /** The three states the deriver is allowed to move between. Everything else it leaves alone. */
    public function isDerivable(): bool
    {
        return $this === self::Open || $this === self::PartiallyPaid || $this === self::Paid;
    }

    /** Does this bill count as a charge against the business at all. */
    public function counts(): bool
    {
        return $this !== self::Draft && $this !== self::Void;
    }

    /**
     * The states {@see self::counts()} answers FALSE for, as a list a query can bind to `NOT IN`.
     *
     * Derived from `counts()` by enumerating the cases rather than typed out, so there is exactly
     * one statement in this application of which bills are a charge against the business, and a
     * seventh case added tomorrow cannot start or stop being excluded by accident.
     *
     * It exists because the rule is asked in two shapes and only one of them can call a method on
     * an instance. `ApAgingReport` and `ExceptionController` both need Draft and Void as VALUES to
     * bind to a DQL `NOT IN`, while a template holding one bill in its hand needs the predicate —
     * and a screen that states a scope the query behind it does not have is exactly the defect this
     * was extracted for. Before this the report open-coded the filter and the exception worklist
     * spelled `[Draft, Void]` out by hand, which is two copies of one rule plus a template guessing
     * at a third.
     *
     * @return list<self>
     */
    public static function uncounted(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $status): bool => !$status->counts(),
        ));
    }

    /**
     * May a bill in this state have money recorded against it, or moved onto it (queue item 34)?
     *
     * Every state but **Void**, which is the rule `VendorBill::recordPayment()` has always enforced
     * inline — stated here now because the MOVE path needs the same answer and a rule asked in two
     * places is a rule that drifts. A void bill is withdrawn: it is owed nothing, so there is
     * nothing for a payment to settle.
     *
     * ## Draft is IN, and the sell side's draft is OUT — an asymmetry that stays
     *
     * `InvoiceStatus::acceptsPayment()` excludes a draft invoice, and this deliberately does not
     * exclude a draft bill. The reasoning does not transfer, in both directions:
     *
     *  - an invoice carries a SEPARATE derived payment-status column, so a draft invoice holding
     *    payments displays itself as Paid while being a document no customer has seen. A bill folds
     *    both into one column and {@see self::isDerivable()} refuses to derive a Draft at all, so a
     *    draft bill holding payments still reads Draft. It cannot contradict itself the same way;
     *  - a draft invoice is inert — it claims nothing and appears on nothing. A draft bill is not:
     *    {@see self::claimsOrderedQuantity()} already counts it against its purchase order lines,
     *    and the match and exception screens already show it. The buy side has never had an inert
     *    draft to reason from.
     *
     * Same word, two objects. See {@see self::counts()} and {@see self::claimsOrderedQuantity()}
     * below for the other place this document's states refuse to collapse into one predicate.
     */
    public function acceptsPayment(): bool
    {
        return $this !== self::Void;
    }

    /**
     * Does this bill hold quantity against the purchase order lines it charges (#658).
     *
     * Every state but Void, and deliberately not the same rule as counts() above. The two answer
     * different questions and disagree about exactly one case, the draft:
     *
     *  - counts() asks *is this a charge against the business* — money at stake. A draft is not: it
     *    has authorised nothing, and the accrual screen must keep reporting goods as unbilled while
     *    somebody is part way through typing the bill for them.
     *  - this asks *is this a claim on the purchase order line* — room left. A draft is: it names a
     *    quantity against a line, and two drafts each naming the whole line are two bills that both
     *    approve. A guard blind to drafts catches the oversized single bill and misses the second
     *    one, which is the case that actually happens.
     *
     * A void bill is withdrawn, charges nothing and claims nothing, so it is out of both.
     */
    public function claimsOrderedQuantity(): bool
    {
        return $this !== self::Void;
    }

    /**
     * May a bill in this state still be edited (`HasStatus::canEditOnStatus()`)?
     *
     * Draft only — the buy-side counterpart is inverted from the sell side's shape (there, every
     * status but two terminal ones is editable; here, only the one starting status is). An approved
     * bill is an authorisation for money to leave, and editing the figures behind it afterwards
     * would make the authorisation refer to something that no longer exists: dispute it, or void it
     * and enter another.
     */
    public function allowsEditing(): bool
    {
        return $this === self::Draft;
    }
}
