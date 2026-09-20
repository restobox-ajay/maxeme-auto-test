<?php

declare(strict_types=1);

namespace ProcurementBundle\Match;

use App\Service\AppSettings;
use App\Service\QuantityScale;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Repository\VendorBillRepository;

/**
 * Ordered ↔ received ↔ charged (#555). The control that makes this worth building rather than
 * keeping a spreadsheet.
 *
 * A bill line that matches its PO line in quantity and price, and has been received, is approvable
 * without anyone thinking about it. Everything else needs a human, and the job of this service is
 * to surface exactly which rows those are and why:
 *
 * | Exception                | What it means                                            |
 * |--------------------------|----------------------------------------------------------|
 * | Billed but not received  | we are being asked to pay for goods we do not have       |
 * | Received but not billed  | an accrual — we owe money nobody has asked for yet       |
 * | Price variance           | the vendor charged more than they quoted                 |
 * | Quantity variance        | they over- or under-shipped against the order            |
 * | Not on the purchase order| a charge with nothing to match it to at all              |
 * | Received somewhere else  | the goods landed at a warehouse this bill does not name  |
 *
 * ## Where the goods landed is the fourth document, and it was never compared
 *
 * A bill carries `vendor_bill.warehouse_id`, and since queue item 32 that column is the ONLY thing
 * `vendor_bill.tax_province` is ever derived from. A `GoodsReceipt` carries its own warehouse,
 * because receiving records where the goods were actually put — `GoodsReceipt` says in its own
 * docblock that a receipt booked to the wrong warehouse is a real and expensive mistake, and that
 * it "is flagged on the three-way match rather than blocked at the door". Until this check, it was
 * not: this service compared quantity and price and nothing compared the two warehouses.
 *
 * That silence costs money rather than tidiness. A bill entered against a Surrey warehouse whose
 * goods were received in Calgary was taxed at BC rates — GST+PST — on goods that landed in Alberta,
 * where there is no PST. Nothing on the bill, the receipt or the match could contradict it, which is
 * the same shape queue items 32 and 37 removed from the province itself. The province is derived
 * correctly now; this is the check that the thing it is derived FROM is the right building.
 *
 * Document-level, not line-level, and deliberately: the warehouse is a fact about the whole bill,
 * exactly as "no purchase order" and "duplicate vendor invoice number" are, and it joins them on
 * `MatchReport` rather than being repeated onto every row.
 *
 * ## Tolerances start at zero
 *
 * Both default to `0`, deliberately, per the plan: **it is much easier to loosen a tolerance than
 * to discover what has been auto-approving itself for six months.** They are AppSettings values an
 * admin raises once the noise is understood, expressed as a percentage of the ordered figure.
 *
 * ## Quantity is compared against RECEIVED, price against ORDERED
 *
 * Not an inconsistency — the two questions are different. "Are they charging us for what turned up"
 * is about goods, and the receipt is the only record of what turned up. "Are they charging what
 * they quoted" is about the agreement, and the PO is the only record of that. Comparing the billed
 * quantity to the ordered quantity instead would clear a bill for 240 that was short-shipped at
 * 210, which is the single most expensive mistake this table exists to prevent.
 *
 * ## Received-but-not-billed is a report, not a line
 *
 * Within one bill it appears as a row with nothing charged against it. Across the business it is
 * the accrual list, which ProcurementExceptionController builds from the open PO lines directly —
 * the goods that arrived and that no bill has ever mentioned are, by definition, not on any bill to
 * be found from one.
 */
