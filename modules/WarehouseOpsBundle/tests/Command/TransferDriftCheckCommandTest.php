<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Command;

use App\Entity\BundleStatus;
use App\Entity\FulfillmentRegion;
use App\Entity\InventoryReconciliationDiscrepancy;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\WarehouseFulfillmentRegionService;
use InventoryDepthBundle\Entity\InventoryDetail;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use WarehouseOpsBundle\Command\TransferDriftCheckCommand;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Entity\TransferOrderLine;
use WarehouseOpsBundle\Tests\Support\WarehouseOpsTestCase;
use WarehouseOpsBundle\Transfer\TransferOrderService;

/**
 * The transfer drift check (#587): documents against buckets, documents against in-transit rows.
 *
 * ## Every transfer here is CONDUCTED, never asserted
 *
 * No test below writes a `transfer_order_line` quantity or a `product_inventory` column to set up
 * its "correct" state. Each one dispatches and receipts through TransferOrderService, the same
 * service the screens call, and then asks the check what it thinks. That matters more here than in
 * most tests: the numbers this command compares are exactly the numbers a fixture would have to
 * hand-write, so a hand-written fixture would be asserting the command agrees with the test author
 * rather than with the app.
 *
 * Drift is then introduced the only way drift ever happens in the wild — a row edited behind the
 * document's back, through raw SQL, bypassing the service, the listeners and the change log.
 *
 * ## The cumulative trap
 *
 * `transfer_out_quantity` is a running total of every dispatch, not the size of the last one
 * (#584). testTwoDispatchesAccumulateAndTheCheckStaysQuiet() is the guard: 4 then 12 leaves the
 * column reading 16, and a check that expected 12 — the amount that just moved — would call a
 * perfectly correct warehouse drifted.
 */
final class TransferDriftCheckCommandTest extends WarehouseOpsTestCase
{
    private TransferOrderService $transfers;
    private Warehouse $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transfers = self::getContainer()->get(TransferOrderService::class);

        $east = (new FulfillmentRegion())->setName('East');
        $this->em->persist($east);
        $this->destination = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($east, 'BC', 'CA');
        $this->em->flush();

