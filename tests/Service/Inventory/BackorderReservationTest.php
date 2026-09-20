<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Service\QuantityScale;
use App\Command\InventoryRecalcCommand;
use App\Entity\AuditLog;
use App\Entity\BackorderFulfillmentEntry;
use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\OrderInventoryReservation;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\Warehouse;
use App\Enum\SalesOrderLineFulfillmentStatus;
use App\Repository\BackorderFulfillmentEntryRepository;
use App\Service\DocumentActor;
use App\Service\Inventory\BackorderReleaseService;
use App\Service\Inventory\BackorderSplitResolver;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Backorder through the real path (#548): persist and flush a SalesOrder, and let
 * InventoryReconciliationSubscriber do what it does on every write.
 *
 * Deliberately not calling the reconciler directly, for the reason
 * InventoryReservationReconcilerTest states: the wiring — two subjects per order, plus the queue
 * synchroniser — is exactly what a test calling a service by hand would never catch a regression in.
 *
 * The two things every test here is really checking:
 *
 *  1. `sales_hold + backordered` equals what the order owes. The promise is a SPLIT of the hold,
 *     never a second hold on the same units, so availability reads the same number a plain oversell
 *     always did.
 *  2. With `allow_backorder` false — the state of every row until an admin says otherwise — nothing
 *     differs from a build without this feature at all.
 */
final class BackorderReservationTest extends DoctrineIntegrationTestCase
{
    private FulfillmentRegion $region;
    private Warehouse $warehouse;
    private ProductCore $product;
    private ProductInventory $inventory;
    private Company $company;
    private BackorderSplitResolver $splits;
    private BackorderReleaseService $releases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($this->region);

        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');

        $this->product = (new ProductCore())->setSku('SKU-BO')->setName('Backorderable Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($this->product);

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);

        // Six on the shelf, all through this file. Every scenario asks for ten.
        $this->inventory = (new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(6);
        $this->em->persist($this->inventory);

        $this->em->flush();

        $this->splits = self::getContainer()->get(BackorderSplitResolver::class);
        $this->releases = self::getContainer()->get(BackorderReleaseService::class);
    }

    private function allowBackorder(?int $cap = null, bool $autoRelease = false, bool $capShrinks = false): void
    {
        $this->inventory
            ->setAllowBackorder(true)
            ->setMaxBackorderQuantity($cap)
            ->setAutoReleaseOnRestock($autoRelease)
            ->setBackorderCapShrinksOnRestock($capShrinks);
        $this->em->flush();
    }

    /** An accepted order for $quantity, with the split applied the way every save path applies it. */
    private function approvedOrderFor(string $quantity): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('ORD-' . uniqid())
            ->setFulfillmentRegion($this->region->getName());

        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($this->product)
                ->setName($this->product->getName())
                ->setSku($this->product->getSku())
                ->setQuantity($quantity),
        );

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);

        $this->splits->applyToOrderLines($order, $order->getStatus(), $this->em);
        $this->em->flush();

        return $order;
    }

    private function refreshInventory(): ProductInventory
    {
        $this->em->refresh($this->inventory);

        return $this->inventory;
    }

    /** @return array{int, int, int} sales hold, backordered, available */
    private function buckets(): array
    {
        $inventory = $this->refreshInventory();

        return [
            $inventory->getSalesHoldQuantity(),
            $inventory->getBackorderedQuantity(),
            $inventory->getAvailableQuantity(),
        ];
    }

    private function entries(): BackorderFulfillmentEntryRepository
    {
        return self::getContainer()->get(BackorderFulfillmentEntryRepository::class);
    }

    // --- the invariant -----------------------------------------------------------------------

    /**
     * The proof the feature did not leak. With the flag off an over-quantity order behaves exactly
     * as it did before #548: the whole quantity sits in sales hold, nothing is promised, and
     * availability is the same negative number it always was.
     */
    public function testWithBackorderOffAnOversellIsUnchangedFromBeforeTheFeature(): void
    {
        $order = $this->approvedOrderFor('10');

        self::assertSame(['10.0000', '0.0000', '-4.0000'], $this->buckets());

        $line = $order->getLines()->first();
        self::assertSame('0.0000', $line->getBackorderedUnits());
        self::assertSame(SalesOrderLineFulfillmentStatus::Fulfilled->value, $line->getFulfillmentStatus());
        self::assertCount(0, $this->entries()->openForOrder($order), 'nothing was promised, so no episode was opened');
        self::assertCount(
            0,
            $this->em->getRepository(OrderInventoryReservation::class)->findBy(['order' => $order, 'bucket' => OrderInventoryReservation::BUCKET_BACKORDERED]),
            'no ledger row in a bucket nothing may hold',
        );
    }

    // --- the split ---------------------------------------------------------------------------

    /**
     * The core arrangement: six real units held as stock, four held as a promise, and availability
     * reading exactly what it read when all ten sat in one bucket. The split changes the
     * attribution, not the total.
     */
    public function testAnOptedInOrderSplitsItsHoldWithoutChangingAvailability(): void
    {
        $this->allowBackorder();
        $order = $this->approvedOrderFor('10');

        self::assertSame(['6.0000', '4.0000', '-4.0000'], $this->buckets());

        $line = $order->getLines()->first();
        self::assertSame('4.0000', $line->getBackorderedUnits());
        self::assertSame(SalesOrderLineFulfillmentStatus::PartiallyBackordered->value, $line->getFulfillmentStatus());
    }

    public function testALineWithNoStockAtAllIsWhollyBackordered(): void
    {
        $this->inventory->setQuantity(0);
        $this->allowBackorder();

        $order = $this->approvedOrderFor('10');

        self::assertSame(['0.0000', '10.0000', '-10.0000'], $this->buckets());
        self::assertSame(SalesOrderLineFulfillmentStatus::Backordered->value, $order->getLines()->first()->getFulfillmentStatus());
    }

    /**
     * The cap bounds the bucket across every order, not each order separately - and what will not
     * fit under it is refused outright rather than trimmed. The refusal itself is
     * AdminOrderStockValidator's job, so what is asserted here is the answer it acts on.
     */
    public function testAskingForMoreThanTheCapAllowsIsRefusedRatherThanTrimmed(): void
    {
        $this->allowBackorder(cap: 3);

        $split = $this->splits->splitFor($this->inventory, '10', $this->inventory->getAvailableQuantity());

        self::assertSame('6.0000', $split->fulfilled);
        self::assertSame('3.0000', $split->backordered);
        self::assertSame('1.0000', $split->uncovered, 'the tenth unit is neither stocked nor promisable');
        self::assertFalse($split->isFullyCovered());
    }

    /** An order that fits under the cap splits normally and fills the bucket to the ceiling. */
    public function testAnOrderThatFitsUnderTheCapFillsItExactly(): void
    {
        $this->allowBackorder(cap: 3);

        $order = $this->approvedOrderFor('9');

        self::assertSame(['6.0000', '3.0000', '-3.0000'], $this->buckets());
        self::assertSame('3.0000', $order->getLines()->first()->getBackorderedUnits());
        self::assertSame('0.0000', $this->refreshInventory()->remainingBackorderCapacity(), 'the ceiling is now full for everyone else');
    }

    /**
     * The mistake this exists to prevent: an order that re-saves unchanged must not refuse itself
     * for the promise it already holds, the same way #214 was a cart blocking itself with its own
     * hold.
     */
    public function testRe_splittingAnUnchangedOrderIsStable(): void
    {
        $this->allowBackorder(cap: 4);
        $order = $this->approvedOrderFor('10');

        self::assertSame(['6.0000', '4.0000', '-4.0000'], $this->buckets());

        $this->splits->applyToOrderLines($order, $order->getStatus(), $this->em);
        $this->em->flush();

        self::assertSame(['6.0000', '4.0000', '-4.0000'], $this->buckets(), 'the order must see its own promise as its own');
        self::assertSame('4.0000', $order->getLines()->first()->getBackorderedUnits());
    }

    /** A Draft has committed to nothing, so it promises nothing — and neither does a voided order. */
    public function testADraftPromisesNothing(): void
    {
        $this->allowBackorder();

        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('ORD-' . uniqid())
            ->setFulfillmentRegion($this->region->getName());
        $order->addLine((new SalesOrderLine())->setProduct($this->product)->setName('X')->setQuantity('10'));
        $this->em->persist($order);

        $this->splits->applyToOrderLines($order, $order->getStatus(), $this->em);
        $this->em->flush();

        self::assertSame(['0.0000', '0.0000', '6.0000'], $this->buckets());
        self::assertSame('0.0000', $order->getLines()->first()->getBackorderedUnits());
    }

    public function testVoidingAnOrderWithdrawsThePromiseAndClosesTheEpisode(): void
    {
        $this->allowBackorder();
        $order = $this->approvedOrderFor('10');

        self::assertCount(1, $this->entries()->openForOrder($order));

        $order->setStatus('Void', DocumentActor::system());
        $this->em->flush();

        self::assertSame(['0.0000', '0.0000', '6.0000'], $this->buckets(), 'a voided order owes nothing, missing units included');
        self::assertCount(0, $this->entries()->openForOrder($order));
    }

    // --- the invoice side ---------------------------------------------------------------------

    /**
     * The case the checkout path makes ordinary, and the one that would double-count without the
     * netting in InvoiceReservationSubject: an order invoiced IN FULL for a partly backordered
     * line. The invoice bills all ten because the customer paid for all ten; only six of them
     * exist, so only six may be held as stock.
     */
    public function testAnInvoiceBillingAPromiseDoesNotHoldItTwice(): void
    {
        $this->allowBackorder();
        $order = $this->approvedOrderFor('10');

        $invoice = new Invoice();
        $order->addInvoice($invoice);
        $invoice
            ->setCompany($this->company)
            ->setDocumentNumber('INV-' . uniqid())
            ->setFulfillmentRegion($order->getFulfillmentRegion())
            ->setTotal('100.00');

        $line = $order->getLines()->first();
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($line)
                ->setProduct($this->product)
                ->setName($line->getName())
                ->setQuantity('10'),
        );
        $this->em->persist($invoice);
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        $inventory = $this->refreshInventory();

        // Six held by the invoice that bills them, four still promised by the order, and the same
        // -4 availability as before anything was invoiced.
        self::assertSame('0.0000', $inventory->getSalesHoldQuantity(), 'the order has billed everything it could');
        self::assertSame('6.0000', $inventory->getPendingQuantity());
        self::assertSame('4.0000', $inventory->getBackorderedQuantity());
        self::assertSame('-4.0000', $inventory->getAvailableQuantity(), 'not -8: the promise is held once');
    }

    // --- the queue --------------------------------------------------------------------------

    public function testAnEpisodeOpensWhenTheLineGoesShortAndClosesWhenItIsWhole(): void
    {
        $this->allowBackorder();
        $order = $this->approvedOrderFor('10');

        $open = $this->entries()->openForOrder($order);
        self::assertCount(1, $open);
        self::assertSame(BackorderFulfillmentEntry::STATUS_OPEN, $open[0]->getStatus());
        self::assertSame($this->warehouse->getId(), $open[0]->getWarehouse()?->getId());
        self::assertNull($open[0]->getCompletedAt());

        $this->releases->applyRelease($order->getLines()->first(), 4, 'admin@example.test', $this->em);
        $this->em->flush();

        self::assertCount(0, $this->entries()->openForOrder($order));

        $all = $this->entries()->search([], 1, 200)['rows'];
        self::assertCount(1, $all, 'the episode is completed, never deleted — the queue has to be able to show it');
        self::assertSame(BackorderFulfillmentEntry::STATUS_COMPLETE, $all[0]->getStatus());
        self::assertNotNull($all[0]->getCompletedAt());
    }

    /** At most one open episode per line, enforced in code because SQLite has no partial unique index here. */
    public function testALineNeverAccumulatesASecondOpenEpisode(): void
    {
        $this->allowBackorder();
        $order = $this->approvedOrderFor('10');

        // Three more writes to the same order, each of which re-runs the synchroniser.
        for ($i = 0; $i < 3; $i++) {
            $order->getLines()->first()->setLocation($this->region->getName());
            $this->em->flush();
        }

        self::assertCount(1, $this->entries()->openForOrder($order));
    }

    // --- release ----------------------------------------------------------------------------

    /**
     * A release moves units between buckets and nothing else: it writes one number on the line and
     * lets the flush reconcile, which is what keeps the manual and automatic routes identical.
     */
    public function testAManualReleaseMovesThePromiseIntoTheStockHold(): void
    {
        $this->allowBackorder();
        $order = $this->approvedOrderFor('10');

        // Four arrive.
        $this->inventory->setQuantity(10);
        $this->em->flush();

        self::assertSame('4.0000', $this->refreshInventory()->getStockAvailableToRelease(), 'the arrivals are what the queue may draw on');

        $released = $this->releases->applyRelease($order->getLines()->first(), 4, 'admin@example.test', $this->em);
        $this->em->flush();

        self::assertSame('4.0000', $released);
        self::assertSame(['10.0000', '0.0000', '0.0000'], $this->buckets());
        self::assertSame(SalesOrderLineFulfillmentStatus::Fulfilled->value, $order->getLines()->first()->getFulfillmentStatus());
    }

    public function testAReleaseIsClampedToWhatIsActuallyOutstanding(): void
    {
        $this->allowBackorder();
        $order = $this->approvedOrderFor('10');
        $this->inventory->setQuantity(100);
        $this->em->flush();

        self::assertSame('4.0000', $this->releases->applyRelease($order->getLines()->first(), 999, 'admin@example.test', $this->em));
        $this->em->flush();

        self::assertSame('0.0000', $order->getLines()->first()->getBackorderedUnits());
    }

    public function testAReleaseIsWrittenToTheOrdersOwnTimeline(): void
    {
        $this->allowBackorder();
        $order = $this->approvedOrderFor('10');
        $this->inventory->setQuantity(8);
        $this->em->flush();

        $this->releases->applyRelease($order->getLines()->first(), 2, 'admin@example.test', $this->em);
        $this->em->flush();

        $logs = $this->em->getRepository(AuditLog::class)->findBy(['entityType' => 'SalesOrder', 'entityId' => $order->getId(), 'actorType' => 'document']);
        $comments = array_map(static fn (AuditLog $log): string => $log->getSummary(), $logs);

        self::assertNotEmpty(array_filter($comments, static fn (string $c): bool => str_contains($c, 'Backorder release: 2 unit(s)')));
        self::assertNotEmpty(array_filter($comments, static fn (string $c): bool => str_contains($c, '2 still backordered')));
    }

    // --- restock ----------------------------------------------------------------------------

    /**
     * The four independent combinations of the two restock toggles. Both default off, which is
     * today's behaviour: a restock changes the stock figure and nothing else.
     *
     * @return iterable<string, array{bool, bool, int, int, ?int}>
     */
    public static function restockToggles(): iterable
    {
        //                            autoRelease, capShrinks, expected backordered, expected salesHold, expected cap
        yield 'neither' =>          [false, false, '4.0000', '6.0000', '20.0000'];
        yield 'auto-release only' => [true,  false, '0.0000', '10.0000', '20.0000'];
        yield 'cap-shrink only' =>   [false, true,  '4.0000', '6.0000', '16.0000'];
        yield 'both' =>              [true,  true,  '0.0000', '10.0000', '16.0000'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('restockToggles')]
    public function testTheTwoRestockTogglesActIndependently(
        bool $autoRelease,
        bool $capShrinks,
        string $expectedBackordered,
        string $expectedSalesHold,
        ?string $expectedCap,
    ): void {
        $this->allowBackorder(cap: 20, autoRelease: $autoRelease, capShrinks: $capShrinks);
        $order = $this->approvedOrderFor('10');

        self::assertSame(['6.0000', '4.0000', '-4.0000'], $this->buckets());

        // Four arrive, through the one hook both restock call sites use.
        $previous = $this->inventory->getQuantity();
        $this->inventory->setQuantity($previous + 4);
        $this->releases->applyRestock($this->inventory, $previous, $this->em);
        $this->em->flush();

        [$salesHold, $backordered] = $this->buckets();

        self::assertSame($expectedBackordered, $backordered);
        self::assertSame($expectedSalesHold, $salesHold);
        self::assertSame($expectedCap, $this->refreshInventory()->getMaxBackorderQuantity());
        self::assertSame(QuantityScale::canonical($expectedBackordered), $order->getLines()->first()->getBackorderedUnits());
    }

    /** An automatic release is attributed to 'System', and its episode shows up already Complete. */
    public function testAnAutomaticReleaseResolvesTheEpisodeAsSystem(): void
    {
        $this->allowBackorder(autoRelease: true);
        $order = $this->approvedOrderFor('10');

        $previous = $this->inventory->getQuantity();
        $this->inventory->setQuantity($previous + 4);
        $this->releases->applyRestock($this->inventory, $previous, $this->em);
        $this->em->flush();

        $all = $this->entries()->search([], 1, 200)['rows'];
        self::assertCount(1, $all);
        self::assertSame(BackorderFulfillmentEntry::STATUS_COMPLETE, $all[0]->getStatus());

        $logs = $this->em->getRepository(AuditLog::class)->findBy(['entityType' => 'SalesOrder', 'entityId' => $order->getId(), 'actorType' => 'document']);
        $byServiceUser = array_filter(
            $logs,
            static fn (AuditLog $log): bool => str_contains($log->getSummary(), 'assigned by System'),
        );
        self::assertNotEmpty($byServiceUser, 'the automatic path names System as the resolver');
    }

    /** A stock correction downward is not a restock and must never shrink a cap or release anything. */
    public function testLoweringTheStockFigureIsNotARestock(): void
    {
        $this->allowBackorder(cap: 20, autoRelease: true, capShrinks: true);
        $this->approvedOrderFor('10');

        $previous = $this->inventory->getQuantity();
        $this->inventory->setQuantity(2);
        $this->releases->applyRestock($this->inventory, $previous, $this->em);
        $this->em->flush();

        self::assertSame('20.0000', $this->refreshInventory()->getMaxBackorderQuantity());
        self::assertSame('4.0000', $this->refreshInventory()->getBackorderedQuantity());
    }

    /**
     * Manual mode is the default, and the point of it: stock arrives, nothing moves, and an admin
     * decides. The hourly recalc must not "fix" that either — see the next test.
     */
    public function testInManualModeARestockLeavesTheQueueAlone(): void
    {
        $this->allowBackorder();
        $order = $this->approvedOrderFor('10');

        $previous = $this->inventory->getQuantity();
        $this->inventory->setQuantity($previous + 10);
        $this->releases->applyRestock($this->inventory, $previous, $this->em);
        $this->em->flush();

        self::assertSame('4.0000', $this->refreshInventory()->getBackorderedQuantity());
        self::assertCount(1, $this->entries()->openForOrder($order));
    }

    // --- the hourly recalc --------------------------------------------------------------------

    /**
     * The recalc recomputes all three buckets from the orders and invoices. Run against a correct
     * cache it has to agree with it exactly — a recalc that disagreed with the incremental path
     * would rewrite a right answer every hour, which is the #539 mistake in a new place.
     */
    public function testTheHourlyRecalcAgreesWithTheIncrementalSplit(): void
    {
        $this->allowBackorder();
        $this->approvedOrderFor('10');

        self::assertSame(['6.0000', '4.0000', '-4.0000'], $this->buckets());

        $this->runRecalc();

        self::assertSame(['6.0000', '4.0000', '-4.0000'], $this->buckets());
    }

    /** And it corrects a cache someone has broken, rather than leaving it wrong forever. */
    public function testTheHourlyRecalcCorrectsADriftedBackorderedBucket(): void
    {
        $this->allowBackorder();
        $this->approvedOrderFor('10');

        // Drift, as a crashed flush or a direct database edit would leave it.
        $this->inventory->setBackorderedQuantity(99);
        $this->em->flush();

        $this->runRecalc();

        self::assertSame(['6.0000', '4.0000', '-4.0000'], $this->buckets());
    }

    private function runRecalc(): void
    {
        $command = self::getContainer()->get(InventoryRecalcCommand::class);
        $command->run(new ArrayInput([]), new BufferedOutput());

        $this->em->clear();
        $found = $this->em->getRepository(ProductInventory::class)->find($this->inventory->getId());
        self::assertInstanceOf(ProductInventory::class, $found);
        $this->inventory = $found;
    }
}