final class ThreeWayMatchService
{
    public const SETTING_QUANTITY_TOLERANCE = 'procurement_quantity_tolerance_percent';
    public const SETTING_PRICE_TOLERANCE = 'procurement_price_tolerance_percent';

    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly VendorBillRepository $bills,
    ) {
    }

    public function match(VendorBill $bill): MatchReport
    {
        $quantityTolerance = $this->tolerance(self::SETTING_QUANTITY_TOLERANCE);
        $priceTolerance = $this->tolerance(self::SETTING_PRICE_TOLERANCE);

        $order = $bill->getPurchaseOrder();
        $lines = [];

        /** @var array<int, true> $seenOrderLines */
        $seenOrderLines = [];

        foreach ($bill->getLines() as $billLine) {
            $line = $this->matchBillLine($billLine, $order, $quantityTolerance, $priceTolerance);
            $lines[] = $line;

            $orderLineId = $billLine->getPurchaseOrderLine()?->getId();
            if ($orderLineId !== null) {
                $seenOrderLines[$orderLineId] = true;
            }
        }

        // Rows on the order that this bill says nothing about, but which goods have arrived
        // against. Money we owe that nobody has asked for — an accrual, and the reason a period
        // close is not just "add up the bills".
        if ($order instanceof PurchaseOrder) {
            foreach ($order->getLines() as $orderLine) {
                if (isset($seenOrderLines[$orderLine->getId() ?? 0]) || !$orderLine->hasReceipts()) {
                    continue;
                }

                $lines[] = MatchLine::of(
                    null,
                    $orderLine,
                    $orderLine->getName(),
                    $orderLine->getQuantityOrdered(),
                    $orderLine->getQuantityReceived(),
                    '0.00',
                    $orderLine->getUnitCost(),
                    null,
                    [MatchLine::EXCEPTION_RECEIVED_NOT_BILLED],
                    QuantityScale::sub(0, $orderLine->getQuantityReceived()),
                    '0.00',
                );
            }
        }

        return new MatchReport(
            $bill,
            $lines,
            !$order instanceof PurchaseOrder,
            $this->hasDuplicateVendorInvoiceNumber($bill),
            $this->receivedAtOtherWarehouses($bill),
            $this->formatPercent($quantityTolerance),
            $this->formatPercent($priceTolerance),
        );
    }

    /**
     * Has this vendor invoice number been seen before — the duplicate-payment guard.
     *
     * Paying the same invoice twice is the most expensive clerical error in AP, and it happens
     * because the same PDF arrives twice by email. This **warns**; it never blocks. Vendors do
     * reuse their own numbers, and a hard block would eventually force somebody to enter a real
     * bill under a made-up one — which trades a visible duplicate for an invisible fiction.
     */
    public function hasDuplicateVendorInvoiceNumber(VendorBill $bill): bool
    {
        $number = trim((string) $bill->getVendorInvoiceNo());
        if ($number === '') {
            return false;
        }

        return $this->bills->findByVendorInvoiceNumber($bill->getVendor(), $number, $bill->getId()) !== [];
    }

    /**
     * The warehouses goods actually arrived at that this bill does not name.
     *
     * Empty is clean, and empty is the normal answer: a bill against a purchase order takes its
     * receiving warehouse from that order, and receiving books against the same order, so in the
     * happy path both ends name one building and agree by construction. This finds the case where
     * somebody chose a different one on one of the two screens.
     *
     * ## Why this is worth a flag at all
     *
     * `vendor_bill.warehouse_id` is the ONLY input to `vendor_bill.tax_province` (queue item 32),
     * so a bill naming the wrong building is not a filing error — it is a tax rate. Goods received
     * in Calgary and billed against a Surrey warehouse are charged GST+PST where Alberta has no
     * PST, and the figure is frozen onto the document at save. Nothing else in the estate can catch
     * it: the province derivation is correct, the warehouse it derived from is not, and only the
     * receipt knows.
     *
     * ## What it deliberately does NOT flag
     *
     *  - **a bill with no warehouse of its own.** There is no disagreement to report, and the gap
     *    it does have is already `VendorBill::approve()`'s refusal;
     *  - **a bill with no receipts.** Nothing has arrived to disagree with; being billed for goods
     *    that have not turned up is `EXCEPTION_BILLED_NOT_RECEIVED`, per line, and saying it twice
     *    in two shapes would teach clerks to click through both;
     *  - **a VOIDED receipt.** A void is a second fact that withdraws the first (#613), and a bill
     *    must not be held against a receipt somebody has already withdrawn — that is precisely the
     *    correction path #613 exists to provide;
     *  - **a bill with no purchase order.** Receipts are found through the order, so a standalone
     *    bill has nothing to compare. It is already `$withoutPurchaseOrder`, which already needs a
     *    human.
     *
     * Reported STRICTLY: any non-voided receipt at another building is named, not only the case
     * where every receipt disagrees. Goods split across two sites make a single frozen province
     * wrong for some of them however the split falls, and the class's own rule is that it is much
     * easier to loosen a check than to discover what has been auto-approving itself for six months.
     *
     * @return list<string> distinct warehouse names, ordered, so the sentence a human reads is
     *                      stable between two renders of the same bill
     */
    private function receivedAtOtherWarehouses(VendorBill $bill): array
    {
        $billed = $bill->getWarehouse();
        $order = $bill->getPurchaseOrder();

        if ($billed === null || !$order instanceof PurchaseOrder) {
            return [];
        }

        $names = [];

        foreach ($order->getReceipts() as $receipt) {
            if (!$receipt instanceof GoodsReceipt || $receipt->isVoided()) {
                continue;
            }

            // Compared by identity first and by id second, so an unflushed fixture — a warehouse
            // that is the same object but has no id yet — is not reported as a disagreement with
            // itself.
            $landed = $receipt->getWarehouse();
            if ($landed === $billed || ($landed->getId() !== null && $landed->getId() === $billed->getId())) {
                continue;
            }

            $names[$landed->getName()] = true;
        }

        /** @var list<string> $distinct */
        $distinct = array_keys($names);
        sort($distinct);

        return $distinct;
    }

    private function matchBillLine(
        VendorBillLine $billLine,
        ?PurchaseOrder $order,
        int $quantityTolerance,
        int $priceTolerance,
    ): MatchLine {
        $orderLine = $billLine->getPurchaseOrderLine();
        $billed = QuantityScale::canonical($billLine->getQuantity());

        if (!$orderLine instanceof PurchaseOrderLine) {
            // A charge with nothing to match it to. Legitimate — freight, a substitution, a
            // correction — and always a human's call, which is why it is an exception rather than
            // an error. A bill with no PO at all reports every line this way, and MatchReport says
            // so at the top so nobody reads five identical rows as five separate problems.
            return MatchLine::of(
                $billLine,
                null,
                $billLine->getName(),
                '0.00',
                '0.00',
                $billLine->getQuantity(),
                null,
                $billLine->getUnitCost(),
                [$order instanceof PurchaseOrder ? MatchLine::EXCEPTION_UNMATCHED : MatchLine::EXCEPTION_BILLED_NOT_RECEIVED],
                $billLine->getQuantity(),
                '0.00',
            );
        }

        $ordered = QuantityScale::canonical($orderLine->getQuantityOrdered());
        $received = QuantityScale::canonical($orderLine->getQuantityReceived());

        $exceptions = [];

        // Billed for more than turned up. The exception that stops money leaving for goods that
        // are not on the shelf, and the reason quantity is compared against received.
        if (QuantityScale::compare($billed, $this->allowExtraQty($received, $quantityTolerance)) > 0) {
            $exceptions[] = MatchLine::EXCEPTION_BILLED_NOT_RECEIVED;
        }

        // Over- or under-shipped against what was ordered. Distinct from the above: a vendor can
        // ship 250 against a PO for 240 and bill correctly for 250, which is a quantity variance
        // and nothing to do with whether we have the goods.
        $quantityGap = QuantityScale::compare($received, $ordered) >= 0
            ? QuantityScale::sub($received, $ordered)
            : QuantityScale::sub($ordered, $received);
        if (QuantityScale::compare($quantityGap, $this->allowanceQty($ordered, $quantityTolerance)) > 0) {
            $exceptions[] = MatchLine::EXCEPTION_QUANTITY_VARIANCE;
        }

        // Charged more than quoted. Compared in ten-thousandths, because unit costs carry four
        // decimal places and rounding to cents here is how a fifth of a cent per unit across
        // 100,000 units disappears.
        $orderedCost = self::unitCost($orderLine->getUnitCost());
        $billedCost = self::unitCost($billLine->getUnitCost());
        if ($billedCost > $this->allowExtra($orderedCost, $priceTolerance)) {
            $exceptions[] = MatchLine::EXCEPTION_PRICE_VARIANCE;
        }

        return MatchLine::of(
            $billLine,
            $orderLine,
            $orderLine->getName(),
            $orderLine->getQuantityOrdered(),
            $orderLine->getQuantityReceived(),
            $billLine->getQuantity(),
            $orderLine->getUnitCost(),
            $billLine->getUnitCost(),
            $exceptions,
            QuantityScale::sub($billed, $received),
            number_format(($billedCost - $orderedCost) / 10000, 4, '.', ''),
        );
    }

    /**
     * The ceiling a figure may reach before it counts as "more than" $base.
     *
     * Deliberately one-sided. Being charged for LESS than arrived, or at a lower price than quoted,
     * is not an exception anybody needs to chase — it is a discount, and flagging it would teach
     * clerks to click through the ones that matter.
     */
    private function allowExtra(int $base, int $tolerancePercent): int
    {
        return $base + $this->allowance($base, $tolerancePercent);
    }

    /** $tolerancePercent of $base, as an absolute allowance in the same units. Zero means exact. */
    private function allowance(int $base, int $tolerancePercent): int
    {
        if ($tolerancePercent <= 0) {
            return 0;
        }

        return (int) floor(abs($base) * $tolerancePercent / 10000);
    }

    /** allowExtra()'s counterpart for a quantity, held as a decimal string rather than as an int. */
    private function allowExtraQty(string $base, int $tolerancePercent): string
    {
        return QuantityScale::add($base, $this->allowanceQty($base, $tolerancePercent));
    }

    /** allowance()'s counterpart for a quantity, held as a decimal string rather than as an int. */
    private function allowanceQty(string $base, int $tolerancePercent): string
    {
        if ($tolerancePercent <= 0) {
            return QuantityScale::canonical(0);
        }

        $absBase = QuantityScale::compare($base, 0) < 0
            ? QuantityScale::sub(0, $base)
            : QuantityScale::canonical($base);

        return bcdiv(bcmul($absBase, (string) $tolerancePercent, QuantityScale::MAX_DECIMALS + 4), '10000', QuantityScale::MAX_DECIMALS);
    }

    /**
     * A tolerance percentage, held as hundredths of a percent so that "0.5%" is expressible without
     * floats. Default 0 — see the class docblock on why it starts exact.
     */
    private function tolerance(string $setting): int
    {
        $raw = trim((string) $this->appSettings->get($setting, '0'));
        if ($raw === '' || !is_numeric($raw)) {
            return 0;
        }

        return max(0, (int) round((float) $raw * 100));
    }

    private function formatPercent(int $hundredthsOfPercent): string
    {
        return rtrim(rtrim(number_format($hundredthsOfPercent / 100, 2, '.', ''), '0'), '.') ?: '0';
    }

    /** Unit costs compared in ten-thousandths, matching their four stored decimal places. */
    private static function unitCost(?string $cost): int
    {
        return (int) round((float) $cost * 10000);
    }
}