        $this->receive(50, $this->binA);
    }

    private function check(): CommandTester
    {
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('app:warehouse-ops:transfer-check')
        );
        $tester->execute([]);

        return $tester;
    }

    /**
     * One transfer, conducted end to end: dispatch everything requested, receipt what arrived.
     *
     * @return TransferOrderLine the line, so a test can name it in a hand-edit
     */
    private function conduct(int $requested, int $arriving, string $number): TransferOrderLine
    {
        $transfer = (new TransferOrder())
            ->setNumber($number)
            ->setFromWarehouse($this->warehouse)
            ->setToWarehouse($this->destination)
            ->setStatus(TransferOrder::STATUS_DRAFT);

        $line = (new TransferOrderLine())
            ->setProduct($this->product)
            ->setSku($this->product->getSku())
            ->setName($this->product->getName())
            ->setQuantityRequested($requested);

        $transfer->addLine($line);
        $this->em->persist($transfer);
        $this->em->persist($line);
        $this->em->flush();

        $this->transfers->dispatch($transfer, strtolower($number), 'tester');

        if ($arriving > 0) {
            $this->transfers->receive($transfer, [$line->getId() => $arriving], [$line->getId() => null], strtolower($number), 'tester');
        }

        return $line;
    }

    /** @return list<InventoryReconciliationDiscrepancy> */
    private function findings(): array
    {
        /** @var list<InventoryReconciliationDiscrepancy> $rows */
        $rows = $this->em->getRepository(InventoryReconciliationDiscrepancy::class)->findBy([], ['id' => 'ASC']);

        return $rows;
    }

    /** @return array<string, array{cached: int, recomputed: int}> keyed by "warehouse|bucket" */
    private function findingsByBucket(): array
    {
        $out = [];
        foreach ($this->findings() as $row) {
            // As whole units, so the expectations below stay readable ints now that the discrepancy
            // columns read as decimal strings. wholeUnits() fails rather than truncates.
            $out[$row->getWarehouse()->getName() . '|' . $row->getBucket()] = [
                'cached' => $this->wholeUnits($row->getCachedQuantity()),
                'recomputed' => $this->wholeUnits($row->getRecomputedQuantity()),
            ];
        }

        return $out;
    }

    /**
     * Raw SQL, so the edit reaches the row the way a DBA or a broken import would: unobserved.
     *
     * @param array<string, mixed> $params
     */
    private function behindTheDocumentsBack(string $sql, array $params): void
    {
        $this->em->flush();
        $this->em->getConnection()->executeStatement($sql, $params);
        $this->em->clear();

        $product = $this->em->getRepository(ProductCore::class)->find($this->product->getId());
        $west = $this->em->getRepository(Warehouse::class)->find($this->warehouse->getId());
        $east = $this->em->getRepository(Warehouse::class)->find($this->destination->getId());

        self::assertNotNull($product);
        self::assertNotNull($west);
        self::assertNotNull($east);

        $this->product = $product;
        $this->warehouse = $west;
        $this->destination = $east;
    }

    private function inventory(Warehouse $warehouse): ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product,
            'warehouse' => $warehouse,
        ]);
        self::assertInstanceOf(ProductInventory::class, $row);

        return $row;
    }

    public function testNoTransfersAtAllIsNotDrift(): void
    {
        $tester = $this->check();
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('nothing to check', $tester->getDisplay());
        self::assertSame([], $this->findings());
    }

    /**
     * A transfer where 12 left and only 10 arrived. Nothing here is drift.
     *
     * This is the case most likely to be reported wrongly, so it is asserted first: the two missing
     * units are not a disagreement between any two records. `transfer_out` says 12 left, which is
     * true; `transfer_in` says 10 arrived, which is true; and the source's in-transit row still
     * holds the 2 that never turned up, which is where lost stock belongs until somebody writes it
     * off. A check that treated the 12/10 gap as an error would fire on every short arrival in the
     * business.
     */
    public function testAShortArrivalIsAccountedFor_NotDrift(): void
    {
        $line = $this->conduct(12, 10, 'TR-CLEAN');

        self::assertSame('12.0000', $line->getQuantityDispatched());
        self::assertSame('10.0000', $line->getQuantityReceived());
        self::assertSame('12.0000', $this->inventory($this->warehouse)->getTransferOutQuantity());
        self::assertSame('10.0000', $this->inventory($this->destination)->getTransferInQuantity());
        self::assertSame(
            '2.0000',
            $this->details->inTransitTotal($this->product, $this->warehouse),
            'the two that never arrived still stand on the source warehouse\'s in-transit row',
        );

        $tester = $this->check();
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('all agree', $tester->getDisplay());
        self::assertSame([], $this->findings(), 'a short arrival is a document saying what happened, not a disagreement');
    }

    /**
     * The cumulative column, proven by conducting two transfers rather than by asserting a number.
     *
     * 4 units leave and arrive; then 12 more leave and arrive. `transfer_out_quantity` reads 16 —
     * the running total — and the check has to expect 16. Anything that subtracted the 12 that just
     * moved, or reset the column to it, would report this warehouse as drifted by 4 or by 12 while
     * it is exactly right.
     */
    public function testTwoDispatchesAccumulateAndTheCheckStaysQuiet(): void
    {
        $this->conduct(4, 4, 'TR-ACC-1');
        self::assertSame('4.0000', $this->inventory($this->warehouse)->getTransferOutQuantity(), 'product_inventory.transfer_out_quantity after the first transfer');

        $this->conduct(12, 12, 'TR-ACC-2');
        self::assertSame('16.0000', $this->inventory($this->warehouse)->getTransferOutQuantity(), 'transfer_out_quantity 4 -> 16: the total moved, not the 12 that just left');
        self::assertSame('16.0000', $this->inventory($this->destination)->getTransferInQuantity());

        $tester = $this->check();
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('all agree', $tester->getDisplay());
        self::assertSame([], $this->findings());
    }

    /**
     * A `transfer_order_line` edited by hand after the fact — the exact failure #587 was opened for.
     *
     * `quantity_dispatched` 12 becomes 15 with no movement behind it, so the document now claims
     * three units left that never did. TWO records notice: the cached bucket still says 12, and the
     * source's in-transit rows still hold 2 while the document now says 5 are in flight.
     */
    public function testAHandEditedDispatchQuantityIsCaughtTwice(): void
    {
        $line = $this->conduct(12, 10, 'TR-DRIFT');
        $lineId = $line->getId();

        $this->behindTheDocumentsBack(
            'UPDATE transfer_order_line SET quantity_dispatched = 15 WHERE id = :id',
            ['id' => $lineId],
        );

        $tester = $this->check();
        $tester->assertCommandIsSuccessful();

        $found = $this->findingsByBucket();

        self::assertArrayHasKey('West|' . TransferDriftCheckCommand::BUCKET_TRANSFER_OUT, $found);
        self::assertSame(
            ['cached' => 12, 'recomputed' => 15],
            $found['West|' . TransferDriftCheckCommand::BUCKET_TRANSFER_OUT],
            'product_inventory.transfer_out_quantity still reads 12 while the document now sums to 15',
        );

        self::assertArrayHasKey('West|' . TransferDriftCheckCommand::BUCKET_IN_TRANSIT, $found);
        self::assertSame(
            ['cached' => 5, 'recomputed' => 2],
            $found['West|' . TransferDriftCheckCommand::BUCKET_IN_TRANSIT],
            'the document claims 15 - 10 = 5 in flight; inventory_detail holds 2',
        );

        self::assertArrayNotHasKey('East|' . TransferDriftCheckCommand::BUCKET_TRANSFER_IN, $found, 'the destination is untouched: nothing edited what arrived');
        self::assertCount(2, $this->findings());

        self::assertStringContainsString('NOTHING corrected', $tester->getDisplay());
        self::assertStringContainsString('CUMULATIVE', $tester->getDisplay(), 'the output has to say which rows are ordinary history');
    }

    /**
     * The mirror case: the document is untouched and the STOCK ROW was adjusted behind its back.
     *
     * Only the in-transit comparison can see this — both cached buckets still agree with the
     * document they are derived from, which is precisely why the bucket checks alone were not
     * enough and #587 needed the third comparison.
     */
    public function testAnInTransitRowAdjustedBehindTheDocumentsBackIsCaught(): void
    {
        $this->conduct(12, 10, 'TR-ROWS');

        $this->behindTheDocumentsBack(
            'UPDATE inventory_detail SET quantity = 0 WHERE status = :status',
            ['status' => InventoryDetail::STATUS_IN_TRANSIT],
        );

        $this->check()->assertCommandIsSuccessful();

        $found = $this->findingsByBucket();

        self::assertSame(
            ['cached' => 2, 'recomputed' => 0],
            $found['West|' . TransferDriftCheckCommand::BUCKET_IN_TRANSIT] ?? [],
            'the document says two are still in flight; the rows say none are',
        );
        self::assertCount(1, $this->findings(), 'neither bucket drifted: both still agree with their own document');
    }

    /**
     * A bucket zeroed by something other than the transfer service — an import, a bad migration, a
     * hand-run UPDATE.
     */
    public function testAZeroedBucketIsCaughtWithTheDocumentIntact(): void
    {
        $this->conduct(12, 12, 'TR-ZEROED');

        $this->behindTheDocumentsBack(
            'UPDATE product_inventory SET transfer_in_quantity = 0 WHERE warehouse_id = :id',
            ['id' => $this->destination->getId()],
        );

        $this->check()->assertCommandIsSuccessful();

        self::assertSame(
            ['cached' => 0, 'recomputed' => 12],
            $this->findingsByBucket()['East|' . TransferDriftCheckCommand::BUCKET_TRANSFER_IN] ?? [],
            'transfer_in_quantity 12 -> 0 with transfer_order_line.quantity_received still 12',
        );
    }

    /**
     * The rule the whole issue turns on: the check REPORTS and never repairs.
     *
     * Asserted on the rows themselves rather than on the absence of a `--fix` flag, because the
     * dangerous version of this command is one that quietly "helps".
     */
    public function testTheCheckCorrectsNothingItFinds(): void
    {
        $line = $this->conduct(12, 10, 'TR-READONLY');
        $lineId = $line->getId();

        $this->behindTheDocumentsBack(
            'UPDATE transfer_order_line SET quantity_dispatched = 15 WHERE id = :id',
            ['id' => $lineId],
        );

        $this->check();
        $this->em->clear();

        self::assertSame(
            15,
            (int) $this->em->getConnection()->fetchOne('SELECT quantity_dispatched FROM transfer_order_line WHERE id = :id', ['id' => $lineId]),
            'transfer_order_line.quantity_dispatched is left exactly as the check found it',
        );

        $west = $this->em->getRepository(ProductInventory::class)->findOneBy(['warehouse' => $this->warehouse->getId()]);
        self::assertInstanceOf(ProductInventory::class, $west);
        self::assertSame('12.0000', $west->getTransferOutQuantity(), 'product_inventory.transfer_out_quantity is NOT recomputed to 15');
        self::assertSame(
            2,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE status = :status',
                ['status' => InventoryDetail::STATUS_IN_TRANSIT],
            ),
            'and the in-transit rows are untouched',
        );
    }

    /**
     * Transfers running BOTH ways along one route, all correct.
     *
     * The regression guard for the one arithmetic mistake that would make this command useless in
     * production: "in flight" is `SUM(dispatched − received)` over each warehouse's OWN outbound
     * lines. Computing it as `dispatchedFrom(W) − receivedInto(W)` mixes two different documents,
     * and West — which sent 12 and received 6 back — would be reported as 6 units of phantom drift
     * while every row in the database is right.
     */
    public function testAWarehouseThatBothSendsAndReceivesIsNotReportedAsDrifted(): void
    {
        $this->conduct(12, 12, 'TR-BOTH-1');

        // And now the other way: East sends 6 back to West.
        $back = (new TransferOrder())
            ->setNumber('TR-BOTH-2')
            ->setFromWarehouse($this->destination)
            ->setToWarehouse($this->warehouse)
            ->setStatus(TransferOrder::STATUS_DRAFT);
        $line = (new TransferOrderLine())
            ->setProduct($this->product)
            ->setSku($this->product->getSku())
            ->setName($this->product->getName())
            ->setQuantityRequested(6);
        $back->addLine($line);
        $this->em->persist($back);
        $this->em->persist($line);
        $this->em->flush();

        $this->transfers->dispatch($back, 'tr-both-2', 'tester');
        $this->transfers->receive($back, [$line->getId() => 6], [$line->getId() => null], 'tr-both-2', 'tester');

        $tester = $this->check();
        $tester->assertCommandIsSuccessful();

        self::assertSame([], $this->findings(), 'both warehouses send and receive, and every record agrees');
        self::assertStringContainsString('all agree', $tester->getDisplay());
    }

    /**
     * WarehouseOpsBundle Inactive: the check stands down, and — the part worth asserting — it
     * destroys nothing on the way past.
     *
     * With the bundle off, BundleBucketAvailabilityGate excludes both transfer terms from
     * availability rather than zeroing them, so the columns this command compares are reaching
     * nobody. Emailing an admin hourly about them would be noise about a switched-off feature. The
     * drift is still there when the bundle comes back on, which is what the second half asserts.
     */
    public function testWithTheBundleInactiveNothingIsCheckedAndNothingIsTouched(): void
    {
        $line = $this->conduct(12, 10, 'TR-OFF');
        $lineId = $line->getId();

        $this->behindTheDocumentsBack(
            'UPDATE transfer_order_line SET quantity_dispatched = 15 WHERE id = :id',
            ['id' => $lineId],
        );

        // Switched off through the one activation path, rather than by persisting a fresh row.
        // A brand-new BundleStatus used to be safe here because nothing had created one — bundles
        // were enabled by the ABSENCE of a row. Every bundle now gets an explicit Active row in
        // setUp() (see DoctrineIntegrationTestCase), so inserting a second one for the same source
        // trips the UNIQUE index on bundle_status.source instead of switching anything off.
        self::getContainer()->get(BundleStatusRepository::class)->deactivate('WarehouseOpsBundle');
        $this->em->clear();

        $tester = $this->check();
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('Inactive', $tester->getDisplay());
        self::assertSame([], $this->findings(), 'nothing is reported about a feature the instance has switched off');
        self::assertSame(
            15,
            (int) $this->em->getConnection()->fetchOne('SELECT quantity_dispatched FROM transfer_order_line WHERE id = :id', ['id' => $lineId]),
            'and nothing is zeroed or tidied on the way past',
        );

        // Switched back on, the same drift is still there to find.
        //
        // Written as an explicit Active row, not as a DELETE. Deleting it used to mean "on", because
        // absence of a row was the enabled default; a bundle with no row is now INERT until somebody
        // activates it, so the DELETE this line replaces switched the bundle further OFF and the
        // assertion below looked for findings the command had correctly declined to make.
        $this->em->getConnection()->executeStatement(
            'UPDATE bundle_status SET status = :status WHERE source = :s',
            ['status' => BundleStatus::STATUS_ACTIVE, 's' => 'WarehouseOpsBundle'],
        );
        $this->em->clear();

        $this->check();
        self::assertCount(2, $this->findings());
    }
}
