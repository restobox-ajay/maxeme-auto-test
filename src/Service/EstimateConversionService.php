<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\Inventory\AdminOrderStockValidator;
use App\Service\Inventory\BackorderSplitResolver;
use App\Entity\Estimate;
use App\Enum\InvoiceIssueIntent;
use App\Enum\QuoteConversionInvoicing;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Estimate → SalesOrder is a copy, not an in-place conversion: they're separate
 * tables (mapped-superclass leaves), so accepting an Estimate creates a brand new
 * SalesOrder with its own order number, cross-linked back via Estimate::$convertedOrder.
 * The Estimate itself is left as accepted/locked history, never edited again.
 *
 * ## Acceptance and conversion are no longer the same moment
 *
 * They still are on the CUSTOMER side: one click takes a Priced quote to Accepted, mints the order
 * and raises its invoice, all in one transaction. On the ADMIN side they are separate deliberate
 * steps — Accept marks the quote Accepted and creates nothing, Create Sales Order converts it, and
 * invoicing is a third action taken against the order. So this service accepts a quote that is
 * Priced (converted the instant it was accepted) OR one that is already Accepted and has no order
 * behind it yet, and the caller says whether an invoice comes with it.
 *
 * Converting is once-only and enforced as such in the database, not just in memory — see
 * claimForConversion(). Callers still have to provide the transaction: convert() persists but never
 * flushes, so the order, its lines, the invoice raised against it (#539), the estimate's status
 * change and the reservation the flush triggers all commit (or roll back) together under the
 * caller's control.
 */
final class EstimateConversionService
{
    /** Log author when the conversion was not driven by a signed-in user (matches OrderController's default). */
    private const SYSTEM_ACTOR = 'System';

    public function __construct(
        private readonly OrderNumberGenerator $orderNumberGenerator,
        private readonly AdminOrderStockValidator $stockValidator,
        private readonly BackorderSplitResolver $backorderSplits,
        private readonly OrderInvoicingService $orderInvoicing,
    ) {
    }

    /**
     * $actorName is only used to author the two log entries this writes; the service has no security
     * context of its own, so the caller supplies whatever display name it already knows.
     *
     * $invoicing says whether the order this mints is also billed. It defaults to RaiseInvoice, the
     * behaviour every caller had before the admin side split acceptance from conversion, so a caller
     * that never thought about invoicing keeps getting the invoice it always got.
     */
    public function convert(
        Estimate $estimate,
        EntityManagerInterface $entityManager,
        ?string $actorName = null,
        QuoteConversionInvoicing $invoicing = QuoteConversionInvoicing::RaiseInvoice,
    ): SalesOrder {
        // Priced is the customer's path — accepted and converted in the same request. Accepted is
        // the admin's — the quote was marked Accepted earlier and the order is being raised now.
        // Everything else (Draft, Submitted, Rejected) has no business becoming an order, and an
        // Accepted quote that ALREADY has an order is caught by the convertedOrder guard below
        // rather than here, so the caller gets the "already converted" exception it can forward on.
        if (!$estimate->isStatus('Priced') && !$estimate->isStatus('Accepted')) {
            throw new \LogicException('Only a Priced or Accepted estimate can be converted to an order.');
        }
        if (!$estimate->isFullyPriced()) {
            throw new \LogicException('Cannot convert an estimate that is not fully priced.');
        }
        // Cheap in-memory rejection of the ordinary double-submit, and the reason this guard lives
        // here rather than only in the caller: every entry point gets it, not just the accept
        // action. It is deliberately not the whole story — two requests that both read the estimate
        // while it was still convertible would both sail past it, which is what the claim below
        // settles.
        if ($estimate->getConvertedOrder() !== null) {
            throw EstimateAlreadyConvertedException::forEstimate($estimate);
        }

        $this->claimForConversion($estimate, $entityManager);

        $order = new SalesOrder();
        $order->setCompany($estimate->getCompany());
        // Take the estimate's frozen identity rather than the live company's, for the same reason
        // the addresses are copied below: a quote priced for one legal entity must not convert into
        // an order naming another. setCompany() above seeded it from the live company; this
        // overwrites that with what was actually quoted.
        $order->copyCompanySnapshotFrom($estimate);
        $order->setOrderNumber($this->orderNumberGenerator->next($entityManager));
        // No status assignment: a new order IS a Draft, and the gate call below is what accepts it.
        $order->setSource($estimate->getSource());
        $order->setUserName($estimate->getUserName());
        $order->setPoNumber($estimate->getPoNumber());
        $order->setDocumentDate($estimate->getDocumentDate());

        // The order inherits the estimate's FROZEN addresses, and must not re-resolve them from the
        // company address book. The team prices a quote against a specific delivery address; if the
        // customer edits that address between quote and acceptance, re-resolving would quote one
        // location and ship to another. Copying the snapshot also carries the contact names, so the
        // three separate name assignments this replaces are no longer needed.
        foreach ($estimate->getAddresses() as $estimateAddress) {
            $order->copyAddressFrom($estimateAddress);
        }
        $order->setSpecialInstructions($estimate->getSpecialInstructions());
        $order->setShippingMethod($estimate->getShippingMethod());
        $order->setFulfillmentRegion($estimate->getFulfillmentRegion());
        // The shipping rows travel inside fee_lines with everything else the quote charged, so
        // there is no separate shipping copy here that could disagree with the rows.
        $order->setFeeLines($estimate->getFeeLines());
        $order->setTaxLines($estimate->getTaxLines());
        $order->setSubtotal((string) $estimate->getSubtotal());
        $order->setTax((string) $estimate->getTax());
        $order->setTotal((string) $estimate->getTotal());

        foreach ($estimate->getLines() as $line) {
            $orderLine = (new SalesOrderLine())
                ->setProduct($line->getProduct())
                ->setName($line->getName())
                ->setLocation($line->getLocation())
                ->setSku($line->getSku())
                ->setQuantity($line->getQuantity())
                ->setWeight($line->getWeight())
                ->setUnit($line->getUnit())
                ->setTaxCode($line->getTaxCode())
                ->setCost($line->getCost())
                ->setPrice((string) $line->getPrice())
                ->setSubtotal((string) $line->getSubtotal())
                // The quote's row order is part of what the customer accepted, so the order it
                // becomes keeps it rather than reverting to whatever id order the copy produces.
                ->setSortOrder($line->getSortOrder());
            $order->addLine($orderLine);
        }

        $estimate->setConvertedOrder($order);

        $entityManager->persist($order);

        // The acceptance is never refused, but the order it produces may not be fillable.
        //
        // Until now conversion minted a Pending order unconditionally, and Pending reserves: a
        // customer accepting a quote for 500 units against 5 in stock created a live order for 500
        // and drove availability to -495, which then made the SKU unbuyable for everyone else. That
        // is the #326 defect reaching production through the one path #326 did not cover, because
        // it never goes near the admin order form.
        //
        // Refusing the acceptance was the obvious alternative and is the wrong trade: the customer
        // has agreed to a price we quoted them, and telling them they cannot accept it — with no
        // way to resolve it themselves — is a worse failure than a slow order. So the acceptance
        // always succeeds and the quote becomes Accepted as normal; only the ORDER waits.
        //
        // Draft is the right resting place because it already means exactly this everywhere else in
        // the app: a document that reserves nothing and is not yet a commitment. It also lands the
        // decision in front of an admin at precisely the point AdminOrderStockValidator already
        // guards — promoting a Draft to a reserving status — so nothing new had to be invented to
        // stop the oversell happening later by hand.
        $shortfalls = $this->stockValidator->shortfallsForOrder($order, $entityManager);
        // An order held back for stock stays a Draft — it was never approved, so nothing about it is
        // owed and it reserves nothing. Everything else is approved here, through the one gate,
        // which is also what puts the acceptance on the order's timeline.
        if ($shortfalls === []) {
            $order->setStatus('Approved', $this->actorFrom($actorName), 'Order approved.');
        }

        // Which of the accepted units exist and which are a promise (#548). After the approval,
        // because a shortfall that held the order back leaves it a Draft — which has committed to
        // none of this and promises nothing — and before the invoice, which reads each line's
        // backordered quantity to decide how much of what it bills has stock behind it.
        //
        // shortfallsForOrder() above already excluded anything a backorder-enabled SKU can absorb
        // within its cap, so an order that reaches here approved with lines to split is exactly the
        // case the setting was turned on for.
        $this->backorderSplits->applyToOrderLines($order, $order->getStatus(), $entityManager);

        // WHETHER is the caller's ($invoicing). WHERE is not, and must not become the caller's: the
        // call stays here, inside this method, in this position.
        //
        // Raised here, not by the caller, and raised AFTER the shortfall check above has settled
        // whether the order is approved — a quote held back as a Draft must not produce a live
        // invoice for stock that is not there.
        //
        // And after backorderSplits->applyToOrderLines() above, because the invoice reads each
        // line's backordered quantity to decide how much of what it bills has stock behind it.
        //
        // Same transaction by construction: this persists and never flushes, exactly as the order
        // above does, so the caller's transaction commits the pair or neither (#539 stage 1).
        //
        // Skipping it changes none of that. NoInvoice leaves the order uninvoiced for someone to
        // bill later through admin_invoice_create (with order_id), which applies its own approved-only rule; it
        // does not move the invoice anywhere or defer it inside this method.
        if ($invoicing === QuoteConversionInvoicing::RaiseInvoice) {
            $this->orderInvoicing->invoiceInFull(
                $order,
                $entityManager,
                $this->actorFrom($actorName),
                // The caller states the intent because the order's status no longer carries it
                // (see InvoiceIssueIntent). This caller mirrors the order it just built — approved
                // bills, held-as-Draft does not — which is exactly what intentForOrderStatus() is
                // for, and is why the shortfall check above has to have run first.
                OrderInvoicingService::intentForOrderStatus($order->getStatusEnum()),
            );
        }

        $this->copyEstimateLogsToOrder($estimate, $order, $entityManager);

        // The acceptance itself, through the seam's one door — checked against the 'estimate'
        // vocabulary (Priced -> Accepted, and Accepted -> Accepted as a silent no-op for the admin
        // path that was already Accepted before this ran) and signed by the actor this method was
        // handed. It used to be written beside setConvertedOrder() above, with nothing recording it
        // at all.
        //
        // AFTER the copy, deliberately. copyEstimateLogsToOrder() carries the quote's history onto
        // the order, and this row would otherwise land there too — one line above the "Created from
        // quote X." marker logConversion() writes next, saying the same thing twice on the same
        // timeline. The quote's own acceptance belongs to the quote.
        $estimate->setStatus('Accepted', $this->actorFrom($actorName), 'Estimate accepted and converted to an order.');

        $this->logConversion($estimate, $order, $actorName, $entityManager);

        if ($shortfalls !== []) {
            $this->logShortfall($estimate, $order, $shortfalls, $actorName, $entityManager);
        }

        return $order;
    }

    /**
     * The caller's display name as a DocumentActor.
     *
     * This service has no security context of its own — it is called from an admin controller, from
     * the customer portal and from tests — so it keeps taking whatever display name the caller
     * already knows, and wraps it for the named actions rather than resolving an ambient user that
     * may not exist. An empty name is the System actor, matching what the log entries below do.
     */
    private function actorFrom(?string $actorName): DocumentActor
    {
        $name = trim((string) $actorName);

        return $name === '' || $name === self::SYSTEM_ACTOR
            ? DocumentActor::system()
            : DocumentActor::named($name);
    }

    /**
     * The quote's own history — customer questions, admin replies, status notes recorded while it
     * was still a quote — doesn't travel via Estimate::$convertedOrder alone; that link shows the
     * relationship now, it doesn't carry what was said before conversion. Without this, a converted
     * order's Activity Log shows only the "Created from quote" marker logConversion() writes below,
     * with no trace of what led up to it.
     */
    private function copyEstimateLogsToOrder(Estimate $estimate, SalesOrder $order, EntityManagerInterface $entityManager): void
    {
        // The estimate already has its own id by this point in conversion, so its own timeline
        // entries are already real audit_log rows (queueActivityLogEntry() only defers writing
        // until this SAME request's flush, and this method runs mid-request, after the estimate
        // that produced them was itself persisted and flushed earlier in the conversion). Read
        // them back rather than re-deriving them, the same actor_type = 'document' marker that
        // separates a narrative entry from a generic field-diff row.
        $rows = $entityManager->getConnection()->fetchAllAssociative(
            "SELECT actor_name, action, summary, recipient_notified FROM audit_log
             WHERE entity_type = 'Estimate' AND entity_id = ? AND actor_type = 'document' ORDER BY id ASC",
            [$estimate->getId()],
        );

        foreach ($rows as $row) {
            $order->queueActivityLogEntry()
                ->setUserName((string) $row['actor_name'])
                ->setComment((string) $row['summary'])
                ->setType((string) $row['action'])
                ->setRecipientNotified((bool) $row['recipient_notified']);
        }
    }

    /**
     * Why this order is a Draft, written onto both documents.
     *
     * The email is best-effort and sent outside the transaction; these entries are neither. An admin
     * opening the order months later has to be able to see why it never went live without depending
     * on a message that may not have arrived.
     *
     * @param list<array{product: \App\Entity\ProductCore, warehouse: \App\Entity\Warehouse, regionName: string, requested: int, available: int, missing: int}> $shortfalls
     */
    private function logShortfall(Estimate $estimate, SalesOrder $order, array $shortfalls, ?string $actorName, EntityManagerInterface $entityManager): void
    {
        $userName = trim((string) $actorName) ?: self::SYSTEM_ACTOR;

        $detail = implode('; ', array_map(
            static fn (array $row): string => sprintf(
                '%s in %s: %d needed, %d available, short %d',
                $row['product']->getName(),
                $row['regionName'],
                $row['requested'],
                $row['available'],
                $row['missing'],
            ),
            $shortfalls,
        ));

        $comment = sprintf(
            'Held as Draft — not enough stock to fulfil. %s. Review and promote once stock is available.',
            $detail,
        );

        $order->queueActivityLogEntry()
            ->setUserName($userName)
            ->setComment($comment)
            ->setType('System');

        $estimate->queueActivityLogEntry()
            ->setUserName($userName)
            ->setComment(sprintf(
                'Order %s was held as Draft — not enough stock to fulfil. %s.',
                $order->getOrderNumber(),
                $detail,
            ))
            ->setType('System');
    }

    /**
     * Atomically claims the estimate for this conversion: a single UPDATE that moves it to Accepted
     * only while it is still convertible AND still has no converted order. Whichever of two
     * simultaneous conversions the database serves first changes one row; every later one changes
     * none and is refused here — before an order exists to be orphaned, and before any stock is
     * reserved for it.
     *
     * ## Why the WHERE has two clauses and which one does the work
     *
     * `converted_order_id IS NULL` is the load-bearing one, and it is the only one that still works
     * now that acceptance and conversion can be separate steps.
     *
     * Converting a PRICED quote (the customer's accept) is refused a second time by either clause:
     * the winner's commit leaves the row Accepted with an order on it, so the loser matches neither.
     *
     * Converting an ALREADY-Accepted quote (the admin's Create Sales Order) sets status to the value
     * it already holds, so the status clause cannot distinguish the two requests — only the order id
     * can. That still serialises correctly: this UPDATE is the first write of the caller's
     * transaction, so a second request's copy of it blocks on the write lock until the first
     * commits, and then reads a row whose converted_order_id is set and matches nothing.
     *
     * A read-then-write check cannot do this: both requests read the row before either writes, which
     * is exactly the race. Nor would a unique index on converted_order_id help — the two requests
     * write the *same* estimate row, and no uniqueness rule on that column objects to that. A
     * SELECT ... FOR UPDATE locking read would work on MySQL but not on the SQLite this app also
     * runs on; a conditional UPDATE is atomic on both and needs no schema change.
     *
     * The entity's own status is set the usual way further up, so the UnitOfWork's later UPDATE
     * writes the value this one already wrote rather than disagreeing with the row.
     */
    private function claimForConversion(Estimate $estimate, EntityManagerInterface $entityManager): void
    {
        $estimateId = $estimate->getId();
        if ($estimateId === null) {
            // Nothing is stored yet, so there is no row to claim — and equally no second request
            // that could be holding one. The in-memory guards above are the whole guard here.
            return;
        }

        $metadata = $entityManager->getClassMetadata(Estimate::class);
        $claimed = (int) $entityManager->getConnection()->executeStatement(
            sprintf(
                'UPDATE %s SET %s = ? WHERE %s = ? AND %s IN (?, ?) AND %s IS NULL',
                $metadata->getTableName(),
                $metadata->getColumnName('status'),
                $metadata->getSingleIdentifierColumnName(),
                $metadata->getColumnName('status'),
                $metadata->getSingleAssociationJoinColumnName('convertedOrder'),
            ),
            [
                'Accepted',
                $estimateId,
                'Priced',
                'Accepted',
            ],
        );

        if ($claimed !== 1) {
            throw EstimateAlreadyConvertedException::forEstimate($estimate);
        }
    }

    /**
     * The conversion is the most consequential transition either document undergoes and used to
     * leave no trace on either one — a converted order's history tab opened completely empty, with
     * nothing saying it came from a quote at all. Both entries are System/not-notified, matching
     * the "Order %s created by %s." entry a natively-created order writes.
     *
     * Written from the service rather than from the caller so the two entries can't drift apart,
     * and because the order number they both quote is assigned inside convert().
     */
    private function logConversion(Estimate $estimate, SalesOrder $order, ?string $actorName, EntityManagerInterface $entityManager): void
    {
        $userName = trim((string) $actorName);
        if ($userName === '') {
            $userName = self::SYSTEM_ACTOR;
        }

        $estimate->queueActivityLogEntry()
            ->setUserName($userName)
            ->setComment(sprintf('Quote accepted; converted to order %s.', $order->getOrderNumber()))
            ->setType('System');

        $order->queueActivityLogEntry()
            ->setUserName($userName)
            ->setComment(sprintf('Created from quote %s.', $estimate->getDocumentNumber()))
            ->setType('System');
    }
}
