<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\WarehouseLocation;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\Vendor;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * One conducted run of the real buy-and-sell chain — Purchase Order, Goods Receipt, Vendor Bill,
 * Estimate, Sales Order, Invoice — checking that `product_inventory`'s bucket columns still
 * reconcile against `inventory_detail` after every step that could plausibly disturb them.
 *
 * Rebuilds `tests/Functional/WarehouseToInvoiceWalkthroughCest.php`, deleted in commit `3afde7e0`
 * ("Rebuild PurchaseOrder edit/detail...") "pending VendorBill's own equivalent rebuild" — which
 * landed separately (`ea227605`) but the walkthrough itself was never restored. See
 * `docs/plans` history and this session's investigation for the full account: the trigger was a
 * false "NO — run app:inventory-depth:detail-check" on the Stock hub's Reconciliation card, traced
 * to that card comparing the wrong two numbers (fixed separately in `StockController::byProduct()`)
 * — not to any actual drift in the accounting. This walkthrough is the standing proof of that: every
 * dimensional identity shape the app offers (none, lot, lot+expiry, serial), driven through every
 * document that can move stock, checked against the SAME equation
 * `InventoryDetailRecalcCommand`/`app:inventory-depth:detail-check` uses.
 *
 * Scoped to DIMENSIONAL products only, on purpose: "bucket reconciles against detail" is a question
 * about the detail layer, and a simple-mode product has none — `StockMovementService` skips writing
 * detail rows for one entirely (see its own docblock, "dimensional reads as simple everywhere").
 * {@see self::aSimpleProductsCompletedInvoiceWritesNoSoldDetailRowAtAll()} covers that shape
 * separately, with its own much smaller fixture, rather than forcing a fifth product through a
 * chain built to exercise the detail layer a simple product does not have.
 *
 * One warehouse serves both sides on purpose: `haveActiveFulfillmentRegionFor()` finds a region by
 * NAME and reuses whatever warehouse already serves it rather than minting a second one, so
 * receiving against this warehouse on the buy side and reserving from the same warehouse on the
 * sell side are the same stock, the way a real single-location wholesaler's would be.
 */
final class WarehouseToInvoiceWalkthroughCest
{
    private const REGION = 'Walkthrough Region';

    private const RECEIVED_QTY = '20.00';

    private const ORDERED_QTY = '5.00';

    private ?Warehouse $warehouse = null;

    private ?WarehouseLocation $bin = null;

    private ?Vendor $vendor = null;

    private ?Company $company = null;

    /** @var array<string, ProductCore> tracking shape ('none'|'lot'|'lotexp'|'serial') => product */
    private array $products = [];

    /** @var array<string, string> shape => the lot code receiveAgainstPurchaseOrder() wrote for it (lot/lotexp only) */
    private array $lotCodes = [];

    public function _before(FunctionalTester $I): void
    {
        $this->warehouse = null;
        $this->bin = null;
        $this->vendor = null;
        $this->company = null;
        $this->products = [];
        $this->lotCodes = [];
    }

    /**
     * Serial identity is one unit per row by definition, so a serial-tracked line orders/invoices
     * exactly 1 — every other shape orders the shared ORDERED_QTY. Sales-side identity capture
     * (SalesOrderLine::$lotId/$serial) is a single value per LINE regardless of quantity, which a
     * lot line can satisfy (the whole quantity comes off one batch) but a multi-unit serial line
     * structurally cannot — there is no single serial that names five different units.
     */
    private function orderedQtyFor(string $shape): string
    {
        return $shape === 'serial' ? '1.00' : self::ORDERED_QTY;
    }

    // ============================================================================== the walkthrough

