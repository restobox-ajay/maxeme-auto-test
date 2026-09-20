<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QuantityScale;
use App\Repository\BundleStatusRepository;
use App\Service\DocumentActor;
use App\Service\OrderTaxBreakdownService;
use Psr\Log\NullLogger;
use TaxBundle\Tax\TaxCalculatorResolver;
use App\Entity\SalesOrder;
use App\Entity\AppSetting;
use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Enum\InvoicePaymentStatus;
use App\Enum\QuoteConversionInvoicing;
use App\Enum\SalesOrderStatus;
use App\Service\AppSettings;
use App\Service\DocumentNumberAllocator;
use App\Service\EstimateAlreadyConvertedException;
use App\Service\EstimateConversionService;
use App\Service\Inventory\AdminOrderStockValidator;
use App\Service\Inventory\BackorderSplitResolver;
use App\Service\Inventory\LotAvailabilityResolver;
use App\Service\WarehouseFulfillmentRegionService;
use App\Service\InvoiceNumberGenerator;
use App\Service\OrderInvoicingService;
use App\Service\OrderNumberGenerator;
use App\Tests\DoctrineIntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Exercises convert() against a real EntityManager since OrderNumberGenerator::next()
 * drives raw SQL a mocked EntityManager couldn't meaningfully fake (see
 * OrderNumberGeneratorTest, whose generator()-stubbing pattern this mirrors).
 */
