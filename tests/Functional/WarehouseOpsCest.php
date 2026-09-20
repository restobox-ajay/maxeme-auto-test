<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Inventory\InventoryModeSwitcher;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;
use WarehouseOpsBundle\Entity\TransferOrder;

/**
 * WarehouseOpsBundle's screens (#552), end to end through the real kernel.
 *
 * The PHPUnit tests cover what a pick does to the number and what a transfer does between its two
 * movements. This covers the half they cannot reach: that every screen renders at all, that the
 * scan wizard carries its state in the URL, that the label PDF actually comes out as a PDF, and
 * that a form submission reaches the same movement service the desktop adjustment screen uses.
 *
 * Several of these screens run DQL no unit test executes, so rendering them is a real assertion.
 */
final class WarehouseOpsCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('warehouse-ops-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** @return array{product: ProductCore, warehouse: Warehouse, region: FulfillmentRegion, pick: WarehouseLocation, staging: WarehouseLocation} */
    private function seed(FunctionalTester $I, int $quantity = 40): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Ops Region ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('OPS-' . strtoupper(substr(uniqid(), -6)))
            ->setName('Ops Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity($quantity));

        $pick = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('P-01')->setSortKey(10)->setType(WarehouseLocation::TYPE_PICK);
        $staging = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('S-01')->setSortKey(900)->setType(WarehouseLocation::TYPE_STAGING);
        $em->persist($pick);
        $em->persist($staging);

        $em->flush();

        // Through the real switcher, so the opening balance is written the way the app writes it.
        $I->grabService(InventoryModeSwitcher::class)->toDimensional($product, 'cest@example.test');

        return ['product' => $product, 'warehouse' => $warehouse, 'region' => $region, 'pick' => $pick, 'staging' => $staging];
    }

    public function everyScreenRenders(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        foreach ([
            '/admin/bundles/warehouse-ops',
            '/admin/bundles/warehouse-ops/labels',
            '/admin/bundles/warehouse-ops/scan',
            '/admin/bundles/warehouse-ops/pick-lists',
            '/admin/bundles/warehouse-ops/pick-lists/new',
            '/admin/bundles/warehouse-ops/transfers',
            '/admin/bundles/warehouse-ops/discrepancies',
            '/admin/bundles/warehouse-ops/bin-map?filters[warehouse]=' . $seed['warehouse']->getId(),
        ] as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
        }
    }

    /** Standing requirement in this codebase: a copied URL reproduces the exact result. */
    public function everyListFilterLivesInTheUrl(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seed($I);

        $I->amOnPage('/admin/bundles/warehouse-ops/pick-lists?filters[status]=released&filters[number]=PL-9');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="filters[number]"][value="PL-9"]');

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers?filters[status]=draft&filters[number]=TR-9');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="filters[number]"][value="TR-9"]');
    }

    /**
     * The label screen's whole job is putting ink on adhesive paper, so "it returned 200" is not
     * enough — this checks the bytes are a PDF, and that the preview really is the same HTML the
     * PDF is built from.
     */
    public function labelsComeOutAsAPdfAndThePreviewIsTheSameRendering(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $I->amOnPage('/admin/bundles/warehouse-ops/labels');
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/warehouse-ops/labels/print', [
            '_token' => $token,
            'kind' => 'bin',
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'template' => 'roll-100x50',
            'copies' => '1',
            'preview' => '1',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('P-01');
        $I->seeInSource('class="bar"');

        $I->sendAjaxPostRequest('/admin/bundles/warehouse-ops/labels/print', [
            '_token' => $token,
            'kind' => 'bin',
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'template' => 'roll-100x50',
            'copies' => '2',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->assertStringStartsWith('%PDF', $I->grabPageSource(), 'a label sheet has to be a real PDF, not an HTML page a browser might rescale');
    }

    /**
     * The scan wizard, driven the way a keyboard-wedge scanner drives it: one field, Enter, next
     * step. The state has to survive entirely in the URL — a mis-scan is then the back button.
     */
    public function theScanWizardCarriesItsStateInTheUrlAndWritesOneMovement(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 40);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $start = sprintf('/admin/bundles/warehouse-ops/scan?flow=receive&warehouse=%d', $seed['warehouse']->getId());
        $I->amOnPage($start);
        $I->see('Scan the bin');
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        // Scan the bin.
        $I->sendAjaxPostRequest('/admin/bundles/warehouse-ops/scan/scan', [
            '_token' => $token,
            'flow' => 'receive',
            'warehouse' => (string) $seed['warehouse']->getId(),
            'expect' => 'bin',
            'code' => 'P-01',
        ]);

        $I->amOnPage($start . '&bin=' . $seed['pick']->getId());
        $I->see('Scan the item');

        // Scan the SKU.
        $I->sendAjaxPostRequest('/admin/bundles/warehouse-ops/scan/scan', [
            '_token' => $token,
            'flow' => 'receive',
            'warehouse' => (string) $seed['warehouse']->getId(),
            'bin' => (string) $seed['pick']->getId(),
            'expect' => 'item',
            'code' => $seed['product']->getSku(),
        ]);

        $quantityStep = sprintf('%s&bin=%d&product=%d', $start, $seed['pick']->getId(), $seed['product']->getId());
        $I->amOnPage($quantityStep);
        $I->see('How many?');

        $I->sendAjaxPostRequest('/admin/bundles/warehouse-ops/scan/apply', [
            '_token' => $token,
            'op_id' => 'cest-scan-1',
            'flow' => 'receive',
            'warehouse' => (string) $seed['warehouse']->getId(),
            'bin' => (string) $seed['pick']->getId(),
            'product' => (string) $seed['product']->getId(),
            'quantity' => '7',
            'reference' => 'CEST-RECEIPT',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $inventory = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $seed['product'],
            'warehouse' => $seed['warehouse'],
        ]);
        $em->refresh($inventory);
        // `quantity` is the client's external figure and no movement writes it (#564), so a scanned
        // receipt of 7 leaves it at 40 and lands in `received` instead. What the assertion has
        // always meant — the goods are here and sellable — is availability, so that is what it now
        // asserts, plus the bucket that carries it.
        $I->assertSame('40.0000', $inventory->getQuantity(), 'a receipt never writes the external system\'s own figure');
        $I->assertSame('7.0000', $inventory->getReceivedQuantity(), 'the scanned units land in `received`');
        $I->assertSame('47.0000', $inventory->getAvailableQuantity(), 'a scanned receipt adds to what can be sold, through the same service an adjustment uses');

        $row = $em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $seed['product'],
            'location' => $seed['pick'],
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);
        $I->assertInstanceOf(InventoryDetail::class, $row);
        $I->assertSame('7.0000', $row->getQuantity());

        // The same submission again, with the same key the device minted. Warehouse wi-fi is bad.
        $I->sendAjaxPostRequest('/admin/bundles/warehouse-ops/scan/apply', [
            '_token' => $token,
            'op_id' => 'cest-scan-1',
            'flow' => 'receive',
            'warehouse' => (string) $seed['warehouse']->getId(),
            'bin' => (string) $seed['pick']->getId(),
            'product' => (string) $seed['product']->getId(),
            'quantity' => '7',
            'reference' => 'CEST-RECEIPT',
        ]);
        $em->refresh($inventory);
        // Idempotency, read the same way as the first booking: `quantity` was never written, so the
        // proof a replay moved nothing is that `received` is still 7 rather than 14.
        $I->assertSame('40.0000', $inventory->getQuantity(), 'still the external figure, untouched by either submit');
        $I->assertSame('7.0000', $inventory->getReceivedQuantity(), 'a replayed submit moves stock once');
        $I->assertSame('47.0000', $inventory->getAvailableQuantity(), 'a replayed submit moves stock once');

        // And it shows up in #550's own movement history, because it is an ordinary movement.
        $I->amOnPage('/admin/bundles/inventory-depth/movements');
        $I->see('CEST-RECEIPT');
    }

    /**
     * #785: ScanController::onHand() declared `: int` but returned InventoryDetail::getQuantity()
     * — a decimal STRING, this app's canonical quantity representation everywhere else — straight
     * through. Under declare(strict_types=1) that is a TypeError, and it only ever stayed hidden
     * because the no-stock branch returns the literal 0 rather than a fetched quantity: the wizard
     * worked for an empty bin and 500ed the moment a bin actually had something in it, which is
     * exactly the case the "count"/"move" quantity step exists to show.
     *
     * A real row is put in the bin first (the same receive → apply cycle the test above drives),
     * so this reaches onHand()'s real branch rather than its always-safe fallback.
     */
    public function theQuantityStepRendersOnHandFromARealBinRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 40);
        $start = sprintf('/admin/bundles/warehouse-ops/scan?flow=receive&warehouse=%d', $seed['warehouse']->getId());
        $I->amOnPage($start);
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/warehouse-ops/scan/apply', [
            '_token' => $token,
            'op_id' => 'cest-onhand-1',
            'flow' => 'receive',
            'warehouse' => (string) $seed['warehouse']->getId(),
            'bin' => (string) $seed['pick']->getId(),
            'product' => (string) $seed['product']->getId(),
            'quantity' => '9',
            'reference' => 'CEST-ONHAND',
        ]);
        $I->seeResponseCodeIsSuccessful();

        // Straight to the quantity step of a DIFFERENT flow — count — for the bin that now really
        // holds stock, which is the branch that crashed.
        $I->amOnPage(sprintf(
            '/admin/bundles/warehouse-ops/scan?flow=count&warehouse=%d&bin=%d&product=%d',
            $seed['warehouse']->getId(),
            $seed['pick']->getId(),
            $seed['product']->getId(),
        ));
        $I->seeResponseCodeIsSuccessful();
        $I->see('How many?');
        $I->see('9');
    }

    /**
     * A scan that resolves to nothing must say so. Silent failure is how stock goes missing.
     *
     * The second half of the name is now asserted too (#594). Seeing the refusal text was the whole
     * of this test, so a handler that minted a `warehouse_location` for the unscanned code, opened
     * an `inventory_movement_group`, or left a partial `inventory_detail` row behind on its way to
     * deciding it could not resolve the barcode passed it. The row counts are taken before the
     * scan and compared after, because the fixture already put rows in all three tables.
     */
    public function anUnknownBarcodeBlocksLoudlyAndWritesNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $before = [
            'details' => $em->getRepository(InventoryDetail::class)->count([]),
            'groups' => $em->getRepository(InventoryMovementGroup::class)->count([]),
            'bins' => $em->getRepository(WarehouseLocation::class)->count([]),
            'lots' => $em->getRepository(InventoryLot::class)->count([]),
        ];

        $I->amOnPage(sprintf('/admin/bundles/warehouse-ops/scan?flow=receive&warehouse=%d', $seed['warehouse']->getId()));
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        // The client follows the redirect on its own, so this lands back on the scan step with the
        // refusal rendered — which is the point: the person holding the scanner has to see it.
        $I->sendAjaxPostRequest('/admin/bundles/warehouse-ops/scan/scan', [
            '_token' => $token,
            'flow' => 'receive',
            'warehouse' => (string) $seed['warehouse']->getId(),
            'expect' => 'bin',
            'code' => 'NO-SUCH-BIN',
        ]);
        $I->see('is not a bin, a SKU, a lot or a live serial');

        $em = $I->grabService('doctrine.orm.entity_manager');
        $I->assertSame([
            'details' => $before['details'],
            'groups' => $before['groups'],
            'bins' => $before['bins'],
            'lots' => $before['lots'],
        ], [
            'details' => $em->getRepository(InventoryDetail::class)->count([]),
            'groups' => $em->getRepository(InventoryMovementGroup::class)->count([]),
            'bins' => $em->getRepository(WarehouseLocation::class)->count([]),
            'lots' => $em->getRepository(InventoryLot::class)->count([]),
        ], 'an unresolvable barcode must leave inventory_detail, inventory_movement_group, warehouse_location and inventory_lot exactly as it found them');
    }

    /**
     * A transfer through its own screens, and the state in between: dispatched, not received, and
     * sellable at neither end.
     */
    public function aTransferIsDispatchedAndReceivedThroughItsOwnScreens(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $source = $this->seed($I, 40);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $eastRegion = (new FulfillmentRegion())->setName('Ops East ' . uniqid());
        $em->persist($eastRegion);
        $destination = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($eastRegion, 'BC', 'CA');
        $em->flush();

        $I->amOnPage('/admin/bundles/warehouse-ops/transfers');
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/warehouse-ops/transfers/new', [
            '_token' => $token,
            'from_warehouse_id' => (string) $source['warehouse']->getId(),
            'to_warehouse_id' => (string) $destination->getId(),
            'notes' => 'Cest transfer',
        ]);

        $transfer = $em->getRepository(TransferOrder::class)->findOneBy([], ['id' => 'DESC']);
        $I->assertInstanceOf(TransferOrder::class, $transfer);

        $I->sendAjaxPostRequest(sprintf('/admin/bundles/warehouse-ops/transfers/%d/lines', $transfer->getId()), [
            '_token' => $token,
            'product_id' => (string) $source['product']->getId(),
            'quantity' => '12',
        ]);

        $I->sendAjaxPostRequest(sprintf('/admin/bundles/warehouse-ops/transfers/%d/dispatch', $transfer->getId()), [
            '_token' => $token,
        ]);

        $em->clear();
        $sourceInventory = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $source['product']->getId(),
            'warehouse' => $source['warehouse']->getId(),
        ]);
        // Same rule from the other side: a dispatch does not write `quantity` either. The units are
        // still counted there — the external system has not been told they moved — but they are on
        // a truck, so they sit in `transfer_out` and come off availability (#574). "Cannot sell" was
        // always an availability claim, which is what makes this the assertion it should have been.
        $I->assertSame('40.0000', $sourceInventory->getQuantity(), 'a dispatch never writes the external system\'s own figure');
        $I->assertSame('12.0000', $sourceInventory->getTransferOutQuantity(), 'the units on the truck are held in `transfer_out`');
        $I->assertSame('28.0000', $sourceInventory->getAvailableQuantity(), 'the source cannot sell what is on the truck');

        // The destination end, asserted on the columns a dispatch could wrongly credit (#594).
        //
        // The assertion this replaces — `$row === null || $row->getQuantity() === 0` — could not
        // fail. Only the source warehouse is seeded, so the destination row does not exist and the
        // first disjunct is true whatever the transfer did; and `quantity` is the column this same
        // test asserts two lines above is never written by a dispatch at all. A dispatch that
        // credited `transfer_in` at dispatch instead of at receipt would make the same 12 units
        // sellable at BOTH warehouses while they sit on a truck, and this passed.
        $I->assertSame(
            0,
            (int) $em->getConnection()->fetchOne(
                'SELECT COALESCE((SELECT transfer_in_quantity FROM product_inventory WHERE product_id = ? AND warehouse_id = ?), 0)',
                [$source['product']->getId(), $destination->getId()],
            ),
            'product_inventory.transfer_in_quantity may not be credited until the goods are received',
        );

        $destinationInventory = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $source['product']->getId(),
            'warehouse' => $destination->getId(),
        ]);
        $I->assertSame(
            '0.0000',
            $destinationInventory?->getAvailableQuantity() ?? '0.0000',
            'and nothing is sellable at the destination yet — the units are on a truck',
        );
    }

    /**
     * The whole pick round through the UI, ending where the design says it ends: the stock is
     * staged and the product total has not moved.
     *
     * There is no ship step to drive, deliberately — see the bundle README. This application
     * accounts for shipped goods with the invoice's Approved hold, released by an import or
     * recount, so a deduction here would subtract the same units twice.
     */
    public function aPickRoundStagesStockWithoutChangingTheTotal(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 40);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $company = (new \App\Entity\Company())->setName('Ops Co')->setCode('OPSCO-' . strtoupper(substr(uniqid(), -5)));
        $em->persist($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('OPS-ORD-' . strtoupper(substr(uniqid(), -5)))
            ->setFulfillmentRegion($seed['region']->getName());
        $order->addLine(
            (new \App\Entity\SalesOrderLine())
                ->setProduct($seed['product'])
                ->setName($seed['product']->getName())
                ->setSku($seed['product']->getSku())
                ->setQuantity('9')
        );
        $order->setStatus('Approved', \App\Service\DocumentActor::system(), 'Order approved.');
        $em->persist($order);
        $em->flush();

        $I->amOnPage('/admin/bundles/warehouse-ops/pick-lists/new');
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/warehouse-ops/pick-lists/new', [
            '_token' => $token,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'orders' => [(string) $order->getId()],
            'assigned_to' => 'Cest Picker',
        ]);

        $list = $em->getRepository(\WarehouseOpsBundle\Entity\PickList::class)->findOneBy([], ['id' => 'DESC']);
        $I->assertInstanceOf(\WarehouseOpsBundle\Entity\PickList::class, $list);
        $I->assertSame('9.0000', $list->requestedUnits(), 'the round asks for what the order still owes');

        $I->amOnPage(sprintf('/admin/bundles/warehouse-ops/pick-lists/%d', $list->getId()));
        $I->seeResponseCodeIsSuccessful();

        $I->sendAjaxPostRequest(sprintf('/admin/bundles/warehouse-ops/pick-lists/%d/release', $list->getId()), [
            '_token' => $token,
            'staging_location_id' => (string) $seed['staging']->getId(),
        ]);

        $em->clear();
        $list = $em->getRepository(\WarehouseOpsBundle\Entity\PickList::class)->find($list->getId());
        /** @var \WarehouseOpsBundle\Entity\PickTask $task */
        $task = $list->getTasks()->first();

        // The buckets as they stand with the round released and nothing picked yet. Captured rather
        // than written as literals so this stays an "unchanged by the confirm" assertion and not a
        // second, drifting statement of what a release does.
        $before = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $seed['product']->getId(),
            'warehouse' => $seed['warehouse']->getId(),
        ]);
        $bucketsBeforeConfirm = [
            'available' => $before->getAvailableQuantity(),
            'salesHold' => $before->getSalesHoldQuantity(),
            'received' => $before->getReceivedQuantity(),
            'writeOff' => $before->getWriteOffQuantity(),
        ];
        $I->assertGreaterThan(0, $bucketsBeforeConfirm['available'], 'the fixture must have something sellable, or the comparison below is about nothing');

        $I->sendAjaxPostRequest(sprintf('/admin/bundles/warehouse-ops/pick-lists/%d/confirm', $list->getId()), [
            '_token' => $token,
            'op_id' => 'cest-pick-1',
            'picked' => [(string) $task->getId() => '9'],
        ]);

        $em->clear();
        $inventory = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $seed['product']->getId(),
            'warehouse' => $seed['warehouse']->getId(),
        ]);
        $I->assertSame('40.0000', $inventory->getQuantity(), 'a confirmed pick must not change the product total');

        // `quantity` alone could not carry this test's name (#594). This file establishes twice
        // that NO movement writes `product_inventory.quantity` (#564) — not a receipt, not a
        // dispatch — so `getQuantity() === 40` held for every possible implementation of confirm,
        // right or wrong. The double-count this test exists to forbid lands in the buckets: a
        // confirm that also deducted the nine picked units would drop availability by nine while
        // the invoice's Approved hold subtracts the same nine again.
        $I->assertSame($bucketsBeforeConfirm, [
            'available' => $inventory->getAvailableQuantity(),
            'salesHold' => $inventory->getSalesHoldQuantity(),
            'received' => $inventory->getReceivedQuantity(),
            'writeOff' => $inventory->getWriteOffQuantity(),
        ], 'staging stock is still in the building: a confirmed pick moves it between bins and touches no bucket');

        $staged = $em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $seed['product']->getId(),
            'location' => $seed['staging']->getId(),
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);
        $I->assertInstanceOf(InventoryDetail::class, $staged);
        $I->assertSame('9.0000', $staged->getQuantity(), 'and it is sitting in the staging bin');

        // The screen says where a ship button would be, and why there is not one.
        $I->amOnPage(sprintf('/admin/bundles/warehouse-ops/pick-lists/%d', $list->getId()));
        $I->see('There is no ship button here');
    }

    /**
     * A whole round walked with JavaScript off, ending at the bin the picker actually stood at (#591).
     *
     * The confirm screen used to have one "Found" box per task and no bin field at all, so the
     * service was never told where the picker had been and fell back to the earliest-expiring row in
     * the building. A picker sent to one shelf, who legitimately took the units off another because
     * the first was empty, had them decremented off a third. The total stayed right, every unit
     * stayed `available`, and no screen contradicted it.
     *
     * So this drives the fixed path the way a browser with JS off drives it — a page load, then a
     * plain form POST carrying only the field names the form renders — and asserts the two bin rows
     * directly. Two bins is the whole fixture: with stock in one, "the bin the picker named" and
     * "this product's stock in this warehouse" are the same set of rows and nothing can tell the fix
     * from the bug.
     */
    public function aPickIsRecordedAgainstTheBinThePickerNamesOnTheForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 40);
        $em = $I->grabService('doctrine.orm.entity_manager');

        // A second pick face, further down the walk than the one the round will suggest.
        $far = (new WarehouseLocation())->setWarehouse($seed['warehouse'])->setCode('P-02')->setSortKey(20)->setType(WarehouseLocation::TYPE_PICK);
        $em->persist($far);
        $em->flush();

        // Stock into both, through the movement service — the only way stock ever arrives.
        $movements = $I->grabService(\InventoryDepthBundle\Movement\StockMovementService::class);
        foreach ([$seed['pick'], $far] as $index => $bin) {
            $movements->apply(
                \InventoryDepthBundle\Movement\MovementRequest::of(
                    \InventoryDepthBundle\Entity\InventoryMovementGroup::TYPE_RECEIPT,
                    'cest-591-seed-' . $index . '-' . uniqid(),
                )->receive($seed['product'], new \InventoryDepthBundle\Movement\DetailKey($seed['warehouse'], $bin), 10)
            );
        }

        $company = (new \App\Entity\Company())->setName('Shelf Co')->setCode('SHELF-' . strtoupper(substr(uniqid(), -5)));
        $em->persist($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('SHELF-ORD-' . strtoupper(substr(uniqid(), -5)))
            ->setFulfillmentRegion($seed['region']->getName());
        $order->addLine(
            (new \App\Entity\SalesOrderLine())
                ->setProduct($seed['product'])
                ->setName($seed['product']->getName())
                ->setSku($seed['product']->getSku())
                ->setQuantity('10')
        );
        $order->setStatus('Approved', \App\Service\DocumentActor::system(), 'Order approved.');
        $em->persist($order);
        $em->flush();

        $I->amOnPage('/admin/bundles/warehouse-ops/pick-lists/new');
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/pick-lists/new', [
            '_token' => $token,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'orders' => [(string) $order->getId()],
        ]);

        $list = $em->getRepository(\WarehouseOpsBundle\Entity\PickList::class)->findOneBy([], ['id' => 'DESC']);
        $I->assertInstanceOf(\WarehouseOpsBundle\Entity\PickList::class, $list);

        $I->sendFormPostRequest(sprintf('/admin/bundles/warehouse-ops/pick-lists/%d/release', $list->getId()), [
            '_token' => $token,
            'staging_location_id' => (string) $seed['staging']->getId(),
        ]);

        $em->clear();
        $list = $em->getRepository(\WarehouseOpsBundle\Entity\PickList::class)->find($list->getId());
        /** @var \WarehouseOpsBundle\Entity\PickTask $task */
        $task = $list->getTasks()->first();
        $taskId = (int) $task->getId();

        $I->assertSame(
            $seed['pick']->getId(),
            $task->getSuggestedLocation()?->getId(),
            'the round sends the picker to the lowest-sorted bin holding the product',
        );

        // The field exists on the page, without a line of JavaScript, and offers the far bin.
        $I->amOnPage(sprintf('/admin/bundles/warehouse-ops/pick-lists/%d', $list->getId()));
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement(sprintf('select[name="picked_from[%d]"]', $taskId));
        $I->seeElement(sprintf('select[name="picked_from[%d]"] option[value="%d"]', $taskId, $far->getId()));

        // The picker went to P-02 instead, and says so. A plain form POST — no X-Requested-With,
        // only the names the form renders.
        $token = (string) $I->grabAttributeFrom('form[action$="/confirm"] input[name="_token"]', 'value');
        $I->sendFormPostRequest(sprintf('/admin/bundles/warehouse-ops/pick-lists/%d/confirm', $list->getId()), [
            '_token' => $token,
            'op_id' => 'cest-591-confirm',
            'picked' => [(string) $taskId => '10'],
            'picked_from' => [(string) $taskId => (string) $far->getId()],
        ]);

        $em->clear();

        $atSuggested = $em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $seed['product']->getId(),
            'location' => $seed['pick']->getId(),
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);
        $atFar = $em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $seed['product']->getId(),
            'location' => $far->getId(),
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);
        $staged = $em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $seed['product']->getId(),
            'location' => $seed['staging']->getId(),
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);

        $I->assertSame('10.0000', $atSuggested?->getQuantity(), 'P-01 is the bin the paper named and nobody went there');
        $I->assertSame('0.0000', $atFar?->getQuantity(), 'P-02 is where the picker said they were, and it is where the ten came off');
        $I->assertSame('10.0000', $staged?->getQuantity(), 'and the ten are in the staging bin');

        $inventory = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $seed['product']->getId(),
            'warehouse' => $seed['warehouse']->getId(),
        ]);
        $I->assertSame('40.0000', $inventory->getQuantity(), 'a pick still does not change the product total');
    }
}
