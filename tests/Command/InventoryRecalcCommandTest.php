<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\AuditLog;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\InventoryReconciliationDiscrepancy;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Service\DocumentActor;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exercises app:inventory-recalc through the real CommandTester path against a real EntityManager,
 * since the command's whole point is recomputing Sales Hold and Pending from source of truth and
 * correcting drift between that and the InventoryReconciliationSubscriber-maintained cache — a
 * mocked EntityManager would prove nothing about that drift-correction behavior.
 */
final class InventoryRecalcCommandTest extends DoctrineIntegrationTestCase
{
    private CommandTester $tester;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(self::$kernel);
        $command = $application->find('app:inventory-recalc');
        $this->tester = new CommandTester($command);

        $this->company = (new Company())->setName('Acme Co')->setCode('ACME');
        $this->em->persist($this->company);
    }

    /**
     * An Approved order with nothing invoiced against it, so its whole quantity is uninvoiced and
     * sits in the sales hold bucket (#539 stage 3). Reached through the named action, because
     * SalesOrder has no setStatus().
     */
    /** The warehouse a region's stock comes out of (#546) — created with the region, as admin does. */
    private function warehouses(): WarehouseFulfillmentRegionService
    {
        return self::getContainer()->get(WarehouseFulfillmentRegionService::class);
    }

    private function newApprovedOrder(FulfillmentRegion $region, ProductCore $product, string $quantity, ?string $lineLocation = null): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('ORD-' . uniqid())
            ->setFulfillmentRegion($region->getName());

        $line = (new SalesOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setQuantity($quantity)
            ->setLocation($lineLocation);
        $order->addLine($line);

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');

        $this->em->persist($order);

        return $order;
    }

    public function testCorrectsDriftedSalesHoldQuantityAndLeavesOtherBucketsUntouched(): void
    {
        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $warehouse = $this->warehouses()->createWarehouseForRegion($region, 'BC', 'CA');
        $product = (new ProductCore())->setSku('SKU-1')->setName('Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);

        $this->newApprovedOrder($region, $product, '5');
        $this->em->flush();

        $inventory = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse]);
        self::assertNotNull($inventory);
        self::assertSame('5.0000', $inventory->getSalesHoldQuantity());

        // Corrupt the cache directly (touching only ProductInventory, not SalesOrder/Line, so
        // this doesn't itself trigger InventoryReconciliationSubscriber) plus stamp the two
        // buckets this command does not own, so we can prove the recalc leaves them alone.
        $inventory->setSalesHoldQuantity(12)->setApprovedQuantity(7)->setCartHoldQuantity(3);
        $this->em->flush();

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('1 ProductInventory row(s) corrected', $this->tester->getDisplay());

        $this->em->refresh($inventory);
        self::assertSame('5.0000', $inventory->getSalesHoldQuantity());
        self::assertSame('0.0000', $inventory->getPendingQuantity());
        self::assertSame('7.0000', $inventory->getApprovedQuantity());
        self::assertSame('3.0000', $inventory->getCartHoldQuantity());

        // One discrepancy row, naming the bucket that actually drifted. Pending agreed with source
        // of truth and is silent, which is the whole point of the buckets being separate: a drift
        // says which side it came from.
        $discrepancies = $this->em->getRepository(InventoryReconciliationDiscrepancy::class)->findAll();
        self::assertCount(1, $discrepancies);
        self::assertSame('sales_hold', $discrepancies[0]->getBucket());
        self::assertSame('12.0000', $discrepancies[0]->getCachedQuantity());
        self::assertSame('5.0000', $discrepancies[0]->getRecomputedQuantity());
        self::assertSame('inventory_recalc_cron', $discrepancies[0]->getSource());

        // AuditLogSubscriber's own generic entity-diff listener also logs this same
        // ProductInventory update — filter down to the command's own explicit summary rather
        // than assuming it's the only 'updated'/ProductInventory row.
        $updatedLogs = $this->em->getRepository(AuditLog::class)->findBy(['area' => 'System', 'entityType' => 'ProductInventory', 'action' => 'updated']);
        $recalcLogs = array_values(array_filter($updatedLogs, static fn (AuditLog $log): bool => str_contains($log->getSummary(), 'row(s) corrected')));
        self::assertCount(1, $recalcLogs);
        self::assertStringContainsString('1 row(s) corrected', $recalcLogs[0]->getSummary());
    }

    public function testNoOpWhenCacheAlreadyMatchesSourceOfTruth(): void
    {
        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $warehouse = $this->warehouses()->createWarehouseForRegion($region, 'BC', 'CA');
        $product = (new ProductCore())->setSku('SKU-2')->setName('Another Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);

        $this->newApprovedOrder($region, $product, '5');
        $this->em->flush();

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('0 ProductInventory row(s) corrected', $this->tester->getDisplay());

        self::assertCount(0, $this->em->getRepository(InventoryReconciliationDiscrepancy::class)->findAll());
        // The command only emits its own summary AuditLog entry when it actually corrects a
        // row — a pre-existing 'created' entry from the initial order-triggered reconciliation
        // is expected and not what this assertion is about.
        self::assertCount(0, $this->em->getRepository(AuditLog::class)->findBy(['area' => 'System', 'entityType' => 'ProductInventory', 'action' => 'updated']));

        $inventory = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse]);
        self::assertSame('5.0000', $inventory->getSalesHoldQuantity());
    }

    /**
     * Two lines resolve to two different regions (aggregated independently) and a third line's
     * location doesn't match any known region — mirrors InventoryReservationReconciler's own
     * resolveLineWarehouse() skip behavior, so the two source-of-truth computations never disagree
     * on what an unresolvable line counts for (nothing).
     */
    public function testAggregatesMultipleRegionsAndSkipsUnresolvableLineLocation(): void
    {
        $west = (new FulfillmentRegion())->setName('West');
        $east = (new FulfillmentRegion())->setName('East');
        $this->em->persist($west);
        $this->em->persist($east);
        $westWarehouse = $this->warehouses()->createWarehouseForRegion($west, 'BC', 'CA');
        $eastWarehouse = $this->warehouses()->createWarehouseForRegion($east, 'BC', 'CA');
        $product = (new ProductCore())->setSku('SKU-3')->setName('Multi-Region Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);

        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('ORD-' . uniqid())
            ->setFulfillmentRegion($west->getName());
        $order->addLine((new SalesOrderLine())->setProduct($product)->setName($product->getName())->setQuantity('3')->setLocation('West'));
        $order->addLine((new SalesOrderLine())->setProduct($product)->setName($product->getName())->setQuantity('4')->setLocation('East'));
        $order->addLine((new SalesOrderLine())->setProduct($product)->setName($product->getName())->setQuantity('100')->setLocation('Nowhere'));
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        $westInventory = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $westWarehouse]);
        $eastInventory = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $eastWarehouse]);
        self::assertSame('3.0000', $westInventory->getSalesHoldQuantity());
        self::assertSame('4.0000', $eastInventory->getSalesHoldQuantity());

        // Corrupt only West's cache; East stays correct.
        $westInventory->setSalesHoldQuantity(999);
        $this->em->flush();

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('1 ProductInventory row(s) corrected', $this->tester->getDisplay());

        $this->em->refresh($westInventory);
        $this->em->refresh($eastInventory);
        self::assertSame('3.0000', $westInventory->getSalesHoldQuantity());
        self::assertSame('4.0000', $eastInventory->getSalesHoldQuantity());
    }

    /**
     * The other half of what this command owns from #539 stage 3: Pending is recomputed from the
     * INVOICES, not from the orders, so a drift in one bucket is corrected without disturbing the
     * other and each names its own source.
     */
    public function testCorrectsDriftedPendingQuantityFromTheInvoices(): void
    {
        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $warehouse = $this->warehouses()->createWarehouseForRegion($region, 'BC', 'CA');
        $product = (new ProductCore())->setSku('SKU-4')->setName('Invoiced Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);

        $order = $this->newApprovedOrder($region, $product, '10');
        $this->em->flush();

        $invoice = new Invoice();
        $order->addInvoice($invoice);
        $invoice
            ->setCompany($this->company)
            ->setDocumentNumber('INV-' . uniqid())
            ->setFulfillmentRegion($region->getName());
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($order->getLines()->first())
                ->setProduct($product)
                ->setName($product->getName())
                ->setQuantity('4'),
        );
        $invoice->issue(DocumentActor::system());
        $this->em->persist($invoice);
        $this->em->flush();

        $inventory = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse]);
        self::assertNotNull($inventory);
        self::assertSame('6.0000', $inventory->getSalesHoldQuantity());
        self::assertSame('4.0000', $inventory->getPendingQuantity());

        $inventory->setPendingQuantity(41);
        $this->em->flush();

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));

        $this->em->refresh($inventory);
        self::assertSame('6.0000', $inventory->getSalesHoldQuantity());
        self::assertSame('4.0000', $inventory->getPendingQuantity());

        $discrepancies = $this->em->getRepository(InventoryReconciliationDiscrepancy::class)->findAll();
        self::assertCount(1, $discrepancies);
        self::assertSame('pending', $discrepancies[0]->getBucket());
        self::assertSame('41.0000', $discrepancies[0]->getCachedQuantity());
        self::assertSame('4.0000', $discrepancies[0]->getRecomputedQuantity());
    }
}
