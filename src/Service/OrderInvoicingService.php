<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceIssueIntent;
use App\Enum\SalesOrderStatus;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Raises invoices against a sales order (#539).
 *
 * Stage 1 gave this one job: every order-creation path mints a 1:1 shadow invoice, so the invariant
 * "every order has at least one invoice" is true in every environment. Stage 2 adds the other half
 * — Convert to Invoice, which raises an invoice for part of an order — and both go through here,
 * because the header copy is thirty-odd fields plus lines plus addresses and two transcriptions of
 * it is how an invoice ends up disagreeing with the order it bills.
 *
 * ## Snapshots, not live reads
 *
 * The same rule EstimateConversionService follows, and for the same reason: the company identity and
 * the addresses are copied from the ORDER's frozen snapshots via copyCompanySnapshotFrom() and
 * copyAddressFrom(), never re-resolved from the live Company or the address book. An invoice is the
 * record of who was billed and where the goods went; re-reading either would let that record change
 * after the fact, and would let the two documents disagree about the same shipment.
 *
 * The same principle is why nothing here re-runs the fee or tax calculators. See
 * chargesAndTaxFor() for what that means for a partial invoice.
 *
 * ## Call it after the order has been persisted
 *
 * SalesDocumentDateStamp fills a blank document_date at prePersist, so an order that had no date
 * typed on it only has one once persist() has been called. This copies that date across, so calling
 * it first would copy a blank — and invoice.document_date is NOT NULL, exactly like the other two.
 *
 * Neither method flushes: the caller owns the transaction, so the order, its invoice and whatever
 * else the request writes commit or roll back together.
 */
final class OrderInvoicingService
{
    public function __construct(
        private readonly InvoiceNumberGenerator $invoiceNumberGenerator,
        private readonly OrderTaxBreakdownService $taxBreakdown,
    ) {
    }

    /**
     * The whole order, on one invoice — the 1:1 shadow every creation path raises.
     *
     * Refuses an order that already has a live invoice. Invoicing in full twice would bill the same
     * goods twice, which is true in stage 1 (where it would also break the 1:1 invariant) and stays
     * true afterwards, so this is a permanent rule rather than a stage-1 scaffold. Cancelled and
     * draft invoices do not count, for the reason SalesOrder::getCountingInvoices() states.
     */
    public function invoiceInFull(
        SalesOrder $order,
        EntityManagerInterface $entityManager,
        DocumentActor $actor,
        InvoiceIssueIntent $intent,
    ): Invoice {
        if ($order->getCountingInvoices() !== []) {
            throw new \DomainException(sprintf(
                'Order %s already has a live invoice and cannot be invoiced in full again.',
                $order->getOrderNumber(),
            ));
        }

        $requested = [];
        foreach ($order->getLines() as $orderLine) {
            $requested[] = ['line' => $orderLine, 'quantity' => $orderLine->getQuantity(), 'price' => null];
        }

        // Every charge row too, at its whole quantity — this invoice IS the order, so it carries
        // what the order charges (#539 stage 5). Nothing counts as invoiced here (the guard above
        // saw to that), so the remainder of each charge IS the whole of it, which is what lets
        // billsTheWholeOrder() recognise this case and hand the order's own snapshot across verbatim.
        return $this->raise($order, $requested, $this->keptCharges($order, null), $entityManager, $actor, $intent);
    }

    /**
     * Convert to Invoice: an invoice for some or all of what is still uninvoiced.
     *
     * $requestedLines is what the create-invoice screen posted, one row per order line the admin
     * kept, already resolved to entities:
     *
     *   list<array{line: SalesOrderLine, quantity: string, price: ?string}>
     *
     * A null price means "whatever the order charged", which is the pre-filled default; a non-null
     * one is an override the admin typed, because prices are editable at invoice level.
     *
     * $requestedCharges is the same thing for the order's CHARGE rows (#539 stage 5), keyed by the
     * slug that identifies each one:
     *
     *   list<array{slug: string, quantity: string, amount: ?string}>
     *
     * A charge is a quantified row like any other and the admin says how much of it this invoice
     * bills. Null means "not offered" and takes the remainder of every charge, which is exactly what
     * the screen pre-fills; an empty list means the admin cleared them all and this invoice bills no
     * charges at all. Those two are deliberately different: a caller that never asked and an admin
     * who answered "none" are not the same answer.
     *
     * Refuses an order that is not approved, and refuses a line or charge quantity above what is
     * left to invoice — the rules settled with the client. Rows of zero quantity are dropped rather
     * than refused: the screen pre-fills every line, and clearing one is how an admin says "not this
     * time", not an error.
     *
     * @param list<array{line: SalesOrderLine, quantity: string, price: ?string}>  $requestedLines
     * @param ?list<array{slug: string, quantity: string, amount: ?string}>        $requestedCharges
     */
    public function invoiceFromOrder(
        SalesOrder $order,
        array $requestedLines,
        EntityManagerInterface $entityManager,
        DocumentActor $actor,
        InvoiceIssueIntent $intent,
        ?array $requestedCharges = null,
    ): Invoice {
        $status = $order->getStatusEnum();
        if ($status === null || !$status->isApprovedOrLater()) {
            throw new \DomainException(sprintf(
                'Order %s is %s. Approve it before invoicing against it.',
                $order->getOrderNumber(),
                $order->getStatus(),
            ));
        }

        $kept = [];
        foreach ($requestedLines as $row) {
            // A line belonging to another order has no remainder ON THIS ONE, but
            // uninvoicedQuantityFor() would happily quote its own ordered quantity back — it reads
            // the line, and the line does not know it is a stranger here. So the check is explicit:
            // billing another order's goods must be refused, not merely unlikely to be requested.
            if ($row['line']->getOrder() !== $order) {
                throw new \DomainException(sprintf(
                    '%s is not a line on order %s.',
                    $row['line']->getName(),
                    $order->getOrderNumber(),
                ));
            }

            $quantity = (float) $row['quantity'];
            if ($quantity <= 0.0) {
                continue;
            }

            $remaining = (float) $order->uninvoicedQuantityFor($row['line']);
            if ($quantity > $remaining) {
                throw new \DomainException(sprintf(
                    '%s: %s requested but only %s left to invoice on order %s.',
                    $row['line']->getName(),
                    rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.'),
                    rtrim(rtrim($order->uninvoicedQuantityFor($row['line']), '0'), '.'),
                    $order->getOrderNumber(),
                ));
            }

            $kept[] = $row;
        }

        $charges = $this->keptCharges($order, $requestedCharges);

        // A charge-only invoice is a real document: an order whose goods are all billed can still owe
        // the freight, and refusing it would leave that charge permanently unbillable and the order
        // permanently short of Invoiced.
        if ($kept === [] && $charges === []) {
            throw new \DomainException(sprintf('Nothing left to invoice on order %s.', $order->getOrderNumber()));
        }

        $invoice = $this->raise($order, $kept, $charges, $entityManager, $actor, $intent);

        // On the ORDER's timeline, because a partial invoice is an event in the order's life and the
        // order page is where someone looks to find out what has been billed so far. The invoice's
        // own creation entry is written by raise().
        $order->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf('Invoice %s raised against this order.', $invoice->getDocumentNumber()))
            ->setType('System');

        return $invoice;
    }

    /**
     * The shared body: header copy, addresses, lines, charges, numbering, and the issuing action.
     *
     * @param list<array{line: SalesOrderLine, quantity: string, price: ?string}> $requestedLines
     * @param list<array{row: FeeLine, quantity: float, amount: float}>           $charges
     */
    private function raise(
        SalesOrder $order,
        array $requestedLines,
        array $charges,
        EntityManagerInterface $entityManager,
        DocumentActor $actor,
        InvoiceIssueIntent $intent,
    ): Invoice {
        $invoice = new Invoice();
        // Both sides linked up front so the order can see its own invoice within the same request —
        // every quantity check in this file reads getCountingInvoices(), which is only a guard if the
        // collection is actually populated.
        $order->addInvoice($invoice);

        $invoice->setCompany($order->getCompany());
        // setCompany() seeded the identity from the LIVE company; this overwrites it with what the
        // order was actually raised against. Same two-step, same reason, as the estimate → order copy.
        $invoice->copyCompanySnapshotFrom($order);
        $invoice->setDocumentNumber($this->invoiceNumberGenerator->next($entityManager));

        $invoice
            ->setSource($order->getSource())
            ->setUserName($order->getUserName())
            ->setPoNumber($order->getPoNumber())
            ->setDocumentDate($order->getDocumentDate())
            ->setSpecialInstructions($order->getSpecialInstructions())
            ->setShippingMethod($order->getShippingMethod())
            ->setFulfillmentRegion($order->getFulfillmentRegion());

        // The terms of the sale, copied from the order that agreed them. Payment STATUS is not
        // copied and could not be: since stage 4 it is derived from this invoice's own payment rows,
        // and a new invoice has none — it is born Not Paid and stays there until money arrives.
        $invoice
            ->setPaymentMethod($order->getPaymentMethod())
            ->setPaymentTerm($order->getPaymentTerm())
            // The accounting date. The order's own date, since #539 stage 6 dropped
            // sales_order.invoice_date — this used to read COALESCE(that, document_date), and with
            // the column gone the second term is the whole expression. Nothing is lost: the column
            // could only ever have named one of an order's invoice dates, and this one belongs to
            // this invoice.
            ->setInvoiceDate($order->getDocumentDate());

        foreach ($order->getAddresses() as $orderAddress) {
            $invoice->copyAddressFrom($orderAddress);
        }

        $subtotal = 0.0;
        foreach ($requestedLines as $row) {
            $line = $this->lineFrom($row['line'], $row['quantity'], $row['price']);
            $invoice->addLine($line);
            $subtotal += (float) $line->getSubtotal();
        }

        $this->chargesAndTaxFor($invoice, $order, $requestedLines, $charges, $subtotal, $actor);

        $this->applyIssueIntent($invoice, $actor, $intent);

        $entityManager->persist($invoice);

        return $invoice;
    }

    /**
     * Charges, tax and coupons.
     *
     * An invoice that bills the WHOLE order takes the order's encoded snapshots verbatim — nothing
     * here re-runs the fee or tax calculators, for the same reason nothing re-reads the live
     * company: a config change between the order and the invoice must not silently re-price the
     * record of what was sold.
     *
     * A PART-invoice used to carry no charges at all and say so on its timeline, because splitting a
     * fee snapshot would have needed an apportionment rule nobody had settled. #539 stage 5 settles
     * it, and not by apportioning: a charge is a quantified row like any other, and the person
     * raising the invoice says how much of it this one bills. The rows are rebuilt at the requested
     * quantity, the remainder stays on the order, and the only thing enforced is that the parts add
     * back up. See keptCharges().
     *
     * TAX is still not recomputed for a part-invoice, and that is a gap rather than a decision:
     * tax_lines carries a rate per line, so it could be re-applied to this invoice's own taxable
     * base, but doing so is a tax calculation and belongs with the tax code rather than here. The
     * invoice's timeline says so, where an admin will see it and can add a tax row.
     *
     * Public: InvoiceController's own unified save path calls this directly on an Invoice it built
     * itself, for the same reason it calls keptCharges() directly — reusing the tested drawdown
     * logic rather than a second copy of it.
     *
     * @param list<array{line: SalesOrderLine, quantity: string, price: ?string}> $requestedLines
     * @param list<array{row: FeeLine, quantity: float, amount: float}>           $charges
     */
    public function chargesAndTaxFor(
        Invoice $invoice,
        SalesOrder $order,
        array $requestedLines,
        array $charges,
        float $subtotal,
        DocumentActor $actor,
    ): void {
        $invoice->setSubtotal(number_format($subtotal, 2, '.', ''));

        if ($this->billsTheWholeOrder($order, $requestedLines, $charges)) {
            $invoice
                ->setFeeLines($order->getFeeLines())
                ->setTaxLines($order->getTaxLines())
                ->setCouponCodes($order->getCouponCodes())
                ->setSubtotal($order->getSubtotal())
                ->setTax($order->getTax())
                ->setTotal($order->getTotal());

            return;
        }

        $chargeLines = [];
        $chargeTotal = 0.0;
        foreach ($charges as $charge) {
            $chargeLines[] = $charge['row']->withQuantityAndAmount($charge['quantity'], $charge['amount']);
            $chargeTotal += $charge['amount'];
        }

        $invoice->setFeeLines(FeeLineSnapshot::encode($chargeLines));

        // The invoice computes its own tax, through the same service and the same call the order
        // form makes — OrderTaxBreakdownService::computeBreakdownFor() is typed to
        // AbstractSalesDocument, so an invoice is as valid an argument as an order. Nothing here
        // re-implements a rate, apportions the order's tax figure, or knows what a tax code means.
        //
        // Reading the invoice's own lines is also what makes this correct rather than merely
        // present: an instalment is taxed on what it bills, at the rates in force on the day it is
        // raised, which is the answer an auditor expects and the same answer the order would give
        // for those rows.
        $breakdown = $this->taxBreakdown->computeBreakdownFor($invoice, $chargeLines);

        // Carried at scale, not snapped to the cent, for the reason the order form states: tax is
        // an intermediate on the way to the total, and rounding before adding is a second rounding
        // point (#257). The stored column is still to the cent.
        $tax = SalesDocumentMoney::intermediate($breakdown['total']);

        $invoice
            ->setTaxLines($this->taxBreakdown->toJson($breakdown))
            ->setTax(number_format($tax, 2, '.', ''))
            ->setTotal(number_format($subtotal + $tax + $chargeTotal, 2, '.', ''));

        $invoice->queueActivityLogEntry()
            ->setUserName($actor->displayName)
            ->setComment(sprintf(
                'Part-invoice of order %s. %s',
                $order->getOrderNumber(),
                $chargeLines === []
                    ? 'No charges are billed on it.'
                    : sprintf('%d charge row(s) billed at the quantities entered.', count($chargeLines)),
            ))
            ->setType('System');
    }

    /**
     * The order's charge rows the admin asked to bill, resolved and checked (#539 stage 5).
     *
     * A null request is "the screen did not ask", and takes the remainder of every charge — which is
     * exactly what the screen pre-fills, so a caller with no opinion gets the same answer an admin
     * who pressed straight through would. An empty list is "bill no charges", which is a different
     * answer and is honoured as one.
     *
     * A slug the order does not carry is skipped rather than reported: the screen only ever renders
     * this order's own charge rows, so such a row is a forged post and not something to correct. A
     * quantity above the remainder IS reported, because that is the rule the whole thing exists to
     * enforce, and an admin typing 2 into a row with 0.6 left needs telling.
     *
     * Two rows sharing a slug are billed as one row of the combined quantity and amount — see
     * AbstractSalesDocument::getChargeSlugs() for why a slug is the identity here.
     *
     * Public for the same reason chargesAndTaxFor() is.
     *
     * @param ?list<array{slug: string, quantity: string, amount: ?string}> $requested
     *
     * @return list<array{row: FeeLine, quantity: float, amount: float}>
     */
    public function keptCharges(SalesOrder $order, ?array $requested): array
    {
        $requested ??= $this->remainingChargeRequest($order);

        $kept = [];
        foreach ($requested as $row) {
            $slug = (string) ($row['slug'] ?? '');
            $orderRows = $order->chargeRowsFor($slug);
            if ($orderRows === []) {
                continue;
            }

            $quantity = round((float) ($row['quantity'] ?? '0'), 2);
            if ($quantity <= 0.0) {
                continue;
            }

            $remaining = (float) $order->uninvoicedChargeQuantityFor($slug);
            // Slack of a hundredth of a cent, because both sides are two-decimal strings read back
            // as floats and 0.6 - 0.2 is not 0.4 in binary floating point.
            if ($quantity > $remaining + 0.0001) {
                throw new \DomainException(sprintf(
                    '%s: %s requested but only %s of that charge is left to invoice on order %s.',
                    $orderRows[0]->label,
                    self::trimmed($quantity),
                    self::trimmed($remaining),
                    $order->getOrderNumber(),
                ));
            }

            $amountRaw = $row['amount'] ?? null;
            $amount = $amountRaw === null || trim((string) $amountRaw) === ''
                ? round($this->unitChargeAmount($order, $slug) * $quantity, 2)
                : round((float) $amountRaw, 2);

            $kept[] = ['row' => $orderRows[0], 'quantity' => $quantity, 'amount' => $amount];
        }

        return $kept;
    }

    /**
     * Every charge on the order, at what is left of it — the default the screen pre-fills.
     *
     * @return list<array{slug: string, quantity: string, amount: null}>
     */
    private function remainingChargeRequest(SalesOrder $order): array
    {
        $request = [];
        foreach ($order->getChargeSlugs() as $slug) {
            $request[] = [
                'slug' => $slug,
                'quantity' => $order->uninvoicedChargeQuantityFor($slug),
                'amount' => null,
            ];
        }

        return $request;
    }

    /** What one whole unit of the order's charge under $slug costs. */
    private function unitChargeAmount(SalesOrder $order, string $slug): float
    {
        $quantity = (float) $order->chargeQuantityFor($slug);
        $amount = $order->chargeAmountFor($slug);

        return $quantity === 0.0 ? $amount : $amount / $quantity;
    }

    /** A quantity as a person would type it: 0.60 reads back as 0.6, and 4.00 as 4. */
    private static function trimmed(float $quantity): string
    {
        $formatted = number_format($quantity, 2, '.', '');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    /**
     * True when this invoice covers the order outright: nothing counted as invoiced before it, every
     * line goes on it at its full ordered quantity, and every charge at its full ordered quantity
     * and amount. That is the case the order's own charge and tax snapshot describes exactly, and it
     * is the case every stage 1 creation path produces.
     *
     * Charges joined the test in #539 stage 5. Without them, an admin who billed every line but
     * cleared the freight would still have been handed the order's shipping snapshot back —
     * charging the very thing they had just said not to charge.
     *
     * @param list<array{line: SalesOrderLine, quantity: string, price: ?string}> $requestedLines
     * @param list<array{row: FeeLine, quantity: float, amount: float}>           $charges
     */
    private function billsTheWholeOrder(SalesOrder $order, array $requestedLines, array $charges): bool
    {
        // The invoice being raised is already in the order's collection but is still Draft at this
        // point — applyIssueIntent() runs after this — so it never counts itself here. Anything else
        // counting means some quantity was billed before, and the order's own charge snapshot has
        // therefore already been carried by whichever invoice did it.
        if ($order->getCountingInvoices() !== []) {
            return false;
        }

        $requestedByLine = [];
        foreach ($requestedLines as $row) {
            $key = spl_object_id($row['line']);
            $requestedByLine[$key] = ($requestedByLine[$key] ?? 0.0) + (float) $row['quantity'];
        }

        foreach ($order->getLines() as $orderLine) {
            $requested = $requestedByLine[spl_object_id($orderLine)] ?? 0.0;
            if (abs($requested - (float) $orderLine->getQuantity()) > 0.001) {
                return false;
            }
        }

        $requestedByCharge = [];
        foreach ($charges as $charge) {
            $slug = $charge['row']->slug;
            $requestedByCharge[$slug] ??= ['quantity' => 0.0, 'amount' => 0.0];
            $requestedByCharge[$slug]['quantity'] += $charge['quantity'];
            $requestedByCharge[$slug]['amount'] += $charge['amount'];
        }

        foreach ($order->getChargeSlugs() as $slug) {
            $requested = $requestedByCharge[$slug] ?? ['quantity' => 0.0, 'amount' => 0.0];
            if (abs($requested['quantity'] - (float) $order->chargeQuantityFor($slug)) > 0.001) {
                return false;
            }
            // An amount the admin overrode is no longer what the order's snapshot says, so the
            // snapshot is no longer the right thing to hand across. Half a cent, since both figures
            // are already rounded to the cent.
            if (abs($requested['amount'] - $order->chargeAmountFor($slug)) > 0.005) {
                return false;
            }
        }

        return true;
    }

    /**
     * The order row this bills is recorded on the invoice row, so uninvoiced quantity is derived per
     * line rather than guessed by matching SKUs after the fact.
     */
    private function lineFrom(SalesOrderLine $orderLine, string $quantity, ?string $price): InvoiceLine
    {
        $unitPrice = $price ?? $orderLine->getPrice();
        // $quantity is, and stays, the BASE figure — it is what uninvoicedQuantityFor() is
        // denominated in and what the inventory layer will move. The invoice inherits the ORDER
        // line's UNIT so the two documents print the same denomination, and the entered figure is
        // derived from the billed base quantity rather than copied from the order: an invoice for
        // half the line bills 20 boxes of a 40-box order, not 40 (#659).
        $lineUnit = $orderLine->getUnitOfMeasure();
        $baseUnit = LineDenomination::baseUnitOf($orderLine->getProduct());

        $line = new InvoiceLine();
        if ($lineUnit === null) {
            $line->setQuantity(number_format((float) $quantity, 2, '.', ''));
        } else {
            $line->setEnteredQuantity(
                LineDenomination::toEnteredQuantity($quantity, $lineUnit, $baseUnit),
                $lineUnit,
                $baseUnit,
            );
        }

        return $line
            ->setSalesOrderLine($orderLine)
            ->setProduct($orderLine->getProduct())
            ->setName($orderLine->getName())
            ->setLocation($orderLine->getLocation())
            ->setSku($orderLine->getSku())
            ->setWeight($orderLine->getWeight())
            ->setUnit($orderLine->getUnit())
            ->setTaxCode($orderLine->getTaxCode())
            ->setCost($orderLine->getCost())
            ->setPrice($unitPrice)
            ->setSubtotal(number_format((float) $quantity * (float) $unitPrice, 2, '.', ''))
            ->setBatch($orderLine->getBatch())
            // Copied verbatim like $batch — fine on a draft/unsaved invoice, since nothing final has
            // happened yet. MandatoryCaptureGuard is what requires this to be real once the invoice
            // actually issues (2026-09-14 lot/serial/expiry plan, section 5).
            ->setLotId($orderLine->getLotId())
            ->setSerial($orderLine->getSerial())
            // The row order is part of what the customer agreed to, so the invoice prints the rows in
            // the same order the order does rather than in whatever order the copy produced.
            ->setSortOrder($orderLine->getSortOrder());
    }

    /**
     * Issues the invoice, through the named actions rather than by naming a status at the gate.
     * `issue()` and `issueAwaitingPayment()` both reach `setStatus()` themselves, and each carries
     * the intent this method is choosing between — which a bare target would throw away.
     *
     * Stage 1 inferred the intent from the order's status. Stage 2's SalesOrderStatus no longer says
     * anything about payment or fulfilment, so the caller states it instead — see InvoiceIssueIntent
     * for why that is the better shape regardless.
     */
    private function applyIssueIntent(Invoice $invoice, DocumentActor $actor, InvoiceIssueIntent $intent): void
    {
        match ($intent) {
            InvoiceIssueIntent::KeepDraft => null,
            InvoiceIssueIntent::Issue => $invoice->issue($actor),
            InvoiceIssueIntent::AwaitingPayment => $invoice->issueAwaitingPayment($actor),
        };
    }

    /**
     * The intent an order in $status implies, for the callers that mirror an order's own state onto
     * the invoice it raises rather than knowing something the order does not.
     *
     * A draft order raises a draft invoice; an approved one raises an issued invoice. Payment is
     * absent on purpose — nothing about an order says whether money has arrived, which is the whole
     * reason AwaitingPayment is the checkout's to pass and not this method's to guess.
     */
    public static function intentForOrderStatus(?SalesOrderStatus $status): InvoiceIssueIntent
    {
        return $status !== null && $status->isApprovedOrLater()
            ? InvoiceIssueIntent::Issue
            : InvoiceIssueIntent::KeepDraft;
    }
}