final class EstimateConversionServiceTest extends DoctrineIntegrationTestCase
{
    private Company $company;
    private EstimateConversionService $service;
    private int $estimateSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);
        $this->em->flush();

        // A real validator rather than a stub, and it reports nothing here: the estimate lines
        // below carry a NAME but no product, and a line with no product consumes no stock, so
        // shortfallsForOrder() skips them and convert() approves the order it built.
        //
        // Worth stating because it is load-bearing for the assertions on the order's status. The
        // Draft-when-short behaviour is exercised end to end in QuoteAcceptanceStockShortfallCest,
        // which has real products and real inventory; this suite is about which FIELDS conversion
        // copies, and giving it stock would only be scenery.
        $this->service = new EstimateConversionService(
            $this->orderNumberGenerator('HD'),
            new AdminOrderStockValidator(
                self::getContainer()->get(WarehouseFulfillmentRegionService::class),
                self::getContainer()->get(BackorderSplitResolver::class),
                self::getContainer()->get(LotAvailabilityResolver::class),
                self::getContainer()->get(QuantityScale::class),
            ),
            self::getContainer()->get(BackorderSplitResolver::class),
            // No invoice_number_prefix row, so the invoice numbers this raises come out under
            // InvoiceNumberGenerator's own 'INV-' default — deliberately a different sequence from
            // the order numbers above, which is the point of a per-kind counter.
            new OrderInvoicingService(
                new InvoiceNumberGenerator($this->appSettings([]), new DocumentNumberAllocator()),
                $this->taxBreakdown(),
            ),
        );
    }

    private function orderNumberGenerator(string $prefix): OrderNumberGenerator
    {
        return new OrderNumberGenerator($this->appSettings(['order_number_prefix' => $prefix]), new DocumentNumberAllocator());
    }

    /**
     * Settings read from a stubbed repository rather than the real database, matching
     * OrderNumberGeneratorTest's pattern: the generators only ever ask AppSettings for a prefix, and
     * writing rows through the live EntityManager would put them inside the transaction under test.
     *
     * @param array<string, string> $rows
     */
    private function appSettings(array $rows): AppSettings
    {
        $settingRows = [];
        foreach ($rows as $key => $value) {
            $settingRows[] = (new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($value);
        }

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($settingRows);

        $settingsEm = $this->createStub(EntityManagerInterface::class);
        $settingsEm->method('getRepository')->willReturn($repo);

        return new AppSettings($settingsEm, new ArrayAdapter());
    }

    private function fullyPricedEstimate(): Estimate
    {
        $estimate = (new Estimate())
            ->setCompany($this->company)
            ->setDocumentNumber('EST' . ++$this->estimateSequence)
            ->setSource('Customer')
            ->setUserName('Jane Buyer')
            ->setBillingName('Jane Buyer')
            ->setShippingName('Jane Buyer')
            ->setShippingCompanyName('Acme Co')
            ->setPoNumber('PO-1')
            ->setSpecialInstructions('Leave at dock')
            ->setShippingMethod('Ground')
            ->setFulfillmentRegion('West')
            ->setFeeLines(json_encode([
                ['slug' => 'handling', 'label' => 'Handling', 'taxClass' => 'G', 'amount' => 2.0, 'placement' => 'main_line', 'type' => 'fee', 'source' => 'auto-calc'],
                ['slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'G', 'amount' => 10.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc'],
            ]))
            ->setTaxLines('taxes')
            ->setSubtotal('100.00')
            ->setTax('5.00')
            ->setTotal('115.00');
        $estimate->setStatus('Priced', DocumentActor::system());

        $line = (new EstimateLine())
            ->setName('Widget')
            ->setLocation('Aisle 1')
            ->setSku('WIDGET-1')
            ->setQuantity('2.00')
            ->setWeight('3.5')
            ->setUnit('lb')
            ->setTaxCode('TAX1')
            ->setCost('40.00')
            ->setPrice('50.00')
            ->setSubtotal('100.00');
        $estimate->addLine($line);

        $this->em->persist($estimate);
        $this->em->flush();

        return $estimate;
    }

    public function testConvertThrowsWhenEstimateIsNeitherPricedNorAccepted(): void
    {
        $estimate = $this->fullyPricedEstimate();
        $estimate->setStatus('Submitted', DocumentActor::system());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only a Priced or Accepted estimate can be converted to an order.');

        $this->service->convert($estimate, $this->em);
    }

    /**
     * An Accepted quote with no order behind it converts — the state the admin side now produces,
     * where acceptance and conversion are separate actions taken at separate times.
     *
     * Priced still converts too (every other test here does it); this is the second door, not a
     * replacement for the first.
     */
    public function testConvertAcceptsAnAlreadyAcceptedEstimateThatHasNoOrderYet(): void
    {
        $estimate = $this->fullyPricedEstimate();
        // Exactly what the admin's Accept action leaves behind: the status moved, nothing created.
        $estimate->setStatus('Accepted', DocumentActor::system());
        $this->em->flush();

        $order = $this->service->convert($estimate, $this->em);
        $this->em->flush();

        self::assertSame($order, $estimate->getConvertedOrder());
        self::assertSame('Accepted', $estimate->getStatus());
        self::assertSame(SalesOrderStatus::Invoiced->value, $order->getStatus(), 'the default still bills it');
    }

    /**
     * The invoice is the caller's to ask for. NoInvoice produces the order and nothing else, which
     * is what the admin's Convert to Sales Order passes.
     *
     * The order itself is unchanged by the choice — still approved, still carrying its lines — so
     * both halves are asserted: what was skipped, and what was not.
     */
    public function testConvertRaisesNoInvoiceWhenTheCallerAsksForNone(): void
    {
        $estimate = $this->fullyPricedEstimate();

        $order = $this->service->convert($estimate, $this->em, null, QuoteConversionInvoicing::NoInvoice);
        $this->em->flush();

        self::assertSame([], $this->em->getRepository(Invoice::class)->findBy(['salesOrder' => $order]));
        // Approved, not Invoiced: nothing billed it, so the deriver has nothing to move it on with.
        self::assertSame(SalesOrderStatus::Approved->value, $order->getStatus());
        self::assertCount(1, $order->getLines());
        self::assertSame($order, $estimate->getConvertedOrder());
    }

    public function testConvertThrowsWhenEstimateIsNotFullyPriced(): void
    {
        $estimate = $this->fullyPricedEstimate();
        $estimate->setTax(null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot convert an estimate that is not fully priced.');

        $this->service->convert($estimate, $this->em);
    }

    public function testConvertCreatesOrderCopyingHeaderAndLineFields(): void
    {
        $estimate = $this->fullyPricedEstimate();

        $order = $this->service->convert($estimate, $this->em);

        // Nothing on these lines is short, so convert() accepted the order it built — through
        // approve(), the only thing that writes Approved (#539 stage 2).
        self::assertSame(SalesOrderStatus::Approved->value, $order->getStatus());

        $this->em->flush();

        // And the flush moved it on again, correctly: convert() also raised the shadow invoice for
        // the whole order and issued it, so every ordered quantity is invoiced — but the invoice is
        // 'Not Paid', so SalesOrderStatusDeriver derives Invoiced rather than Closed.
        self::assertSame(SalesOrderStatus::Invoiced->value, $order->getStatus());

        self::assertInstanceOf(SalesOrder::class, $order);
        self::assertSame('HD1', $order->getOrderNumber());
        self::assertSame($this->company, $order->getCompany());
        self::assertSame('Customer', $order->getSource());
        self::assertSame('Jane Buyer', $order->getUserName());
        self::assertSame('Jane Buyer', $order->getBillingName());
        self::assertSame('Jane Buyer', $order->getShippingName());
        self::assertSame('Acme Co', $order->getShippingCompanyName());
        self::assertSame('PO-1', $order->getPoNumber());
        self::assertSame('Leave at dock', $order->getSpecialInstructions());
        self::assertSame('Ground', $order->getShippingMethod());
        self::assertSame('West', $order->getFulfillmentRegion());
        self::assertSame($estimate->getFeeLines(), $order->getFeeLines());
        self::assertSame('taxes', $order->getTaxLines());
        self::assertSame('100.00', $order->getSubtotal());
        // The quote's shipping row travelled inside fee_lines, which is the only place a shipping
        // figure lives — nothing is copied across on its own.
        self::assertSame(
            ['Shipping (Ground)'],
            array_map(static fn ($line) => $line->label, $order->getShippingLines()),
        );
        self::assertSame(10.0, $order->getShippingTotal());
        self::assertSame('5.00', $order->getTax());
        self::assertSame('115.00', $order->getTotal());

        self::assertCount(1, $order->getLines());
        $orderLine = $order->getLines()->first();
        self::assertSame('Widget', $orderLine->getName());
        self::assertSame('Aisle 1', $orderLine->getLocation());
        self::assertSame('WIDGET-1', $orderLine->getSku());
        self::assertSame('2.00', $orderLine->getQuantity());
        self::assertSame('3.5', $orderLine->getWeight());
        self::assertSame('lb', $orderLine->getUnit());
        self::assertSame('TAX1', $orderLine->getTaxCode());
        self::assertSame('40.00', $orderLine->getCost());
        self::assertSame('50.00', $orderLine->getPrice());
        self::assertSame('100.00', $orderLine->getSubtotal());

        self::assertSame('Accepted', $estimate->getStatus());
        self::assertSame($order, $estimate->getConvertedOrder());

        $persisted = $this->em->getRepository(SalesOrder::class)->findOneBy(['orderNumber' => 'HD1']);
        self::assertNotNull($persisted);
        self::assertCount(1, $persisted->getLines());
    }

    /**
     * The order must inherit the identity the estimate was quoted under, not re-read the live
     * company. Re-reading would let a rebrand between quoting and acceptance produce an order naming
     * a party the quote never named — the same drift the frozen addresses avoid.
     */
    public function testConvertInheritsTheEstimatesFrozenCompanyIdentity(): void
    {
        $estimate = $this->fullyPricedEstimate();

        $this->company->setName('Globex Incorporated')->setPrimaryEmail('accounts@globex.example');
        $this->em->flush();

        $order = $this->service->convert($estimate, $this->em);

        self::assertSame('Acme Co', $order->getCompanyIdentity()->getName());
        self::assertSame($estimate->getCompanySnapshot(), $order->getCompanySnapshot());
        self::assertSame('Globex Incorporated', $order->getCompany()->getName(), 'the live relationship still points at the account');
    }

    public function testConvertAssignsSequentialOrderNumbersAcrossCalls(): void
    {
        $first = $this->fullyPricedEstimate();
        $this->service->convert($first, $this->em);
        $this->em->flush();

        $second = $this->fullyPricedEstimate();
        $order = $this->service->convert($second, $this->em);
        $this->em->flush();

        self::assertSame('HD2', $order->getOrderNumber());
    }

    /**
     * The plain double-submit: the estimate this request holds already carries its order, so there
     * is nothing to redo. Refused inside convert() rather than only in the caller so every entry
     * point is covered, and refused with its own exception type so a caller can tell it apart from
     * the misuse the other two guards report.
     */
    public function testConvertRefusesAnEstimateThatAlreadyHasAnOrder(): void
    {
        $estimate = $this->fullyPricedEstimate();
        $this->service->convert($estimate, $this->em);
        $this->em->flush();

        // Back to Priced without clearing the order — the state a caller that only checked the
        // status would happily convert a second time.
        $estimate->setStatus('Priced', DocumentActor::system());
        $this->em->flush();

        $this->expectException(EstimateAlreadyConvertedException::class);
        $this->expectExceptionMessage('has already been converted to an order');

        $this->service->convert($estimate, $this->em);
    }

    /**
     * The race the in-memory guard cannot see: another request converted this estimate after this
     * one loaded it, so the copy in hand still reads Priced with no order. The claim is a
     * conditional UPDATE, so it changes no rows here and the conversion is refused — crucially
     * before an order exists to be orphaned or to hold its own reservation.
     *
     * The winner's COMMITTED state is what this stages, and that is the whole state: status moved
     * AND converted_order_id set. It used to stage only the status, which was enough while the
     * claim keyed on Priced. It is not enough any more and must not be: "Accepted with no order" is
     * now a real resting state — a quote an admin accepted and nobody has converted yet — and a
     * claim that refused it would refuse the admin's Convert button outright. So the id is what
     * settles the race now; see claimForConversion()'s docblock for why that still serialises.
     */
    public function testConvertRefusesWhenTheRowWasAlreadyClaimedByAnotherRequest(): void
    {
        $estimate = $this->fullyPricedEstimate();

        $winnersOrder = (new SalesOrder())->setOrderNumber('HD-WINNER')->setCompany($this->company);
        $this->em->persist($winnersOrder);
        $this->em->flush();

        $this->em->getConnection()->executeStatement(
            'UPDATE estimate SET status = ?, converted_order_id = ? WHERE id = ?',
            ['Accepted', $winnersOrder->getId(), $estimate->getId()],
        );

        self::assertSame('Priced', $estimate->getStatus(), 'the in-memory copy is stale, which is the point');
        self::assertNull($estimate->getConvertedOrder(), 'and it cannot see the winner\'s order either');

        try {
            $this->service->convert($estimate, $this->em);
            self::fail('convert() should have refused an estimate another request had already claimed');
        } catch (EstimateAlreadyConvertedException) {
            // expected
        }

        // The winner's order, and only the winner's: the loser wrote nothing.
        self::assertSame(
            [$winnersOrder],
            $this->em->getRepository(SalesOrder::class)->findAll(),
            'no order may be left behind by the losing request',
        );
    }

    /**
     * Conversion is the most consequential thing that happens to either document, and used to leave
     * no trace on either — a converted order's history opened empty with nothing saying where it
     * came from.
     */
    public function testConvertLogsTheConversionOnBothDocuments(): void
    {
        $estimate = $this->fullyPricedEstimate();

        $order = $this->service->convert($estimate, $this->em, 'Jane Buyer, jane@example.test (7)');
        $this->em->flush();

        // Read back through the repositories rather than the entities' own collections: the logs
        // are persisted directly (as everywhere else in this app), so the collections already held
        // in memory here don't know about them until a fresh load.
        // Three rows now, not one, and each is a real event the timeline used to miss. The status
        // seam writes a row from setStatus() itself, so the fixture's own Draft -> Priced move and
        // the Priced -> Accepted move conversion performs both show up. Asserted by content rather
        // than by position, because two of the three are written in the same flush.
        $estimateLogs = $this->narrativeLogs('Estimate', $estimate->getId());
        $estimateComments = array_map(static fn (AuditLog $log): string => $log->getSummary(), $estimateLogs);
        self::assertEqualsCanonicalizing([
            'Status changed from Draft to Priced.',
            'Estimate accepted and converted to an order.',
            'Quote accepted; converted to order HD1.',
        ], $estimateComments);

        $conversionEntry = array_values(array_filter(
            $estimateLogs,
            static fn (AuditLog $log): bool => $log->getSummary() === 'Quote accepted; converted to order HD1.',
        ))[0];
        self::assertSame('System', $conversionEntry->getAction());
        self::assertFalse($conversionEntry->isRecipientNotified());
        self::assertSame('Jane Buyer, jane@example.test (7)', $conversionEntry->getActorName());

        // And the move conversion itself makes is signed by the same caller rather than by nobody,
        // which is the whole reason setStatus() takes an actor.
        $acceptEntry = array_values(array_filter(
            $estimateLogs,
            static fn (AuditLog $log): bool => $log->getSummary() === 'Estimate accepted and converted to an order.',
        ))[0];
        self::assertSame('Jane Buyer, jane@example.test (7)', $acceptEntry->getActorName());

        // The order's timeline carries more than the conversion marker now: approve() signs its own
        // entry and SalesOrderStatusDeriver signs the Approved -> Invoiced move the shadow invoice
        // caused (#539 stage 2). So this asserts the entries it is about are there and say what they
        // always said, rather than that they are the only ones.
        $orderLogs = $this->narrativeLogs('SalesOrder', $order->getId());
        $comments = array_map(static fn (AuditLog $log): string => $log->getSummary(), $orderLogs);

        $marker = sprintf('Created from quote %s.', $estimate->getDocumentNumber());
        self::assertContains($marker, $comments);
        self::assertContains('Order approved.', $comments);
        self::assertContains('Order status changed from Approved to Invoiced.', $comments);

        $conversionLog = self::logWithComment($orderLogs, $marker);
        self::assertSame('System', $conversionLog->getAction());
        self::assertFalse($conversionLog->isRecipientNotified());
        self::assertSame('Jane Buyer, jane@example.test (7)', $conversionLog->getActorName());

        // approve() credits the same actor the conversion marker does — one acceptance, one name.
        self::assertSame('Jane Buyer, jane@example.test (7)', self::logWithComment($orderLogs, 'Order approved.')->getActorName());
    }

    /** @return list<AuditLog> the narrative timeline for one document, oldest first */
    private function narrativeLogs(string $entityType, int $entityId): array
    {
        return $this->em->getRepository(AuditLog::class)->findBy(
            ['entityType' => $entityType, 'entityId' => $entityId, 'actorType' => 'document'],
            ['id' => 'ASC'],
        );
    }

    /** @param list<AuditLog> $logs */
    private static function logWithComment(array $logs, string $comment): AuditLog
    {
        $matches = array_values(array_filter($logs, static fn (AuditLog $log): bool => $log->getSummary() === $comment));
        self::assertCount(1, $matches, sprintf('expected exactly one "%s" entry', $comment));

        return $matches[0];
    }

    /** Nothing about the service knows who is signed in, so an unattributed call is still logged. */
    public function testConversionLogsFallBackToSystemWhenNoActorIsGiven(): void
    {
        $estimate = $this->fullyPricedEstimate();

        $this->service->convert($estimate, $this->em);
        $this->em->flush();

        $estimateLogs = $this->narrativeLogs('Estimate', $estimate->getId());
        self::assertSame('System', $estimateLogs[0]->getActorName());
    }

    /**
     * #369: a quote's own history — questions, replies, status notes recorded while it was still
     * a quote — has to travel onto the order's Activity Log, not just the conversion marker.
     */
    public function testConvertCopiesTheEstimatesExistingLogsOntoTheOrder(): void
    {
        $estimate = $this->fullyPricedEstimate();

        $estimate->queueActivityLogEntry()
            ->setUserName('Jane Buyer')
            ->setComment('Can you rush this?')
            ->setType('Customer')
            ->setRecipientNotified(false);
        $estimate->queueActivityLogEntry()
            ->setUserName('Ada Admin')
            ->setComment('Confirmed, shipping Friday.')
            ->setType('System')
            ->setRecipientNotified(true);
        $this->em->flush();

        // Detach and re-fetch, so convert() receives a fresh entity the same way the controller's
        // repository-fetched estimate does, rather than one still holding the just-queued entries in
        // memory.
        $estimateId = $estimate->getId();
        $this->em->clear();
        $estimate = $this->em->getRepository(Estimate::class)->find($estimateId);

        $order = $this->service->convert($estimate, $this->em, 'Ada Admin');
        $this->em->flush();

        $orderLogs = $this->narrativeLogs('SalesOrder', $order->getId());
        $comments = array_map(static fn (AuditLog $log): string => $log->getSummary(), $orderLogs);

        // Not an exact count: approve() and the derived-status subscriber each write their own
        // entry too (#539 stage 2). What matters here is that the quote's own history travelled.
        self::assertContains('Can you rush this?', $comments);
        self::assertContains('Confirmed, shipping Friday.', $comments);
        self::assertContains(sprintf('Created from quote %s.', $estimate->getDocumentNumber()), $comments);
        self::assertSame(
            2,
            count(array_filter($comments, static fn (string $comment): bool => in_array($comment, ['Can you rush this?', 'Confirmed, shipping Friday.'], true))),
            'each quote entry is copied once, not once per pass over the collection',
        );

        $copiedCustomerNote = array_values(array_filter($orderLogs, static fn (AuditLog $log): bool => $log->getSummary() === 'Can you rush this?'))[0];
        self::assertSame('Jane Buyer', $copiedCustomerNote->getActorName());
        self::assertSame('Customer', $copiedCustomerNote->getAction());
        self::assertFalse($copiedCustomerNote->isRecipientNotified());

        $copiedAdminNote = array_values(array_filter($orderLogs, static fn (AuditLog $log): bool => $log->getSummary() === 'Confirmed, shipping Friday.'))[0];
        self::assertTrue($copiedAdminNote->isRecipientNotified());

        // The quote's own log is untouched — this is a copy, not a move. Five rows rather than
        // three since the status seam: the two notes above, the fixture's Draft -> Priced move, the
        // Priced -> Accepted move conversion performs, and the conversion marker. What matters here
        // is that the two notes are still on the quote after being copied.
        $estimateLogs = $this->narrativeLogs('Estimate', $estimateId);
        $estimateComments = array_map(static fn (AuditLog $log): string => $log->getSummary(), $estimateLogs);
        self::assertCount(5, $estimateLogs);
        self::assertContains('Can you rush this?', $estimateComments);
        self::assertContains('Confirmed, shipping Friday.', $estimateComments);
    }

    /**
     * #539 stage 1: an accepted quote produces an order AND the invoice that shadows it, from the
     * one service every other creation path uses. convert() persists but never flushes, so the pair
     * reaches the database under the caller's transaction or not at all.
     */
    public function testConvertRaisesTheOrdersInvoiceAlongsideIt(): void
    {
        $estimate = $this->fullyPricedEstimate();

        $order = $this->service->convert($estimate, $this->em);
        $this->em->flush();

        $invoices = $this->em->getRepository(Invoice::class)->findBy(['salesOrder' => $order]);
        self::assertCount(1, $invoices, 'exactly one invoice per order is the whole promise of stage 1');

        $invoice = $invoices[0];
        self::assertSame('INV-1', $invoice->getDocumentNumber());
        // The order was approved on conversion (nothing on these lines consumes stock), so
        // intentForOrderStatus() asked for it to be issued rather than left Draft.
        self::assertSame('Pending', $invoice->getStatus());
        self::assertSame($this->company, $invoice->getCompany());
        self::assertSame($order->getSubtotal(), $invoice->getSubtotal());
        self::assertSame($order->getTax(), $invoice->getTax());
        self::assertSame($order->getTotal(), $invoice->getTotal());
        self::assertSame($order->getFeeLines(), $invoice->getFeeLines());
        self::assertSame($order->getPoNumber(), $invoice->getPoNumber());
        self::assertSame($order->getShippingMethod(), $invoice->getShippingMethod());
        self::assertSame($order->getFulfillmentRegion(), $invoice->getFulfillmentRegion());
        // Derived, and Not Paid because nothing has been paid against it — not because the order
        // said so (#539 stage 4: the order has no payment status any more).
        self::assertSame(InvoicePaymentStatus::NotPaid, $invoice->getPaymentStatus());

        self::assertCount(1, $invoice->getLines());
        $invoiceLine = $invoice->getLines()->first();
        self::assertSame('Widget', $invoiceLine->getName());
        self::assertSame('2.00', $invoiceLine->getQuantity());
        self::assertSame('50.00', $invoiceLine->getPrice());
        // Attributed to the order row it bills, which is what lets stage 2 derive uninvoiced qty
        // per line instead of matching SKUs after the fact.
        self::assertSame($order->getLines()->first(), $invoiceLine->getSalesOrderLine());
    }

    /**
     * The invoice inherits the QUOTED identity, not the live company's — the same rule the order
     * itself follows, and the reason the copy goes through copyCompanySnapshotFrom() rather than
     * re-reading Company.
     */
    public function testTheInvoiceInheritsTheQuotedCompanyIdentity(): void
    {
        $estimate = $this->fullyPricedEstimate();

        $this->company->setName('Globex Incorporated');
        $this->em->flush();

        $order = $this->service->convert($estimate, $this->em);
        $this->em->flush();

        $invoice = $this->em->getRepository(Invoice::class)->findOneBy(['salesOrder' => $order]);
        self::assertSame('Acme Co', $invoice->getCompanyIdentity()->getName());
        self::assertSame($order->getCompanySnapshot(), $invoice->getCompanySnapshot());
    }

    /** Invoice numbers run in their own sequence, independent of the order numbers beside them. */
    public function testInvoiceNumbersAreSequentialAndIndependentOfOrderNumbers(): void
    {
        $first = $this->service->convert($this->fullyPricedEstimate(), $this->em);
        $this->em->flush();
        $second = $this->service->convert($this->fullyPricedEstimate(), $this->em);
        $this->em->flush();

        self::assertSame('HD1', $first->getOrderNumber());
        self::assertSame('HD2', $second->getOrderNumber());
        self::assertSame('INV-1', $this->em->getRepository(Invoice::class)->findOneBy(['salesOrder' => $first])->getDocumentNumber());
        self::assertSame('INV-2', $this->em->getRepository(Invoice::class)->findOneBy(['salesOrder' => $second])->getDocumentNumber());
    }

    /**
     * The real tax service, wired with no calculators — which is the honest fixture here: a tax
     * bundle contributes rates, and an instance with none owes no tax. What matters for these tests
     * is that the invoice goes through the same computeBreakdownFor() call the order form makes,
     * not what any particular jurisdiction charges.
     */
    private function taxBreakdown(): OrderTaxBreakdownService
    {
        $bundleStatus = $this->createStub(BundleStatusRepository::class);
        $bundleStatus->method('isActiveForInstance')->willReturn(false);

        return new OrderTaxBreakdownService(
            new TaxCalculatorResolver([], [], $bundleStatus),
            new NullLogger(),
        );
    }
}