    /**
     * The whole chain in one conducted run: issue a PO for four products (one per dimensional
     * identity shape), receive it, bill and approve it, quote and convert an order for the same
     * stock, invoice and complete it — asserting reconciliation after every step that could move a
     * bucket.
     */
    public function theWholeChainReconcilesAtEveryStep(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seedWarehouseAndProducts($I);
        $this->seedVendor($I);

        // ---- Step 1: Purchase Order — a promise, not stock. Nothing has moved yet.
        $poId = $this->createAndIssuePurchaseOrder($I);
        foreach ($this->products as $product) {
            $this->assertReconciles($I, $product, 'after PO issue', expectHeld: 0, expectDetail: 0);
        }

        // ---- Step 2: Goods Receipt — physical stock lands, one row per identity shape.
        $this->receiveAgainstPurchaseOrder($I, $poId);
        foreach ($this->products as $product) {
            $this->assertReconciles(
                $I,
                $product,
                'after receiving',
                expectHeld: (int) (float) self::RECEIVED_QTY,
                expectDetail: (int) (float) self::RECEIVED_QTY,
            );
        }

        // ---- Step 3: Vendor Bill — a financial document. Must not move any bucket at all.
        $billId = $this->createAndApproveBill($I, $poId, self::RECEIVED_QTY);
        $I->see('The three-way match was clean', '.flash-success, .flash-error');
        foreach ($this->products as $product) {
            $this->assertReconciles(
                $I,
                $product,
                'after a cleanly-matched bill approval — billing must not move stock',
                expectHeld: (int) (float) self::RECEIVED_QTY,
                expectDetail: (int) (float) self::RECEIVED_QTY,
            );
        }

        // ---- Step 4: Estimate -> accept -> convert. A sales order is a promise on the sell side,
        // the same way a PO is on the buy side: held and the detail rows are untouched by it, only
        // "available to sell" (a claim on top of Held, not Held itself) moves.
        $estimate = $this->seedPricedEstimate($I);
        $this->postAccept($I, $estimate);
        $this->postConvert($I, $estimate);
        $orderId = $this->onlySalesOrderId($I);

        foreach ($this->products as $product) {
            $this->assertReconciles(
                $I,
                $product,
                'after estimate acceptance and conversion — a sales order reserves, it does not move detail',
                expectHeld: (int) (float) self::RECEIVED_QTY,
                expectDetail: (int) (float) self::RECEIVED_QTY,
            );
        }

        // ---- Step 5+6: Invoice, then complete it. Completing is what actually fires the `sold`
        // movement (Invoice::setStatus('Completed', ...) -> InvoiceShippingRemainderSubscriber ->
        // ShipmentService::ship() -> StockMovementService::apply()) — confirmed by direct source
        // trace during this rebuild's research, and re-confirmed here by assertion.
        $invoiceId = $this->createIssueAndCompleteInvoice($I, $orderId);

        $received = (int) (float) self::RECEIVED_QTY;
        foreach ($this->products as $shape => $product) {
            // 'none' carries neither identity direction (see seedWarehouseAndProducts()'s comment),
            // so ShipmentService::tracksOutbound() gates it onto the same untracked path a simple
            // product takes: a ShipmentLine is written, but no stock actually withdraws — held and
            // the detail rows sit exactly where receiving left them. The other three DO track
            // outbound, and completing the invoice must draw the ordered quantity down from both.
            // (Serial orders/invoices exactly 1 — see orderedQtyFor()'s own docblock.)
            $ordered = (int) (float) $this->orderedQtyFor($shape);
            $expectedRemaining = $shape === 'none' ? $received : $received - $ordered;
            $expectedSold = $shape === 'none' ? 0 : $ordered;

            $this->assertReconciles(
                $I,
                $product,
                sprintf('after the invoice completed (%s)', $shape),
                expectHeld: $expectedRemaining,
                expectDetail: $expectedRemaining,
            );

            $I->assertSame(
                $expectedSold,
                $this->soldDetailTotal($I, $product),
                sprintf(
                    '%s: completing the invoice must write %s a `sold` detail row',
                    $shape,
                    $shape === 'none' ? 'no' : 'exactly the ordered quantity to',
                ),
            );
        }

        // ---- Step 7: whole-database cross-check, through the app's own real reconciliation tool —
        // not just this test's own arithmetic.
        $this->assertDetailCheckClean($I);

        // Guard against the assertion above passing for the wrong reason (nothing to check): if the
        // seeded products were not actually on dimensional inventory, "0 checked, nothing drifted"
        // would look identical to "4 checked, nothing drifted".
        $I->assertGreaterThanOrEqual(4, $this->detailCheckedCount, 'guard: the command must have actually checked these four rows');

        $I->assertNotNull($invoiceId);
        $I->assertNotNull($billId);
    }

    // =========================================================== the bill's three-way match, on its own

