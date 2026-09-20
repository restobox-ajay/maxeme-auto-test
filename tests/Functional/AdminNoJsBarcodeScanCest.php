<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use BarcodeBundle\Barcode\BarcodeRegistry;
use BarcodeBundle\Entity\ProductBarcode;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Inventory\InventoryModeSwitcher;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;
use WarehouseOpsBundle\Entity\PickList;
use WarehouseOpsBundle\Entity\PickTask;

/**
 * Barcode scanning at goods-in and at dispatch, driven the way a browser with scripting off drives
 * it (#607, #609).
 *
 * **Nothing in this file uses JavaScript, and that is the point of it.** Every POST goes through
 * `sendFormPostRequest()` — a plain browser form post with no `X-Requested-With` header, which is
 * exactly what a browser with scripting off sends when a submit button is pressed. It is also
 * exactly what a hardware keyboard-wedge scanner produces: the scanner types the barcode into the
 * focused field and presses Enter, which submits the form. No driver, no permission prompt, no
 * camera API, no fetch(). A camera would sit beside that field as an enhancement; it is never the
 * mechanism, and this file is the proof that it does not need to be.
 *
 * ## What is asserted
 *
 * Rows, not flashes. At goods-in: the `goods_receipt_line` written and the `inventory_detail`
 * behind it. At dispatch: `pick_task.quantity_picked` and where the stock physically ended up.
 * Seeing a green message is not evidence that anything was stored.
 *
 * ## The scan screens themselves write nothing
 *
 * Each scan changes the URL and only the URL. The confirmations post to the SAME routes with the
 * SAME field names the typed screens have always posted to — `lines[N][...]` into
 * `admin_bundle_procurement_receive_submit`, `picked[taskId]` into
 * `admin_bundle_warehouse_ops_pick_list_confirm` — so a scanned delivery and a typed one reach
 * stock through one implementation rather than two.
 */
final class AdminNoJsBarcodeScanCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('nojs-barcode-scan-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A dimensional product carrying a vendor's EAN-13, in a warehouse with a pick bin and a
     * staging bin. The EAN is the barcode actually printed on the carton; the SKU is this
     * business's own name for it, and before #607 the SKU was the only thing that scanned.
     *
     * @return array{product: ProductCore, warehouse: Warehouse, region: FulfillmentRegion, pick: WarehouseLocation, staging: WarehouseLocation, ean: string}
     */
    private function seed(FunctionalTester $I, int $quantity = 40): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Scan Region ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('SCAN-' . strtoupper(substr(uniqid(), -6)))
            ->setName('Scanned Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity($quantity));

        $pick = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('SP-01')->setSortKey(10)->setType(WarehouseLocation::TYPE_PICK);
        $staging = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('SS-01')->setSortKey(900)->setType(WarehouseLocation::TYPE_STAGING);
        $em->persist($pick);
        $em->persist($staging);
        $em->flush();

        $I->grabService(InventoryModeSwitcher::class)->toDimensional($product, 'cest@example.test');

        // Stock ON the pick face, not merely in the building. The dispatch test names that bin as
        // where the units came off, and PickConfirmationService takes the picker at their word: with
        // the opening balance sitting in no bin, naming SP-01 would correctly find nothing there.
        $I->grabService(StockMovementService::class)->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_PUTAWAY, 'scan-seed-' . uniqid(), 'Put away for the barcode scan test')
                ->move(
                    $product,
                    new DetailKey($warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE),
                    new DetailKey($warehouse, $pick, null, null, InventoryDetail::STATUS_AVAILABLE),
                    $quantity,
                )
        );

        // A real EAN-13, with a real check digit, put on the product the way a person would.
        $I->grabService(BarcodeRegistry::class)->attach($product, '4006381333931', ProductBarcode::KIND_EAN);

        return [
            'product' => $product,
            'warehouse' => $warehouse,
            'region' => $region,
            'pick' => $pick,
            'staging' => $staging,
            'ean' => '4006381333931',
        ];
    }

    private function tokenOn(FunctionalTester $I, string $url): string
    {
        $I->amOnPage($url);
        $I->seeResponseCodeIsSuccessful();

        return (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    // ---------------------------------------------------------------- receiving

    /**
     * Goods-in, from the vendor's own carton.
     *
     * The scanned value is the EAN printed on the box, not this business's SKU. That is the whole
     * point of #607: before it, the only product identifier in the database was `product_core.sku`,
     * so a receiving desk could scan a label it had printed itself and could not scan the one
     * actually in front of it.
     */
    public function aVendorsCartonScansIntoAReceiptWithNoJavascript(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $vendor = (new Vendor())->setName('Northern Supply ' . uniqid())->setStatus('Active');
        $em->persist($vendor);
        $em->flush();

        $start = sprintf(
            '/admin/bundles/procurement/receiving/scan?vendor=%d&warehouse=%d&bin=%d',
            $vendor->getId(),
            $seed['warehouse']->getId(),
            $seed['pick']->getId(),
        );

        // The scan field is one text input, focused, that a wedge scanner types into.
        $token = $this->tokenOn($I, $start);
        $I->seeElement('input[name="code"][autofocus]');

        $I->sendFormPostRequest('/admin/bundles/procurement/receiving/scan', [
            '_token' => $token,
            'po' => '0',
            'vendor' => (string) $vendor->getId(),
            'warehouse' => (string) $seed['warehouse']->getId(),
            'bin' => (string) $seed['pick']->getId(),
            'quantity' => '6',
            'code' => $seed['ean'],
        ]);
        $I->seeResponseCodeIsSuccessful();
        // The code that was scanned is read back on the page, so it can be checked against the
        // carton by eye. see()'s second argument is a CSS selector, not a message — so the reason
        // goes in a comment.
        $I->see($seed['ean']);
        // Read out of the flash, and anchored on the em dash that precedes the figure (#627):
        // see('6 on the slip') is a substring match, so a tally of 16 or 106 satisfied it too.
        $I->assertStringEndsWith(
            ' — 6 on the slip.',
            trim(preg_replace('/\s+/', ' ', $I->grabTextFrom('div.flash-success'))),
            'the running scan tally held in the URL, six units on this code',
        );

        // Still nothing written: a scan changes the URL and only the URL.
        $I->assertNull(
            $em->getRepository(GoodsReceipt::class)->findOneBy(['vendor' => $vendor]),
            'scanning must not write a receipt — only the confirmation does',
        );

        // Book it in, through the typed form's own route with the typed form's own field names.
        $I->sendFormPostRequest('/admin/bundles/procurement/receiving/new', [
            '_token' => $token,
            'purchase_order_id' => '0',
            'vendor_id' => (string) $vendor->getId(),
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'packing_slip' => 'SCAN-SLIP-1',
            'lines' => [
                ['product_id' => (string) $seed['product']->getId(), 'quantity' => '6', 'location_id' => (string) $seed['pick']->getId()],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $receipt = $em->getRepository(GoodsReceipt::class)->findOneBy(['vendor' => $vendor]);
        $I->assertInstanceOf(GoodsReceipt::class, $receipt);
        $I->assertSame('SCAN-SLIP-1', $receipt->getPackingSlip());
        $I->assertCount(1, $receipt->getLines());
        $I->assertSame($seed['product']->getId(), $receipt->getLines()->first()->getProduct()?->getId());

        $row = $em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $seed['product'],
            'location' => $seed['pick'],
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);
        $I->assertInstanceOf(InventoryDetail::class, $row);
        // 40 were already on that shelf when the fixture put them there, so what a scanned receipt
        // of six proves is the DELTA. Asserting 6 would have been asserting that receiving replaced
        // the shelf rather than adding to it.
        $I->assertSame('46.0000', $row->getQuantity(), 'the scanned units reached stock through the same movement service a typed receipt uses');
    }

    /**
     * A code that resolves to nothing has to block loudly and write nothing at all. Silent failure
     * is how stock goes missing.
     */
    public function anUnknownCodeAtGoodsInBlocksLoudlyAndWritesNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $before = \count($em->getRepository(GoodsReceipt::class)->findAll());

        $start = sprintf('/admin/bundles/procurement/receiving/scan?warehouse=%d', $seed['warehouse']->getId());
        $token = $this->tokenOn($I, $start);

        $I->sendFormPostRequest('/admin/bundles/procurement/receiving/scan', [
            '_token' => $token,
            'po' => '0',
            'warehouse' => (string) $seed['warehouse']->getId(),
            'quantity' => '1',
            'code' => 'NOT-A-BARCODE-9',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('is not a SKU and is not a barcode on any product');
        $I->see('Nothing scanned yet.');

        $I->assertCount($before, $em->getRepository(GoodsReceipt::class)->findAll(), 'a refused scan must write nothing');

        // Not "no row exists" — the fixture deliberately put 40 on the pick face, and a test that
        // asserted absence would pass just as well against a warehouse with no stock in it. What a
        // refused scan must not do is CHANGE the number.
        $onShelf = $em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $seed['product'],
            'location' => $seed['pick'],
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);
        $em->refresh($onShelf);
        $I->assertSame('40.0000', $onShelf->getQuantity(), 'a refused scan must not move a single unit');
    }

    /**
     * The substitution case #607 raises: a vendor ships an item that is not on the purchase order.
     *
     * The scan records it against the receipt with no order line at all, which
     * `goods_receipt_line.purchase_order_line_id` has always allowed. Refusing to record what is
     * physically on the dock would be worse than the missing paperwork.
     */
    public function aScannedItemThatIsNotOnThePurchaseOrderIsCalledOutAsASubstitution(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $ordered = (new ProductCore())
            ->setSku('ORDERED-' . strtoupper(substr(uniqid(), -6)))
            ->setName('What was ordered')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($ordered);

        $vendor = (new Vendor())->setName('Substituting Supplier ' . uniqid())->setStatus('Active');
        $em->persist($vendor);

        $order = (new PurchaseOrder())
            ->setPoNumber('PO-SUB-' . strtoupper(substr(uniqid(), -6)))
            ->deriveTaxProvinceFrom($seed['warehouse']);
        $order->setVendor($vendor)->setVendorName($vendor->getName());
        $order->addLine(
            (new PurchaseOrderLine())
                ->setProduct($ordered)
                ->setName($ordered->getName())
                ->setSku($ordered->getSku())
                ->setQuantityOrdered('10')
                ->setUnitCost('4.0000')
        );
        $em->persist($order);
        $em->flush();

        $start = '/admin/bundles/procurement/receiving/scan?po=' . $order->getId();
        $token = $this->tokenOn($I, $start);

        $I->sendFormPostRequest('/admin/bundles/procurement/receiving/scan', [
            '_token' => $token,
            'po' => (string) $order->getId(),
            'quantity' => '3',
            'code' => $seed['ean'],
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('not on this order');
    }

    // ---------------------------------------------------------------- dispatch

    /**
     * Dispatch: a picker walks a released round with a scanner and scans each carton off the shelf.
     *
     * This is what dispatch means in this application today. There is no customer shipment screen —
     * #593 is parked because core has no action that says the goods left — so the outbound door is
     * the pick round, which moves stock off the shelf into the outbound staging bin. That is the
     * last moment anybody has a carton in their hands, and it is where the scanner belongs.
     *
     * The scanned value is again the vendor's EAN rather than the SKU, because that is what is
     * printed on the box the picker is holding.
     */
    public function aRoundIsDispatchedByScanningEachCartonWithNoJavascript(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 40);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $list = $this->releasedRound($I, $seed, 3);
        $task = $list->getTasks()->first();
        $I->assertInstanceOf(PickTask::class, $task);

        $scan = '/admin/bundles/warehouse-ops/pick-lists/' . $list->getId() . '/scan';
        $token = $this->tokenOn($I, $scan);
        $I->seeElement('input[name="code"][autofocus]');

        // Scan the shelf first, so what leaves is recorded as having come off it.
        $I->sendFormPostRequest($scan, ['_token' => $token, 'code' => 'SP-01']);
        $I->seeResponseCodeIsSuccessful();
        $I->see('At SP-01');

        // Then three cartons, one scan each, by the barcode printed on them.
        $counted = 0;
        foreach ([1, 2, 3] as $unit) {
            $params = ['_token' => $token, 'bin' => (string) $seed['pick']->getId(), 'code' => $seed['ean']];
            if ($counted > 0) {
                $params['counted'] = [(string) $task->getId() => (string) $counted];
                $params['source'] = [(string) $task->getId() => (string) $seed['pick']->getId()];
            }

            $I->sendFormPostRequest($scan, $params);
            $I->seeResponseCodeIsSuccessful();
            $counted = $unit;
        }

        // Anchored on the em dash, out of the flash (#627): see('3 of 3') is satisfied by
        // '13 of 3' and by '3 of 30' alike, and the tally is the whole point of the screen.
        $I->assertStringContainsString(
            ' — 3 of 3 for ',
            trim(preg_replace('/\s+/', ' ', $I->grabTextFrom('div.flash-success'))),
            'three scans counted against a pick task requesting three',
        );

        // Nothing has moved yet: the tally is in the URL and only in the URL.
        $em->refresh($task);
        $I->assertSame('0.0000', $task->getQuantityPicked(), 'scanning must not move stock — only the confirmation does');

        // Confirm, through the typed screen's own route with the typed screen's own field names.
        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/pick-lists/' . $list->getId() . '/confirm', [
            '_token' => $token,
            'op_id' => 'cest-scan-dispatch-1',
            'picked' => [(string) $task->getId() => '3'],
            'picked_from' => [(string) $task->getId() => (string) $seed['pick']->getId()],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em->refresh($task);
        $I->assertSame('3.0000', $task->getQuantityPicked(), 'a scanned round confirms through the same service a typed one does');

        $staged = $em->getRepository(InventoryDetail::class)->findOneBy([
            'product' => $seed['product'],
            'location' => $seed['staging'],
            'status' => InventoryDetail::STATUS_AVAILABLE,
        ]);
        $I->assertInstanceOf(InventoryDetail::class, $staged);
        $I->assertSame('3.0000', $staged->getQuantity(), 'the scanned units are in the outbound staging bin');
    }

    /** A code that means nothing on this round blocks loudly and records nothing. */
    public function anUnknownCodeAtDispatchBlocksLoudlyAndWritesNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 40);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $list = $this->releasedRound($I, $seed, 3);
        $task = $list->getTasks()->first();

        $scan = '/admin/bundles/warehouse-ops/pick-lists/' . $list->getId() . '/scan';
        $token = $this->tokenOn($I, $scan);

        $I->sendFormPostRequest($scan, ['_token' => $token, 'code' => 'NOT-ANYTHING-AT-ALL']);
        $I->seeResponseCodeIsSuccessful();
        $I->see('is not a bin in');
        $I->see('is not a SKU or a barcode on any product');

        $em->refresh($task);
        $I->assertSame('0.0000', $task->getQuantityPicked());
        $I->assertNull(
            $em->getRepository(InventoryDetail::class)->findOneBy([
                'product' => $seed['product'],
                'location' => $seed['staging'],
            ]),
            'a refused scan must not stage a single unit',
        );
    }

    /**
     * A round cannot dispatch more than it was compiled for. Scanning a fourth carton against a
     * round that asked for three is refused rather than overflowing into the next order's task.
     */
    public function scanningMoreThanTheRoundAsksForIsRefused(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seed($I, 40);

        $list = $this->releasedRound($I, $seed, 2);
        $task = $list->getTasks()->first();

        $scan = '/admin/bundles/warehouse-ops/pick-lists/' . $list->getId() . '/scan';
        $token = $this->tokenOn($I, $scan);

        $I->sendFormPostRequest($scan, [
            '_token' => $token,
            'counted' => [(string) $task->getId() => '2'],
            'code' => $seed['ean'],
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('has already been scanned');
    }

    /**
     * A released round for `$quantity` of the seeded product, with stock on the shelf to pick from.
     */
    private function releasedRound(FunctionalTester $I, array $seed, int $quantity): PickList
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $company = (new Company())->setName('Scan Co ' . uniqid())->setCode('SC' . strtoupper(substr(uniqid(), -5)));
        $em->persist($company);

        $order = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('SO-SCAN-' . strtoupper(substr(uniqid(), -6)))
            ->setFulfillmentRegion($seed['region']->getName());
        $order->addLine(
            (new SalesOrderLine())
                ->setProduct($seed['product'])
                ->setName($seed['product']->getName())
                ->setSku($seed['product']->getSku())
                ->setQuantity((string) $quantity)
        );
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $em->persist($order);
        $em->flush();

        $I->amOnPage('/admin/bundles/warehouse-ops/pick-lists/new');
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/pick-lists/new', [
            '_token' => $token,
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'orders' => [(string) $order->getId()],
            'assigned_to' => 'scanner',
        ]);

        $list = $em->getRepository(PickList::class)->findOneBy([], ['id' => 'DESC']);
        $I->assertInstanceOf(PickList::class, $list);

        $I->sendFormPostRequest('/admin/bundles/warehouse-ops/pick-lists/' . $list->getId() . '/release', [
            '_token' => $token,
            'staging_location_id' => (string) $seed['staging']->getId(),
            'assigned_to' => 'scanner',
        ]);

        $em->refresh($list);

        return $list;
    }
}
