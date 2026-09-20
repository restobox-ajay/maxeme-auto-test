<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use App\Entity\FulfillmentRegion;
use App\Entity\InventoryReconciliationDiscrepancy;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Inventory\InventoryModeSwitcher;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Entity\TransferOrderLine;

/**
 * The transfer drift check and the findings screen it feeds (#587), end to end.
 *
 * ## The transfer is CONDUCTED through the screens
 *
 * Every number the check compares is a number a fixture could have written by hand, so a seeded
 * `transfer_order_line` would prove only that the check agrees with whoever wrote the fixture.
 * Everything below goes through the real transfer screens — create the draft, add the line, press
 * Dispatch, then receipt a DIFFERENT quantity from the one that left — and the assertions are on
 * the columns those screens produced.
 *
 * ## And the cumulative column is watched while it accumulates
 *
 * `product_inventory.transfer_out_quantity` is a running total of every dispatch, not the last one
 * (#584). driftIsOnlyReportedWhenRecordsActuallyDisagree() dispatches twice and watches the column
 * go 12 → 16 while the check stays silent, which is the assertion that separates a check that
 * understands the column from one that would report every second transfer as drift.
 */
final class WarehouseOpsDiscrepanciesCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('drift-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** @return array{product: ProductCore, source: Warehouse, destination: Warehouse} */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $west = (new FulfillmentRegion())->setName('Drift West ' . uniqid());
        $east = (new FulfillmentRegion())->setName('Drift East ' . uniqid());
        $em->persist($west);
        $em->persist($east);

        $warehouses = $I->grabService(WarehouseFulfillmentRegionService::class);
        $source = $warehouses->createWarehouseForRegion($west, 'BC', 'CA');
        $destination = $warehouses->createWarehouseForRegion($east, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('DRIFT-' . strtoupper(substr(uniqid(), -6)))
            ->setName('Drifting Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($source)->setQuantity(40));
        $em->persist((new WarehouseLocation())->setWarehouse($source)->setCode('D-01')->setSortKey(10));

        $em->flush();

        $I->grabService(InventoryModeSwitcher::class)->toDimensional($product, 'drift@example.test');

        return ['product' => $product, 'source' => $source, 'destination' => $destination];
    }

    /**
     * One transfer, conducted through the screens: dispatch everything, receipt $arriving.
     *
     * @return int the `transfer_order_line.id` the dispatch wrote into
     */
    private function conductThroughTheScreens(FunctionalTester $I, array $seed, int $requested, int $arriving): int
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers');
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/warehouse-ops/transfers/new', [
            '_token' => $token,
            'from_warehouse_id' => (string) $seed['source']->getId(),
            'to_warehouse_id' => (string) $seed['destination']->getId(),
            'notes' => 'Drift check transfer',
        ]);

        $transfer = $em->getRepository(TransferOrder::class)->findOneBy([], ['id' => 'DESC']);
        $I->assertInstanceOf(TransferOrder::class, $transfer);

        $I->sendAjaxPostRequest(sprintf('/admin/bundles/warehouse-ops/transfers/%d/lines', $transfer->getId()), [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'quantity' => (string) $requested,
        ]);

        $I->sendAjaxPostRequest(sprintf('/admin/bundles/warehouse-ops/transfers/%d/dispatch', $transfer->getId()), [
            '_token' => $token,
        ]);

        $em->clear();
        $transfer = $em->getRepository(TransferOrder::class)->find($transfer->getId());
        /** @var TransferOrderLine $line */
        $line = $transfer->getLines()->first();
        $lineId = (int) $line->getId();

        $I->sendAjaxPostRequest(sprintf('/admin/bundles/warehouse-ops/transfers/%d/receive', $transfer->getId()), [
            '_token' => $token,
            'received' => [(string) $lineId => (string) $arriving],
        ]);

        $em->clear();

        return $lineId;
    }

    private function runTheCheck(FunctionalTester $I): string
    {
        $tester = new CommandTester(
            (new Application($I->grabService('kernel')))->find('app:warehouse-ops:transfer-check')
        );
        $tester->execute([]);

        return $tester->getDisplay();
    }

    private function inventory(FunctionalTester $I, ProductCore $product, Warehouse $warehouse): ProductInventory
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $row = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product->getId(),
            'warehouse' => $warehouse->getId(),
        ]);
        $I->assertInstanceOf(ProductInventory::class, $row);
        $em->refresh($row);

        return $row;
    }

    /**
     * The clean case, and the cumulative one, on the same conducted transfers.
     *
     * 12 leave and 10 arrive: `transfer_out_quantity` 12, `transfer_in_quantity` 10, and the two
     * that never turned up still on the source's `in_transit` row. Every record says something true
     * about the same event, so there is nothing to report — and the screen says so.
     *
     * Then 4 more leave and arrive, and `transfer_out_quantity` goes 12 → 16 rather than back to 4.
     * The check has to expect 16.
     */
    public function driftIsOnlyReportedWhenRecordsActuallyDisagree(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $this->conductThroughTheScreens($I, $seed, 12, 10);

        $source = $this->inventory($I, $seed['product'], $seed['source']);
        $I->assertSame('12.0000', $source->getTransferOutQuantity(), 'product_inventory.transfer_out_quantity 0 -> 12: what left');
        $I->assertSame('28.0000', $source->getAvailableQuantity(), 'and the source cannot sell the twelve that left');

        $destination = $this->inventory($I, $seed['product'], $seed['destination']);
        $I->assertSame('10.0000', $destination->getTransferInQuantity(), 'transfer_in_quantity 0 -> 10: what ARRIVED, not what was sent');

        $stranded = $em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $seed['product']->getId(),
            'warehouse' => $seed['source']->getId(),
            'status' => InventoryDetail::STATUS_IN_TRANSIT,
        ]);
        $I->assertInstanceOf(InventoryDetail::class, $stranded);
        $I->assertSame('2.0000', $stranded->getQuantity(), 'the two that never arrived are still on the source warehouse\'s in-transit row');

        $I->assertStringContainsString('all agree', $this->runTheCheck($I), 'a short arrival is what the document says happened, not a disagreement');

        // A second transfer on the same route: the cumulative column has to accumulate.
        $this->conductThroughTheScreens($I, $seed, 4, 4);

        $source = $this->inventory($I, $seed['product'], $seed['source']);
        $I->assertSame('16.0000', $source->getTransferOutQuantity(), 'transfer_out_quantity 12 -> 16: the running total, not the 4 that just left');
        $I->assertSame('14.0000', $this->inventory($I, $seed['product'], $seed['destination'])->getTransferInQuantity());

        $I->assertStringContainsString('all agree', $this->runTheCheck($I));
        $I->assertCount(0, $em->getRepository(InventoryReconciliationDiscrepancy::class)->findAll());

        $I->amOnPage('/admin/bundles/warehouse-ops/discrepancies');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Nothing has drifted.');
        $I->seeElement('.table-card.no-paginate');
    }

    /**
     * A `transfer_order_line` edited behind the document's back, found and LISTED.
     *
     * The edit is raw SQL on purpose: it is how drift actually happens — a DBA, a broken import, a
     * half-finished script — and it bypasses the service, the listeners and the change log exactly
     * as those do.
     */
    public function aHandEditedDocumentIsFoundAndListedWithoutBeingCorrected(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $lineId = $this->conductThroughTheScreens($I, $seed, 12, 10);

        $em->getConnection()->executeStatement(
            'UPDATE transfer_order_line SET quantity_dispatched = 15 WHERE id = :id',
            ['id' => $lineId],
        );
        $em->clear();

        $this->runTheCheck($I);

        $findings = $em->getRepository(InventoryReconciliationDiscrepancy::class)->findBy([], ['id' => 'ASC']);
        $I->assertCount(2, $findings, 'the bucket and the in-transit rows both notice');

        // The FIGURES inside the rows, not only how many rows there are (#594). Two rows tagged
        // transfer_drift_check for this SKU is the same count whether the check wrote the delta
        // correctly, swapped the two sides, summed them, or wrote zeros — and a meaningless delta
        // on the findings screen is exactly what this feature must not produce.
        $byBucket = [];
        foreach ($findings as $finding) {
            $byBucket[$finding->getBucket()] = [$finding->getCachedQuantity(), $finding->getRecomputedQuantity()];
        }
        ksort($byBucket);
        $I->assertSame(['in_transit', 'transfer_out'], array_keys($byBucket), 'one finding per bucket, and each names its own');
        $I->assertSame(
            ['12.0000', '15.0000'],
            $byBucket['transfer_out'],
            'the transfer_out finding reads the cached bucket against what the hand-edited document now claims',
        );
        $I->assertNotSame(
            $byBucket['in_transit'][0],
            $byBucket['in_transit'][1],
            'a finding whose two sides agree is not a finding',
        );

        $I->amOnPage('/admin/bundles/warehouse-ops/discrepancies');
        $I->seeResponseCodeIsSuccessful();
        $I->see($seed['product']->getSku());
        $I->see('transfer_drift_check');
        $I->seeNumberOfElements('tr.data-item-row', 2);

        // The row action goes somewhere a human can act, and is a plain link.
        $I->seeElement(sprintf('a.table-action[href="/admin/bundles/inventory-depth/stock/product/%d"]', $seed['product']->getId()));

        // Filter state lives in the URL, so a pasted link reproduces the result — and the filter
        // works with scripting off, because it is a GET form and this is a plain navigation.
        $I->amOnPage('/admin/bundles/warehouse-ops/discrepancies?filters[bucket]=in_transit');
        $I->seeNumberOfElements('tr.data-item-row', 1);
        $I->seeElement('select[name="filters[bucket]"] option[value="in_transit"][selected]');

        $I->amOnPage('/admin/bundles/warehouse-ops/discrepancies?filters[product]=NO-SUCH-SKU');
        $I->see('Nothing has drifted.');

        // Nothing was repaired. Asserted on the rows, because the dangerous version of this feature
        // is one that quietly helps.
        $I->assertSame(
            15,
            (int) $em->getConnection()->fetchOne('SELECT quantity_dispatched FROM transfer_order_line WHERE id = :id', ['id' => $lineId]),
            'transfer_order_line.quantity_dispatched is left exactly as the check found it',
        );
        $I->assertSame(
            '12.0000',
            $this->inventory($I, $seed['product'], $seed['source'])->getTransferOutQuantity(),
            'product_inventory.transfer_out_quantity is NOT recomputed to 15 — a discrepancy is a finding for a human',
        );
    }

    /**
     * With the bundle switched off the screen is absent rather than forbidden, exactly like every
     * other screen in it, and the check stands down without touching anything.
     */
    public function theScreenAndTheCheckBothStandDownWithTheBundleInactive(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $lineId = $this->conductThroughTheScreens($I, $seed, 12, 10);

        $em->getConnection()->executeStatement(
            'UPDATE transfer_order_line SET quantity_dispatched = 15 WHERE id = :id',
            ['id' => $lineId],
        );
        // Switched off through the one activation path. A fresh BundleStatus used to be safe here
        // because nothing had created one — absence of a row was the enabled default. Every
        // installed bundle now gets an explicit Active row before the suite (tests/_bootstrap.php),
        // so a second insert for the same source trips the UNIQUE index instead of switching
        // anything off.
        $I->grabService(BundleStatusRepository::class)->deactivate('WarehouseOpsBundle');
        $em->clear();

        $I->assertStringContainsString('Inactive', $this->runTheCheck($I));
        $I->assertCount(
            0,
            $em->getRepository(InventoryReconciliationDiscrepancy::class)->findAll(),
            'nothing is reported about buckets the availability gate has already excluded',
        );

        $I->amOnPage('/admin/bundles/warehouse-ops/discrepancies');
        $I->seeResponseCodeIs(404);
    }
}