    /**
     * A bill for MORE than has actually been received — but still within what the PO ordered —
     * still approves (VendorBillController::approve() never refuses), but records a three-way-match
     * exception on its timeline instead of silently passing. The original walkthrough's whole reason
     * for driving billing before/after receiving was exactly this kind of contradiction; this is its
     * current-screens equivalent.
     *
     * Billed for the PO's FULL ordered quantity while NOTHING has been received yet, rather than for
     * more than was ordered: VendorBillController::save() has its own separate, harder guard
     * refusing a bill beyond the ORDERED quantity outright ("999 requested but only 20 left to bill
     * on purchase order PO-1"), which is a different rule from the three-way match this test is
     * about. Ordered-but-unreceived is exactly what #698's "the whole order can be billed before
     * anything is received" scenario names.
     */
    public function aBillForMoreThanWasReceivedApprovesWithAnException(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seedWarehouseAndProducts($I);
        $this->seedVendor($I);

        $poId = $this->createAndIssuePurchaseOrder($I);
        // Deliberately no receiveAgainstPurchaseOrder() call — billed against a PO nothing has
        // arrived against yet.

        $this->createAndApproveBill($I, $poId, self::RECEIVED_QTY);

        $I->see('match exception', '.flash-success, .flash-error');
        $I->dontSee('The three-way match was clean', '.flash-success, .flash-error');

        // And billing — clean or not — still must not have moved a single bucket. Nothing was
        // received, so held/detail stay at 0, exactly as after PO issue alone.
        foreach ($this->products as $shape => $product) {
            $this->assertReconciles(
                $I,
                $product,
                sprintf('after billing against an unreceived PO (%s) — an exception is recorded, stock is not', $shape),
                expectHeld: 0,
                expectDetail: 0,
            );
        }
    }

    // ================================================================== the simple-product contrast

    /**
     * A simple-mode product's completed invoice writes a ShipmentLine but NO `sold`-status detail
     * row at all — there is no detail layer to write one into. Kept apart from the main walkthrough,
     * with its own much smaller fixture (`haveStockFor()`, the suite's usual shortcut, rather than a
     * real PO/receipt — the provenance of a simple product's stock is not what this test is about).
     */
    public function aSimpleProductsCompletedInvoiceWritesNoSoldDetailRowAtAll(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())->setName('Walkthrough Simple Co')->setCode('WSC-' . uniqid());
        $I->haveInRepository($company);
        $regionName = $I->haveActiveFulfillmentRegionFor($company, 'Simple Contrast Region ' . uniqid());
        $I->haveInRepository(
            (new CompanyAddress())
                ->setCompany($company)->setLabel('Main')
                ->setAddressLine1('1 Wholesale Way')->setCity('Vancouver')->setProvince('BC')->setPostalCode('V5K0A1')->setCountry('CA')
                ->setIsDefaultShipping(true)->setIsDefaultBilling(true)
        );

        $product = (new ProductCore())
            ->setSku('WALK-SIMPLE-' . strtoupper(substr(uniqid(), -6)))
            ->setName('Walkthrough Simple Widget')
            ->setUnit('EA')->setSalesTaxCode('E')->setCostPrice('4.00')->setDefaultPrice('10.00')->setOriginalPrice('10.00')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $I->haveStockFor($product, 20, $regionName);

