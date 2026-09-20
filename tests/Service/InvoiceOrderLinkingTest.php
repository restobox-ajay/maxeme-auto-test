<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Enum\InvoiceIssueIntent;
use App\Enum\SalesOrderStatus;
use App\Model\InvoiceOrderMismatch;
use App\Service\DocumentActor;
use App\Service\InvoiceOrderMatcher;
use App\Service\OrderInvoicingService;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * Relating an existing invoice to an existing sales order (#539 stage 5).
 *
 * The issue's words: "the condition is that SKU/product lines must match so it can deduct properly.
 * If not match, show clearly what is not matching so user can correct. One thing about shipping and
 * other fees, we do not need to worry about force matching those. Prices can also be edited at
 * invoice level, rare, but should be allowed."
 *
 * The plan calls this the subtlest thing in the issue and that is why stage 5 is alone. These are
 * the tests it asked to be written before any screen was built against the rule — including, in
 * particular, the two things that must NOT block: charges differing, and prices differing.
 */
final class InvoiceOrderLinkingTest extends DoctrineIntegrationTestCase
{
    private Company $company;
    private InvoiceOrderMatcher $matcher;
    private OrderInvoicingService $invoicing;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);
        $this->em->flush();

        $this->matcher = self::getContainer()->get(InvoiceOrderMatcher::class);
        $this->invoicing = self::getContainer()->get(OrderInvoicingService::class);
    }

    public function testAnExactMatchLinksAndAttributesEveryRow(): void
    {
        $order = $this->approvedOrder(['WID-1' => '10.00', 'GAD-2' => '4.00']);
        $invoice = $this->standaloneInvoice(['WID-1' => '10.00', 'GAD-2' => '4.00']);

        $match = $this->matcher->match($invoice, $order);
        self::assertTrue($match->isLinkable(), implode(' ', $match->blockerMessages()));
        self::assertSame([], $match->notices());

        $invoice->linkToOrder(DocumentActor::named('Priya Admin'), $order, $match->attributions);
        $this->em->flush();

        self::assertSame($order, $invoice->getSalesOrder());
        // Attribution per row is the whole point: uninvoiced quantity is derived from
        // InvoiceLine::$salesOrderLine, so a link without it would deduct nothing.
        foreach ($invoice->getLines() as $line) {
            self::assertNotNull($line->getSalesOrderLine());
            self::assertSame($order, $line->getSalesOrderLine()->getOrder());
        }

        self::assertTrue($order->isFullyInvoiced());
        self::assertSame(SalesOrderStatus::Invoiced, $order->getStatusEnum());
    }

    public function testASkuOnTheInvoiceThatIsNotOnTheOrderIsNamedAndBlocks(): void
    {
        $order = $this->approvedOrder(['WID-1' => '10.00']);
        $invoice = $this->standaloneInvoice(['WID-1' => '6.00', 'MYSTERY-9' => '2.00']);

        $match = $this->matcher->match($invoice, $order);

        self::assertFalse($match->isLinkable());
        self::assertSame(
            [InvoiceOrderMismatch::KIND_SKU_NOT_ON_ORDER],
            array_map(static fn ($m): string => $m->kind, $match->blockers()),
        );
        // Specific and actionable: which SKU, on which side, and how much of it.
        self::assertStringContainsString('SKU MYSTERY-9', $match->blockerMessages()[0]);
        self::assertStringContainsString('bills 2 of it', $match->blockerMessages()[0]);
        self::assertStringContainsString('has no line with that SKU', $match->blockerMessages()[0]);
    }

    public function testASkuOnTheOrderThatTheInvoiceDoesNotBillIsReportedButDoesNotBlock(): void
    {
        $order = $this->approvedOrder(['WID-1' => '10.00', 'GAD-2' => '4.00']);
        $invoice = $this->standaloneInvoice(['WID-1' => '10.00']);

        $match = $this->matcher->match($invoice, $order);

        // Blocking on this would forbid linking any invoice that bills less than the whole order,
        // which is the case #539 exists for. It is reported so nobody links a half invoice believing
        // it covered everything.
        self::assertTrue($match->isLinkable(), implode(' ', $match->blockerMessages()));
        self::assertSame(
            [InvoiceOrderMismatch::KIND_ORDER_SKU_NOT_BILLED],
            array_map(static fn ($m): string => $m->kind, $match->notices()),
        );
        self::assertStringContainsString('SKU GAD-2', $match->notices()[0]->message);
        self::assertStringContainsString('still has 4 left to invoice', $match->notices()[0]->message);

        $invoice->linkToOrder(DocumentActor::system(), $order, $match->attributions);
        $this->em->flush();

        self::assertSame(SalesOrderStatus::PartiallyInvoiced, $order->getStatusEnum());
        self::assertSame('4.0000', $order->uninvoicedQuantityFor($order->getLines()->toArray()[1]));
    }

    public function testAQuantityAboveTheOrdersRemainderBlocksAndSaysByHowMuch(): void
    {
        $order = $this->approvedOrder(['WID-1' => '10.00']);
        // Seven already billed by an invoice raised the ordinary way, so only three are left.
        $this->invoicing->invoiceFromOrder(
            $order,
            [['line' => $order->getLines()->first(), 'quantity' => '7.00', 'price' => null]],
            $this->em,
            DocumentActor::system(),
            InvoiceIssueIntent::Issue,
            [],
        );
        $this->em->flush();

        $invoice = $this->standaloneInvoice(['WID-1' => '5.00']);
        $match = $this->matcher->match($invoice, $order);

        self::assertFalse($match->isLinkable());
        self::assertSame(
            [InvoiceOrderMismatch::KIND_QUANTITY_EXCEEDS_REMAINING],
            array_map(static fn ($m): string => $m->kind, $match->blockers()),
        );
        self::assertStringContainsString('bills 5 but order', $match->blockerMessages()[0]);
        self::assertStringContainsString('only 3 left to invoice', $match->blockerMessages()[0]);
        self::assertStringContainsString('(10 ordered, 7 already invoiced)', $match->blockerMessages()[0]);
    }

    public function testAnInvoiceAlreadyLinkedToAnOrderIsRefused(): void
    {
        $first = $this->approvedOrder(['WID-1' => '10.00']);
        $second = $this->approvedOrder(['WID-1' => '10.00']);

        $invoice = $this->standaloneInvoice(['WID-1' => '10.00']);
        $invoice->linkToOrder(DocumentActor::system(), $first, $this->matcher->match($invoice, $first)->attributions);
        $this->em->flush();

        $match = $this->matcher->match($invoice, $second);
        self::assertFalse($match->isLinkable());
        self::assertStringContainsString('is already linked to order ' . $first->getOrderNumber(), $match->blockerMessages()[0]);

        // The report is what the screen shows; the entity is what actually refuses.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already linked to order');
        $invoice->linkToOrder(DocumentActor::system(), $second);
    }

    public function testChargesAndShippingDifferingDoesNotBlock(): void
    {
        // "One thing about shipping and other fees, we do not need to worry about force matching
        // those." Charges live in the fee_lines snapshot, not in the line collection, so this falls
        // out of the matching reading getLines() and nothing else — there is no exemption to carve.
        $order = $this->approvedOrder(['WID-1' => '10.00']);
        $order->setFeeLines(FeeLineSnapshot::encode([
            new FeeLine(null, 'shipping', 'Shipping (Ground)', 'G', 25.0, 'main_line', FeeLine::TYPE_SHIPPING),
        ]));
        $this->em->flush();

        $invoice = $this->standaloneInvoice(['WID-1' => '10.00']);
        $invoice->setFeeLines(FeeLineSnapshot::encode([
            new FeeLine(null, 'rush', 'Rush handling', 'G', 3.5, 'main_line', FeeLine::TYPE_FEE),
        ]));

        $match = $this->matcher->match($invoice, $order);

        self::assertTrue($match->isLinkable(), implode(' ', $match->blockerMessages()));
        self::assertSame([], $match->mismatches, 'A charge is never a matching failure, not even a reported one.');
    }

    public function testPricesDifferingDoesNotBlock(): void
    {
        // "Prices can also be edited at invoice level, rare, but should be allowed." Which is also
        // why "fully invoiced" is decided by quantity and never by amount.
        $order = $this->approvedOrder(['WID-1' => '10.00']);
        $invoice = $this->standaloneInvoice(['WID-1' => '10.00']);
        foreach ($invoice->getLines() as $line) {
            $line->setPrice('99.00')->setSubtotal('990.00');
        }
        $invoice->setSubtotal('990.00')->setTotal('990.00');

        $match = $this->matcher->match($invoice, $order);

        self::assertTrue($match->isLinkable(), implode(' ', $match->blockerMessages()));
        self::assertSame([], $match->mismatches);
    }

    public function testAnInvoiceForAnotherCustomerIsRefused(): void
    {
        $other = (new Company())->setName('Globex Inc')->setCode('GLBX');
        $this->em->persist($other);
        $this->em->flush();

        $order = $this->approvedOrder(['WID-1' => '10.00']);
        $invoice = $this->standaloneInvoice(['WID-1' => '10.00'], $other);

        $match = $this->matcher->match($invoice, $order);

        self::assertFalse($match->isLinkable());
        self::assertStringContainsString('bills Globex Inc', $match->blockerMessages()[0]);
        self::assertStringContainsString('is for Acme Co', $match->blockerMessages()[0]);
    }

    public function testALineWithNoSkuCannotBeMatched(): void
    {
        $order = $this->approvedOrder(['WID-1' => '10.00']);
        $invoice = $this->standaloneInvoice(['WID-1' => '10.00']);
        $invoice->addLine((new InvoiceLine())->setName('Miscellaneous')->setQuantity('1.00')->setPrice('5.00'));

        $match = $this->matcher->match($invoice, $order);

        self::assertFalse($match->isLinkable());
        self::assertStringContainsString('Invoice line "Miscellaneous" has no SKU', $match->blockerMessages()[0]);
    }

    public function testSkusMatchCaseInsensitively(): void
    {
        $order = $this->approvedOrder(['WID-1' => '10.00']);
        $invoice = $this->standaloneInvoice(['wid-1' => '10.00']);

        self::assertTrue($this->matcher->match($invoice, $order)->isLinkable());
    }

    public function testUnlinkingReturnsTheQuantityToTheOrderAndClearsTheAttribution(): void
    {
        $order = $this->approvedOrder(['WID-1' => '10.00']);
        $invoice = $this->standaloneInvoice(['WID-1' => '10.00']);
        $invoice->linkToOrder(DocumentActor::system(), $order, $this->matcher->match($invoice, $order)->attributions);
        $this->em->flush();
        self::assertSame(SalesOrderStatus::Invoiced, $order->getStatusEnum());

        $invoice->unlinkFromOrder(DocumentActor::named('Priya Admin'), 'linked to the wrong order');
        $this->em->flush();

        self::assertNull($invoice->getSalesOrder());
        self::assertCount(0, $order->getInvoices());
        self::assertSame('10.0000', $order->uninvoicedQuantityFor($order->getLines()->first()));
        self::assertSame(SalesOrderStatus::Approved, $order->getStatusEnum());
        foreach ($invoice->getLines() as $line) {
            self::assertNull($line->getSalesOrderLine(), 'A stale attribution would keep drawing the order down.');
        }
    }

    public function testLinkingWritesATimelineEntryOnBothDocuments(): void
    {
        $order = $this->approvedOrder(['WID-1' => '10.00']);
        $invoice = $this->standaloneInvoice(['WID-1' => '10.00']);

        $invoice->linkToOrder(DocumentActor::named('Priya Admin'), $order, $this->matcher->match($invoice, $order)->attributions);
        $this->em->flush();

        self::assertContains(
            sprintf('Invoice %s was linked to this order.', $invoice->getDocumentNumber()),
            $this->narrativeComments('SalesOrder', $order->getId()),
        );
        self::assertContains(
            sprintf('Linked to order %s.', $order->getOrderNumber()),
            $this->narrativeComments('Invoice', $invoice->getId()),
        );
    }

    /**
     * An approved order with one line per SKU.
     *
     * @param array<string, string> $lines sku => quantity
     */
    /** @return list<string> the narrative timeline comments for one document, oldest first */
    private function narrativeComments(string $entityType, int $entityId): array
    {
        $logs = $this->em->getRepository(AuditLog::class)->findBy(
            ['entityType' => $entityType, 'entityId' => $entityId, 'actorType' => 'document'],
            ['id' => 'ASC'],
        );

        return array_map(static fn (AuditLog $log): string => $log->getSummary(), $logs);
    }

    private function approvedOrder(array $lines): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('SO-L' . ++$this->sequence)
            ->setDocumentDate('2026-08-20');
        $order->copyCompanySnapshotFrom($order);

        $total = 0.0;
        foreach ($lines as $sku => $quantity) {
            $order->addLine(
                (new SalesOrderLine())->setName($sku)->setSku($sku)->setQuantity($quantity)->setPrice('5.00')
            );
            $total += (float) $quantity * 5.0;
        }

        $order->setSubtotal(number_format($total, 2, '.', ''))->setTotal(number_format($total, 2, '.', ''));

        $this->em->persist($order);
        $this->em->flush();

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->flush();

        return $order;
    }

    /**
     * An invoice raised on its own, with no order behind it — the case the link screen exists for.
     *
     * Issued rather than left Draft, because a draft counts toward nothing and would make every
     * quantity assertion here vacuous.
     *
     * @param array<string, string> $lines sku => quantity
     */
    private function standaloneInvoice(array $lines, ?Company $company = null): Invoice
    {
        $invoice = new Invoice();
        $invoice->setCompany($company ?? $this->company);
        $invoice
            ->setDocumentNumber('INV-L' . ++$this->sequence)
            ->setDocumentDate('2026-08-20')
            ->setInvoiceDate('2026-08-20');

        $total = 0.0;
        foreach ($lines as $sku => $quantity) {
            $invoice->addLine(
                (new InvoiceLine())
                    ->setName($sku)
                    ->setSku($sku)
                    ->setQuantity($quantity)
                    ->setPrice('5.00')
                    ->setSubtotal(number_format((float) $quantity * 5.0, 2, '.', ''))
            );
            $total += (float) $quantity * 5.0;
        }

        $invoice->setSubtotal(number_format($total, 2, '.', ''))->setTotal(number_format($total, 2, '.', ''));

        $this->em->persist($invoice);
        $this->em->flush();

        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        return $invoice;
    }
}
