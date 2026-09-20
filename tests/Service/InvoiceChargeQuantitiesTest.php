<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Entity\Company;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceIssueIntent;
use App\Enum\SalesOrderStatus;
use App\Service\DocumentActor;
use App\Service\OrderInvoicingService;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * Charges are quantified rows (#539 stage 5).
 *
 * The client's decision, which replaced an earlier apportionment design: a charge is a row like any
 * other and the person raising the invoice says how much of it this one bills. A flat fee is a row
 * of quantity 1; bill 0.4 of it and 0.6 remains. Nothing divides anything, so there is no rounding
 * remainder to place — the figures entered sum to the order's own, or the order is not fully
 * invoiced yet.
 *
 * What is pinned here is that rule and its three consequences: the sum may not exceed the order's
 * quantity, the order is not Invoiced until the charges are billed too, and cancelling an invoice
 * gives its share back.
 *
 * Against a real EntityManager, because the derived order status these produce comes from a real
 * Doctrine listener and the numbering goes through raw SQL.
 */
final class InvoiceChargeQuantitiesTest extends DoctrineIntegrationTestCase
{
    private Company $company;
    private OrderInvoicingService $service;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);
        $this->em->flush();

        $this->service = self::getContainer()->get(OrderInvoicingService::class);
    }

    public function testARowFrozenBeforeChargesWereQuantifiedReadsBackAsOneWholeCharge(): void
    {
        // The entire migration this widening needs. A snapshot written before the field existed has
        // no quantity in it, and it was always the whole charge.
        $lines = FeeLineSnapshot::decode(
            '[{"slug":"shipping","label":"Shipping (Ground)","taxClass":"G","amount":10,"placement":"main_line","type":"shipping","source":"auto-calc"}]'
        );

        self::assertCount(1, $lines);
        self::assertSame(1.0, $lines[0]->quantity);
        self::assertSame(10.0, $lines[0]->amount);
        self::assertSame(10.0, $lines[0]->unitAmount());
    }

    public function testAQuantityRoundTripsThroughTheSnapshot(): void
    {
        $encoded = FeeLineSnapshot::encode([
            new FeeLine(null, 'shipping', 'Shipping (Ground)', 'G', 4.0, 'main_line', FeeLine::TYPE_SHIPPING, FeeLine::SOURCE_AUTO_CALC, 0.4),
        ]);

        $decoded = FeeLineSnapshot::decode($encoded);

        self::assertSame(0.4, $decoded[0]->quantity);
        // The amount stays what the ROW charges, never a per-unit rate: every reader of this
        // snapshot sums amounts and not one of them multiplies.
        self::assertSame(4.0, $decoded[0]->amount);
        self::assertSame(10.0, $decoded[0]->unitAmount());
    }

    public function testPartOfAFlatChargeIsBilledAndTheRestRemains(): void
    {
        $order = $this->orderWithShipping('10.00', 10.0);
        $line = $order->getLines()->first();

        $invoice = $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '4.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
            [['slug' => 'shipping', 'quantity' => '0.40', 'amount' => null]],
        );
        $this->em->flush();

        self::assertSame('0.40', $invoice->chargeQuantityFor('shipping'));
        // Nothing apportioned it: 0.4 of a $10 charge is $4, because the admin asked for 0.4 of it.
        self::assertSame(4.0, $invoice->chargeAmountFor('shipping'));
        self::assertSame('0.6000', $order->uninvoicedChargeQuantityFor('shipping'));
        self::assertSame('20.00', $invoice->getSubtotal());
        self::assertSame('24.00', $invoice->getTotal(), 'The charge is on the invoice total, not only in its snapshot.');
    }

    public function testAnOrderIsNotFullyInvoicedWhileAChargeIsStillOwed(): void
    {
        $order = $this->orderWithShipping('10.00', 10.0);
        $line = $order->getLines()->first();

        $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '10.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
            // Every unit of goods, and none of the freight.
            [],
        );
        $this->em->flush();

        self::assertSame('0.0000', $order->uninvoicedQuantityFor($line));
        self::assertSame('1.0000', $order->uninvoicedChargeQuantityFor('shipping'));
        self::assertFalse($order->isFullyInvoiced());
        self::assertSame(SalesOrderStatus::PartiallyInvoiced, $order->getStatusEnum());
    }

    public function testBillingTheRemainingChargeCompletesTheOrder(): void
    {
        $order = $this->orderWithShipping('10.00', 10.0);
        $line = $order->getLines()->first();

        $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '10.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
            [],
        );
        $this->em->flush();

        // A charge-only invoice: the goods are all billed and the freight still is not.
        $freight = $this->service->invoiceFromOrder(
            $order,
            [],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
            [['slug' => 'shipping', 'quantity' => '1.00', 'amount' => null]],
        );
        $this->em->flush();

        self::assertCount(0, $freight->getLines());
        self::assertSame(10.0, $freight->chargeAmountFor('shipping'));
        self::assertSame('0.0000', $order->uninvoicedChargeQuantityFor('shipping'));
        self::assertTrue($order->isFullyInvoiced());
        self::assertSame(SalesOrderStatus::Invoiced, $order->getStatusEnum());
    }

    public function testAChargeQuantityAboveTheRemainderIsRefused(): void
    {
        $order = $this->orderWithShipping('10.00', 10.0);
        $line = $order->getLines()->first();

        $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '4.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
            [['slug' => 'shipping', 'quantity' => '0.60', 'amount' => null]],
        );
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('only 0.4 of that charge is left to invoice');

        $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '4.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
            [['slug' => 'shipping', 'quantity' => '0.60', 'amount' => null]],
        );
    }

    public function testCancellingAnInvoiceReturnsItsShareOfTheChargeToTheRemainder(): void
    {
        $order = $this->orderWithShipping('10.00', 10.0);
        $line = $order->getLines()->first();

        $invoice = $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '4.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
            [['slug' => 'shipping', 'quantity' => '0.40', 'amount' => null]],
        );
        $this->em->flush();
        self::assertSame('0.6000', $order->uninvoicedChargeQuantityFor('shipping'));

        $invoice->setStatus('Cancelled', DocumentActor::system());
        $this->em->flush();

        // Exactly as for product lines: a cancelled invoice counts toward nothing.
        self::assertSame('1.0000', $order->uninvoicedChargeQuantityFor('shipping'));
        self::assertSame('10.0000', $order->uninvoicedQuantityFor($line));
    }

    public function testADraftInvoiceHoldsNoChargeQuantityEither(): void
    {
        $order = $this->orderWithShipping('10.00', 10.0);
        $line = $order->getLines()->first();

        $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '4.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::KeepDraft,
            [['slug' => 'shipping', 'quantity' => '0.40', 'amount' => null]],
        );
        $this->em->flush();

        self::assertSame('1.0000', $order->uninvoicedChargeQuantityFor('shipping'), 'A draft invoice is inert.');
    }

    public function testAnAmountMayBeTypedOverTheOneTheQuantityImplies(): void
    {
        $order = $this->orderWithShipping('10.00', 10.0);
        $line = $order->getLines()->first();

        $invoice = $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '4.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
            // This delivery carried the freight and the next one will not, so the admin says so.
            [['slug' => 'shipping', 'quantity' => '0.5000', 'amount' => '9.00']],
        );
        $this->em->flush();

        self::assertSame(9.0, $invoice->chargeAmountFor('shipping'));
        // Quantity is what the remainder is decided by; the amount is what is charged. #539 settles
        // that "fully invoiced" is a question about quantity and never about money.
        self::assertSame('0.5000', $order->uninvoicedChargeQuantityFor('shipping'));
    }

    public function testAnInvoiceForTheWholeOrderStillTakesItsChargeSnapshotVerbatim(): void
    {
        $order = $this->orderWithShipping('10.00', 10.0);
        $order->setSubtotal('50.00')->setTax('6.50')->setTotal('66.50');
        $this->em->flush();
        $line = $order->getLines()->first();

        $invoice = $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '10.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
            [['slug' => 'shipping', 'quantity' => '1.00', 'amount' => null]],
        );
        $this->em->flush();

        // Nothing is recomputed for an invoice that bills the whole order — the tax figure in
        // particular has no derivation available to it here.
        self::assertSame($order->getFeeLines(), $invoice->getFeeLines());
        self::assertSame('6.50', $invoice->getTax());
        self::assertSame('66.50', $invoice->getTotal());
        self::assertTrue($order->isFullyInvoiced());
    }

    public function testClearingEveryChargeStopsTheOrdersSnapshotBeingHandedBack(): void
    {
        $order = $this->orderWithShipping('10.00', 10.0);
        $order->setSubtotal('50.00')->setTax('6.50')->setTotal('66.50');
        $this->em->flush();
        $line = $order->getLines()->first();

        $invoice = $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '10.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
            [],
        );
        $this->em->flush();

        // Every line billed, but the admin said not to charge the freight — so handing the order's
        // shipping snapshot back would charge the very thing they had just declined.
        self::assertNull($invoice->getFeeLines());
        self::assertSame('50.00', $invoice->getTotal());
    }

    public function testAChargeNotOfferedAtAllDefaultsToItsRemainder(): void
    {
        $order = $this->orderWithShipping('10.00', 10.0);
        $line = $order->getLines()->first();

        // Null, not []. A caller that never asked gets what the screen pre-fills; a caller that
        // passed an empty list said "no charges", which is a different answer.
        $invoice = $this->service->invoiceFromOrder(
            $order,
            [['line' => $line, 'quantity' => '4.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
        );
        $this->em->flush();

        self::assertSame('1.00', $invoice->chargeQuantityFor('shipping'));
        self::assertSame('0.0000', $order->uninvoicedChargeQuantityFor('shipping'));
    }

    public function testTwoRowsSharingASlugAreOneChargeOfTheCombinedQuantity(): void
    {
        $order = $this->orderWithCharges('10.00', [
            ['slug' => 'adjustment', 'label' => 'Adjustment', 'amount' => 5.0],
            ['slug' => 'adjustment', 'label' => 'Adjustment', 'amount' => 15.0],
        ]);

        self::assertSame(['adjustment'], $order->getChargeSlugs());
        self::assertSame('2.00', $order->chargeQuantityFor('adjustment'));
        self::assertSame(20.0, $order->chargeAmountFor('adjustment'));
        // 2 rows of 1 = quantity 2 for $20, so one whole unit costs $10.
        self::assertSame('2.0000', $order->uninvoicedChargeQuantityFor('adjustment'));
    }

    /** An approved order for one SKU, carrying one flat shipping charge. */
    private function orderWithShipping(string $quantity, float $amount): SalesOrder
    {
        return $this->orderWithCharges($quantity, [
            ['slug' => 'shipping', 'label' => 'Shipping (Ground)', 'amount' => $amount],
        ]);
    }

    /** @param list<array{slug: string, label: string, amount: float}> $charges */
    private function orderWithCharges(string $quantity, array $charges): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('SO-C' . ++$this->sequence)
            ->setDocumentDate('2026-08-20');
        $order->copyCompanySnapshotFrom($order);
        $order->addLine(
            (new SalesOrderLine())->setName('Widget')->setSku('WID-1')->setQuantity($quantity)->setPrice('5.00')
        );

        $lines = [];
        foreach ($charges as $charge) {
            $lines[] = new FeeLine(
                null,
                $charge['slug'],
                $charge['label'],
                'G',
                $charge['amount'],
                'main_line',
                $charge['slug'] === 'shipping' ? FeeLine::TYPE_SHIPPING : FeeLine::TYPE_FEE,
                FeeLine::SOURCE_AUTO_CALC,
            );
        }

        $total = (float) $quantity * 5.0;
        $order
            ->setFeeLines(FeeLineSnapshot::encode($lines))
            ->setSubtotal(number_format($total, 2, '.', ''))
            ->setTotal(number_format($total, 2, '.', ''));

        $this->em->persist($order);
        $this->em->flush();

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->flush();

        return $order;
    }
}