        $estimate = (new Estimate())
            ->setCompany($company)->setDocumentNumber('WSC-' . uniqid())->setSource('Customer')
            ->setFulfillmentRegion($regionName)
            ->setFeeLines(json_encode([['slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'E', 'amount' => 0.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc']]))
            ->setSubtotal('50.00')->setTax('0.00')->setTotal('50.00');
        $estimate->setStatus('Priced', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())->setProduct($product)->setName($product->getName())->setSku($product->getSku())
                ->setLocation($regionName)->setQuantity('5.00')->setPrice('10.00')->setSubtotal('50.00')
        );
        $I->haveInRepository($estimate);

        $this->postAccept($I, $estimate);
        $this->postConvert($I, $estimate);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $orderId = (int) $entityManager->getConnection()->fetchOne(
            'SELECT id FROM sales_order WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [(int) $company->getId()],
        );
        $I->assertGreaterThan(0, $orderId, 'guard: the conversion must have raised an order');

        $order = $entityManager->find(SalesOrder::class, $orderId);
        $line = $order->getLines()->first();

        $I->amOnPage('/admin/order/detail/' . $orderId);
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $orderId, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            'lines' => [[
                'sales_order_line_id' => (string) $line->getId(),
                'product_id' => (string) $product->getId(),
                'qty' => '5.00',
            ]],
        ]);

        $entityManager->clear();
        $invoiceId = (int) $entityManager->getConnection()->fetchOne(
            'SELECT id FROM invoice WHERE sales_order_id = ? ORDER BY id DESC LIMIT 1',
            [$orderId],
        );
        $I->assertGreaterThan(0, $invoiceId, 'guard: the create must have raised an invoice');
        $I->assertSame(
            1,
            (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice_line WHERE invoice_id = ?', [$invoiceId]),
            'guard: the invoice must actually carry the line it was created with',
        );

        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/action/start-processing', ['_token' => $I->csrfToken()]);
        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/action/complete', ['_token' => $I->csrfToken()]);

        $entityManager->clear();

        $I->assertSame(
            0,
            (int) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM inventory_detail WHERE product_id = ? AND status = ?',
                [(int) $product->getId(), InventoryDetail::STATUS_SOLD],
            ),
            'a simple-mode product has no detail layer, so completing its invoice must write no `sold` row at all',
        );
        $invoiceLineId = (int) $entityManager->getConnection()->fetchOne('SELECT id FROM invoice_line WHERE invoice_id = ?', [$invoiceId]);
        $I->assertSame(
            1,
            (int) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM shipment_line sl JOIN shipment s ON s.id = sl.shipment_id WHERE sl.invoice_line_id = ?',
                [$invoiceLineId],
            ),
            'the shipment line must still exist — the shape that carries the movement gets skipped, not the record of shipping',
        );
    }

    // ======================================================================================= seeding

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('walkthrough-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * The warehouse first, reference data last — see InventoryDepthCest::seedDimensionalStock()'s
     * docblock for why: `amLoggedInAs()` never fires LoginSuccessEvent, so nothing is seeded unless
     * asked, and the warehouse has to exist before the region-serving check inside
     * haveSeededReferenceData()'s own dependents runs.
     */
    private function seedWarehouseAndProducts(FunctionalTester $I): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);

        $region = (new FulfillmentRegion())->setName(self::REGION);
        $entityManager->persist($region);
        $this->warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $this->bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('A-01')->setSortKey(1);
        $entityManager->persist($this->bin);
        $entityManager->flush();

        $I->haveSeededReferenceData();

        $policies = [
            // 'none' deliberately carries neither direction: it is the truly untracked shape, and
            // ShipmentService::tracksOutbound() gates the WHOLE movement-writing behaviour on
            // trackOut for the product's mode — a none-mode product never qualifies, whatever its
            // flags say, so this one exercises the "dimensional, but ships without moving a bucket
            // or writing a `sold` row" path, exactly like a simple product but with detail rows that
            // sit there unconsumed. See assertReconciles()'s call for this shape after Step 6.
            'none' => (new TrackingPolicy())->setName('Walkthrough None ' . uniqid())->setMode(TrackingPolicy::MODE_NONE),
            // The other three are trackOut(true) as well as trackIn(true) — capturing on receipt is
            // not enough to exercise the `sold` movement this walkthrough exists to check; shipping
            // has to be able to WITHDRAW by that identity too, or completing the invoice takes the
            // same untracked path 'none' does and Step 6 proves nothing about the lot/serial case.
            'lot' => (new TrackingPolicy())->setName('Walkthrough Lot ' . uniqid())->setMode(TrackingPolicy::MODE_LOT)->setTrackIn(true)->setTrackOut(true),
            'lotexp' => (new TrackingPolicy())->setName('Walkthrough Lot+Expiry ' . uniqid())->setMode(TrackingPolicy::MODE_LOT)->setTrackIn(true)->setTrackOut(true)->setRequiresExpiry(true),
            'serial' => (new TrackingPolicy())->setName('Walkthrough Serial ' . uniqid())->setMode(TrackingPolicy::MODE_SERIAL)->setTrackIn(true)->setTrackOut(true),
        ];

        foreach ($policies as $shape => $policy) {
            $entityManager->persist($policy);

            $product = (new ProductCore())
                ->setSku('WALK-' . strtoupper($shape) . '-' . strtoupper(substr(uniqid(), -6)))
                ->setName('Walkthrough ' . ucfirst($shape) . ' Widget')
                ->setUnit('EA')
                ->setSalesTaxCode('E')
                ->setCostPrice('4.00')
                ->setDefaultPrice('10.00')
                ->setOriginalPrice('10.00')
                ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
                ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
                ->setTrackingPolicy($policy);
            $entityManager->persist($product);
            $entityManager->persist((new ProductInventory())->setProduct($product)->setWarehouse($this->warehouse));

            $this->products[$shape] = $product;
        }

        $entityManager->flush();
    }

    private function seedVendor(FunctionalTester $I): void
    {
        $this->vendor = (new Vendor())->setName('Walkthrough Vendor ' . uniqid());
        $I->haveInRepository($this->vendor);
    }

    // ================================================================================== buy-side flow

    private function createAndIssuePurchaseOrder(FunctionalTester $I): int
    {
        $I->amOnPage('/admin/bundles/procurement/purchase-orders/new');
        $token = $I->csrfToken();

        $lines = [];
        $i = 0;
        foreach ($this->products as $product) {
            $lines[$i] = ['product_id' => (string) $product->getId(), 'qty' => self::RECEIVED_QTY, 'unit_cost' => '4.00'];
            ++$i;
        }

        $I->sendFormPostRequest('/admin/bundles/procurement/purchase-orders/save', [
            '_token' => $token,
            'id' => '0',
            'vendor_id' => (string) $this->vendor->getId(),
            'warehouse_id' => (string) $this->warehouse->getId(),
            'lines' => $lines,
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $poId = (int) $entityManager->getConnection()->fetchOne(
            'SELECT id FROM purchase_order WHERE vendor_id = ? ORDER BY id DESC LIMIT 1',
            [(int) $this->vendor->getId()],
        );
        $I->assertGreaterThan(0, $poId, 'guard: the save must have created a purchase order');

        $I->amOnPage('/admin/bundles/procurement/purchase-orders/' . $poId);
        $I->sendFormPostRequest('/admin/bundles/procurement/purchase-orders/' . $poId . '/issue', [
            '_token' => $I->csrfToken(),
        ]);

        $entityManager->clear();
        $status = (string) $entityManager->getConnection()->fetchOne('SELECT status FROM purchase_order WHERE id = ?', [$poId]);
        $I->assertNotSame('Draft', $status, 'guard: issuing must have moved the PO off Draft — ' . $status);

        return $poId;
    }

    private function receiveAgainstPurchaseOrder(FunctionalTester $I, int $poId): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var PurchaseOrder $order */
        $order = $entityManager->find(PurchaseOrder::class, $poId);

        /** @var array<int, string> productId => purchaseOrderLineId */
        $lineIdByProduct = [];
        foreach ($order->getLines() as $line) {
            $lineIdByProduct[(int) $line->getProduct()->getId()] = (string) $line->getId();
        }

        $I->amOnPage('/admin/bundles/procurement/receiving/new?po=' . $poId);
        $token = $I->csrfToken();

        $lines = [];
        $i = 0;
        foreach ($this->products as $shape => $product) {
            $row = [
                'purchase_order_line_id' => $lineIdByProduct[(int) $product->getId()],
                'product_id' => (string) $product->getId(),
                'quantity' => self::RECEIVED_QTY,
                'location_id' => (string) $this->bin->getId(),
                'unit_cost' => '4.00',
            ];

            if ($shape === 'lot') {
                $row['lot_code'] = $this->lotCodes[$shape] = 'WALK-LOT-' . uniqid();
            }
            if ($shape === 'lotexp') {
                $row['lot_code'] = $this->lotCodes[$shape] = 'WALK-LOTEXP-' . uniqid();
                $row['expiry'] = '2028-01-01';
            }
            if ($shape === 'serial') {
                // 20 units, 20 serials — one receipt line per serial, exactly what SerialList::parse()
                // turns a newline-separated block into (see ReceivingController::submit()).
                $serials = [];
                for ($s = 1; $s <= (int) (float) self::RECEIVED_QTY; ++$s) {
                    $serials[] = 'WALK-SN-' . uniqid() . '-' . $s;
                }
                $row['serials'] = implode("\n", $serials);
                unset($row['quantity']);
            }

            $lines[$i] = $row;
            ++$i;
        }

        $I->sendFormPostRequest('/admin/bundles/procurement/receiving/new', [
            '_token' => $token,
            'purchase_order_id' => (string) $poId,
            'packing_slip' => 'WALK-PS-' . uniqid(),
            'received_by' => 'walkthrough-cest',
            'lines' => $lines,
        ]);

        $I->see('recorded', '.flash-success, .flash-error');
    }

    private function createAndApproveBill(FunctionalTester $I, int $poId, string $billQty): int
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var PurchaseOrder $order */
        $order = $entityManager->find(PurchaseOrder::class, $poId);
        $lineIdByProduct = [];
        foreach ($order->getLines() as $line) {
            $lineIdByProduct[(int) $line->getProduct()->getId()] = (string) $line->getId();
        }

        $I->amOnPage('/admin/bundles/procurement/bills/new?po=' . $poId);
        $token = $I->csrfToken();

        $lines = [];
        $i = 0;
        foreach ($this->products as $product) {
            // purchase_order_line_id is what binds this row to a specific PO line for
            // ThreeWayMatchService to compare against — VendorBillController::requestedLines()
            // reads product_id only as a FALLBACK when no PO line id is posted, and a bill line
            // with no PO line attached matches nothing, which is a BILLED_NOT_RECEIVED-shaped
            // exception on both quantity and price rather than "clean".
            $lines[$i] = [
                'purchase_order_line_id' => $lineIdByProduct[(int) $product->getId()],
                'product_id' => (string) $product->getId(),
                'qty' => $billQty,
                'unit_cost' => '4.00',
            ];
            ++$i;
        }

        $I->sendFormPostRequest('/admin/bundles/procurement/bills/save', [
            '_token' => $token,
            'id' => '0',
            'purchase_order_id' => (string) $poId,
            'lines' => $lines,
        ]);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $billId = (int) $entityManager->getConnection()->fetchOne(
            'SELECT id FROM vendor_bill WHERE purchase_order_id = ? ORDER BY id DESC LIMIT 1',
            [$poId],
        );
        $I->assertGreaterThan(0, $billId, 'guard: the save must have created a vendor bill');

        $I->amOnPage('/admin/bundles/procurement/bills/' . $billId);
        $I->sendFormPostRequest('/admin/bundles/procurement/bills/' . $billId . '/approve', [
            '_token' => $I->csrfToken(),
        ]);

        return $billId;
    }

    // ================================================================================= sell-side flow

    /**
     * A Priced quote created directly, the way AdminQuoteAcceptAndConvertCest's own fixture does —
     * accept() and convert() are the real write path this walkthrough cares about; the create form's
     * own field names are somebody else's coverage (AdminEstimateFormCest).
     */
    /**
     * By this point several real HTTP requests have already run (PO save/issue, receiving, bill
     * save/approve), and Codeception's Symfony module reboots the kernel on each one — which builds
     * a brand new container, and with it a brand new EntityManager with an empty identity map. Any
     * entity object this class is still holding from BEFORE those requests (`$this->products`,
     * `$this->warehouse`) is DETACHED from that new EM's point of view, even though its row is
     * sitting right there on disk — see AdminQuoteAcceptAndConvertCest::configureReason()'s docblock
     * for the same gotcha hit a different way. `$I->haveInRepository()` makes it worse rather than
     * sidestepping it: that helper flushes through a `$this->em` it cached on ITS OWN module
     * instance at boot, which is a THIRD, differently-stale EntityManager by now — so it and
     * `haveActiveFulfillmentRegionFor()` (which correctly re-`grabService()`s a fresh one) end up
     * disagreeing about whether the same Company row is "new". The fix is to do every persist here
     * through the one EntityManager this method grabs itself, and to re-`find()` the products by id
     * into that instance rather than reuse the (now stale) objects in `$this->products`.
     */
    private function seedPricedEstimate(FunctionalTester $I): Estimate
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);

        $this->company = (new Company())->setName('Walkthrough Buyer Co')->setCode('WALK-' . uniqid());
        $entityManager->persist($this->company);
        $entityManager->persist(
            (new CompanyAddress())
                ->setCompany($this->company)->setLabel('Main')
                ->setAddressLine1('1 Wholesale Way')->setCity('Vancouver')->setProvince('BC')->setPostalCode('V5K0A1')->setCountry('CA')
                ->setIsDefaultShipping(true)->setIsDefaultBilling(true)
        );
        $entityManager->flush();

        // Same region NAME the warehouse above already serves — haveActiveFulfillmentRegionFor()
        // finds it by name and attaches this company to it rather than minting a second warehouse,
        // so the stock just received is the stock this order reserves against.
        $I->haveActiveFulfillmentRegionFor($this->company, self::REGION);

        $subtotal = 0.0;
        foreach ($this->products as $shape => $product) {
            $subtotal += (float) $this->orderedQtyFor($shape) * 10.0;
        }

        $estimate = (new Estimate())
            ->setCompany($this->company)
            ->setDocumentNumber('WALK-' . uniqid())
            ->setSource('Customer')
            ->setFulfillmentRegion(self::REGION)
            ->setFeeLines(json_encode([[
                'slug' => 'shipping', 'label' => 'Shipping (Ground)', 'taxClass' => 'E',
                'amount' => 0.0, 'placement' => 'main_line', 'type' => 'shipping', 'source' => 'auto-calc',
            ]]))
            ->setSubtotal((string) $subtotal)
            ->setTax('0.00')
            ->setTotal((string) $subtotal);
        $estimate->setStatus('Priced', DocumentActor::system());

        foreach ($this->products as $shape => $product) {
            // Re-fetched into the CURRENT EntityManager by id — see this method's own docblock.
            $freshProduct = $entityManager->find(ProductCore::class, $product->getId());
            $qty = $this->orderedQtyFor($shape);
            $estimate->addLine(
                (new EstimateLine())
                    ->setProduct($freshProduct)
                    ->setName($freshProduct->getName())
                    ->setSku($freshProduct->getSku())
                    ->setLocation(self::REGION)
                    ->setQuantity($qty)
                    ->setPrice('10.00')
                    ->setSubtotal((string) ((float) $qty * 10.0))
            );
        }

        $entityManager->persist($estimate);
        $entityManager->flush();

        return $estimate;
    }

    /** The real Accept button's POST — same shape AdminQuoteAcceptAndConvertCest drives. */
    private function postAccept(FunctionalTester $I, Estimate $estimate): void
    {
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/accept/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
        ]);
    }

    /** The real Convert button's POST. */
    private function postConvert(FunctionalTester $I, Estimate $estimate): void
    {
        $I->amOnPage('/admin/estimate/detail/' . $estimate->getId());
        $I->sendFormPostRequest('/admin/estimate/convert/' . $estimate->getId(), [
            '_token' => $I->csrfToken(),
        ]);
    }

    private function onlySalesOrderId(FunctionalTester $I): int
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        $ids = $entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM sales_order WHERE company_id = ? ORDER BY id',
            [(int) $this->company->getId()],
        );
        $I->assertCount(1, $ids, 'expected exactly one sales order for this walkthrough\'s quote');

        return (int) $ids[0];
    }

    /**
     * The lot id (for 'lot'/'lotexp') or an available serial (for 'serial') to post on the invoice
     * line, resolved from what receiving actually wrote — `null` for 'none', which carries neither.
     */
    private function shippingIdentityFor(FunctionalTester $I, string $shape, ProductCore $product): ?string
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);

        if ($shape === 'lot' || $shape === 'lotexp') {
            $lotId = (int) $entityManager->getConnection()->fetchOne(
                'SELECT id FROM inventory_lot WHERE product_id = ? AND code = ?',
                [(int) $product->getId(), $this->lotCodes[$shape]],
            );
            $I->assertGreaterThan(0, $lotId, sprintf('guard: the %s lot received earlier must still be findable', $shape));

            return (string) $lotId;
        }

        if ($shape === 'serial') {
            $serial = (string) $entityManager->getConnection()->fetchOne(
                "SELECT serial FROM inventory_detail WHERE product_id = ? AND status = 'available' AND serial IS NOT NULL LIMIT 1",
                [(int) $product->getId()],
            );
            $I->assertNotSame('', $serial, 'guard: a received serial must still be available to assign');

            return $serial;
        }

        return null;
    }

    /**
     * Creates the invoice straight through the real form fields `sales_line_row.html.twig` renders
     * for a tracked-outbound line — `lines[N][lot_id]`/`lines[N][serial]` — the same picker an admin
     * uses, not a workaround. Exercises the fix this rebuild found and made:
     * `InvoiceController::postedInvoiceLineRows()` never forwarded either field into the row
     * `applyLineRows()` builds an `InvoiceLine` from, so `MandatoryCaptureGuard` refused every
     * tracked-outbound invoice line leaving Draft regardless of what an admin picked on the form —
     * see this session's fix in `InvoiceController.php` (both methods) for the full account.
     */
    private function createIssueAndCompleteInvoice(FunctionalTester $I, int $orderId): int
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();
        /** @var SalesOrder $order */
        $order = $entityManager->find(SalesOrder::class, $orderId);

        $shapeByProductId = [];
        foreach ($this->products as $shape => $product) {
            $shapeByProductId[(int) $product->getId()] = $shape;
        }

        $lines = [];
        foreach ($order->getLines() as $line) {
            $shape = $shapeByProductId[(int) $line->getProduct()->getId()] ?? 'none';
            $row = [
                'sales_order_line_id' => (string) $line->getId(),
                'product_id' => (string) $line->getProduct()->getId(),
                'qty' => $this->orderedQtyFor($shape),
            ];

            $identity = $this->shippingIdentityFor($I, $shape, $line->getProduct());
            if ($identity !== null) {
                $row[$shape === 'serial' ? 'serial' : 'lot_id'] = $identity;
            }

            $lines[] = $row;
        }

        $I->amOnPage('/admin/order/detail/' . $orderId);
        $I->sendFormPostRequest('/admin/invoice/create?order_id=' . $orderId, [
            '_token' => $I->csrfToken(),
            'save_mode' => 'issue',
            // The real form pre-fills this select from $order->getFulfillmentRegion() and a browser
            // posts back whatever is selected; InvoiceController::create() only ever reads it off
            // the POST (`$request->get('fulfillment_region')`), with no fallback to the order's own
            // region if it's absent — so a raw POST has to name it explicitly, or the invoice (and
            // every line that falls back to it) saves with fulfillmentRegion NULL, and
            // ShipmentService can then resolve a warehouse for none of its lines.
            'fulfillment_region' => self::REGION,
            'lines' => $lines,
        ]);

        $entityManager->clear();
        $invoiceId = (int) $entityManager->getConnection()->fetchOne(
            'SELECT id FROM invoice WHERE sales_order_id = ? ORDER BY id DESC LIMIT 1',
            [$orderId],
        );
        $I->assertGreaterThan(0, $invoiceId, 'guard: creating the invoice must have written one');
        $I->assertSame(
            count($this->products),
            (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice_line WHERE invoice_id = ?', [$invoiceId]),
            'guard: the invoice must carry one line per product it was created with',
        );

        // Pending -> Processing -> Completed. Completing directly from Pending is refused
        // (Invoice::assertStatusChangeAllowed(): Completed is only reachable from Processing).
        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/action/start-processing', [
            '_token' => $I->csrfToken(),
        ]);

        $I->amOnPage('/admin/invoice/detail/' . $invoiceId);
        $I->sendFormPostRequest('/admin/invoice/' . $invoiceId . '/action/complete', [
            '_token' => $I->csrfToken(),
        ]);

        $entityManager->clear();
        $status = (string) $entityManager->getConnection()->fetchOne('SELECT status FROM invoice WHERE id = ?', [$invoiceId]);
        $I->assertSame('Completed', $status, 'guard: the invoice must actually have reached Completed');

        return $invoiceId;
    }

    // ============================================================================= reconciliation reads

    /**
     * Reads the Stock hub's own Reconciliation card for one product — the same XPath
     * InventoryDepthCest::theProductScreenShowsTheTotalReconcilingToTheInventoryNumber() uses, keyed
     * to the "Reconciliation" heading's nearest enclosing <section> so it survives sitting beside
     * other `table-card company-detail-card` sections on the same page.
     */
    private function assertReconciles(
        FunctionalTester $I,
        ProductCore $product,
        string $context,
        int $expectHeld,
        int $expectDetail,
    ): void {
        $I->amOnPage('/admin/bundles/inventory-depth/stock/product/' . $product->getId());
        $I->seeResponseCodeIsSuccessful();

        $row = '//h2[text()="Reconciliation"]/ancestor::section[1]//table/tbody/tr[1]/';

        $I->assertSame(
            (string) $expectHeld,
            trim($I->grabTextFrom($row . 'td[2]/strong')),
            sprintf('%s (%s): Held', $product->getSku(), $context),
        );
        $I->assertSame(
            (string) $expectDetail,
            trim($I->grabTextFrom($row . 'td[3]/strong')),
            sprintf('%s (%s): sum of available detail', $product->getSku(), $context),
        );
        $I->assertSame(
            'yes',
            trim($I->grabTextFrom($row . 'td[4]')),
            sprintf('%s (%s): Agrees — a mismatch prints "NO — run app:inventory-depth:detail-check"', $product->getSku(), $context),
        );
    }

    private function soldDetailTotal(FunctionalTester $I, ProductCore $product): int
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $entityManager->clear();

        return (int) $entityManager->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
            [(int) $product->getId(), InventoryDetail::STATUS_SOLD],
        );
    }

    /** How many stock rows the command actually examined — set by assertDetailCheckClean(). */
    private int $detailCheckedCount = 0;

    /**
     * The whole-database cross-check, through the app's real `app:inventory-depth:detail-check` —
     * not this test's own arithmetic repeated a second time. Same CommandTester pattern
     * WarehouseOpsDiscrepanciesCest already uses for its own bundle's equivalent command.
     */
    private function assertDetailCheckClean(FunctionalTester $I): void
    {
        $tester = new CommandTester(
            (new Application($I->grabService('kernel')))->find('app:inventory-depth:detail-check')
        );
        $tester->execute([]);

        $output = $tester->getDisplay();
        $I->assertStringContainsString(
            'every one reconciles to its detail',
            $output,
            "app:inventory-depth:detail-check must report clean; got:\n" . $output,
        );

        if (preg_match('/(\d+) dimensional stock row\(s\) checked/', $output, $m) === 1) {
            $this->detailCheckedCount = (int) $m[1];
        }
    }
}
