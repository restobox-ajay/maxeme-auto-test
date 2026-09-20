<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use InventoryDepthBundle\Entity\InventoryAdjustmentReason;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Inventory\InventoryModeSwitcher;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * InventoryDepthBundle's admin screens (#550), end to end through the real kernel.
 *
 * The PHPUnit tests cover the invariant; this covers the half they cannot reach — that every
 * screen renders, that every filter is a GET parameter a copied URL reproduces, and that the core
 * inventory grid swaps the editable box for a link once a product is dimensional.
 *
 * Several of these screens run DQL the unit tests never execute (the movement ledger's ten-way
 * filter, the lot EXISTS subquery), so rendering them at all is a real assertion.
 */
final class InventoryDepthCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('inventory-depth-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * A dimensional product with stock, in a building, with the shipped reference rows in place.
     *
     * ## Why the reference data is seeded here and not in {@see self::loginAsAdmin()}
     *
     * The adjustment screen's reason list used to be created by RENDERING the screen:
     * `AdjustmentController::form()` called `ensureCatalogue()` on its way to the template, so
     * `inventory_adjustment_reason` did not exist until somebody opened the page that lists it.
     * 1970b7b0 removed that write-on-read and moved the rows into a seeder that runs once, on
     * `LoginSuccessEvent`.
     *
     * `amLoggedInAs()` does not dispatch that event — it installs a token straight into token
     * storage and never runs the authenticator — so logging in this way seeds nothing.
     * `haveSeededReferenceData()` is the helper the repo added for exactly that; see
     * `ReferenceDataSeedingCest`, which posts the real login form and exercises the subscriber.
     *
     * It sits at the END of this method, after the region and its warehouse, because that is the
     * ordering `WarehouseToInvoiceWalkthroughCest` had to use: `FulfillmentRegionSeeder` seeds only
     * a completely empty table, and `createRegionForWarehouse()` asks whether THIS warehouse has a
     * region rather than whether a region of that name already exists — so seeding first and then
     * creating a warehouse named after a shipped region produces a duplicate. Nothing here is named
     * after one (the region is `Depth Region <uniqid>`, and `createWarehouseForRegion()` runs the
     * other way round), but the ordering is followed rather than relied upon not to matter, so
     * renaming the fixture later cannot quietly reach that defect.
     *
     * @return array{product: ProductCore, warehouse: Warehouse, bin: WarehouseLocation, lot: InventoryLot}
     */
    private function seedDimensionalStock(FunctionalTester $I, int $quantity = 47): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');

        $region = (new FulfillmentRegion())->setName('Depth Region ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('DEPTH-' . strtoupper(substr(uniqid(), -6)))
            ->setName('Depth Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity($quantity));

        $bin = (new WarehouseLocation())->setWarehouse($warehouse)->setCode('A-12')->setSortKey(10);
        $em->persist($bin);

        $lot = (new InventoryLot())->setProduct($product)->setCode('L-1')->setExpiry(new \DateTimeImmutable('2027-06-30'));
        $em->persist($lot);

        $em->flush();

        // Through the real switcher, so the opening balance is written the way the app writes it.
        $I->grabService(InventoryModeSwitcher::class)->toDimensional($product, 'cest@example.test');

        // The shipped reference rows, last — see this method's docblock for both the reason and the
        // ordering.
        $I->haveSeededReferenceData();

        return ['product' => $product, 'warehouse' => $warehouse, 'bin' => $bin, 'lot' => $lot];
    }

    /**
     * #782: an agreeing count wrote a zero-quantity movement and 500ed. `$found - $detail->
     * getQuantity()` mixes an int with getQuantity()'s decimal STRING return, which PHP's `-`
     * coerces to float — an agreeing count then produced 0.0, not 0, and `0.0 === 0` is false, so
     * the "nothing changed, skip this row" guard never matched. The row fell through to
     * MovementRequest::remove()/receive() with a quantity of zero, which is correctly refused as
     * not positive — just not the outcome an agreeing count should ever reach.
     */
    public function anAgreeingCountWritesNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedDimensionalStock($I, quantity: 47);
        $em = $I->grabService('doctrine.orm.entity_manager');

        // seedDimensionalStock()'s own opening balance lands unspecified (no bin) — see
        // InventoryModeSwitcher::toDimensional()'s own docblock. This test is about counting a
        // BIN's own rows, so it puts one there directly rather than through a second real movement.
        $detail = (new InventoryDetail())
            ->setProduct($seed['product'])
            ->setWarehouse($seed['warehouse'])
            ->setLocation($seed['bin'])
            ->setStatus(InventoryDetail::STATUS_AVAILABLE)
            ->setQuantity('47');
        $em->persist($detail);
        $em->flush();

        $I->amOnPage('/admin/bundles/inventory-depth/cycle-count?bin=' . $seed['bin']->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->sendFormPostRequest('/admin/bundles/inventory-depth/cycle-count', [
            '_token' => $I->csrfToken(),
            'bin_id' => (string) $seed['bin']->getId(),
            'counted' => [(string) $detail->getId() => '47'],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/bundles/inventory-depth/movements');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('Cycle count of ' . $seed['bin']->getCode());

        $em->clear();
        $reloaded = $em->getRepository(InventoryDetail::class)->find($detail->getId());
        $I->assertSame('47.0000', $reloaded->getQuantity(), 'the row the agreeing count named is untouched');
    }

    public function everyScreenRenders(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedDimensionalStock($I);

        foreach ([
            '/admin/bundles/inventory-depth',
            '/admin/bundles/inventory-depth/stock/location',
            '/admin/bundles/inventory-depth/low-stock',
            '/admin/bundles/inventory-depth/stock/product/' . $seed['product']->getId(),
            '/admin/bundles/inventory-depth/stock/serial',
            '/admin/bundles/inventory-depth/adjust',
            '/admin/bundles/inventory-depth/cycle-count',
            '/admin/bundles/inventory-depth/bins',
            '/admin/bundles/inventory-depth/lots',
            '/admin/bundles/inventory-depth/movements',
        ] as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
        }
    }

    /**
     * The adjustment screen picks the product by GET before it can offer anything else — a lot
     * belongs to exactly one product, so nothing lot-shaped can be filled until the product is
     * known, and since #585 which fields exist at all depends on that product's tracking policy.
     *
     * Without a product it shows only the picker; with one it shows the reason list; with a reason
     * it shows the movement form. The `has_from`/`has_to` checkboxes this test used to look for are
     * gone with the movement editor — see InventoryAdjustmentReasonsCest, which covers the shape
     * that replaced them.
     */
    public function theAdjustmentScreenAsksForTheProductBeforeAnythingElse(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedDimensionalStock($I);

        $I->amOnPage('/admin/bundles/inventory-depth/adjust');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('input[name="reason"]');

        $I->amOnPage('/admin/bundles/inventory-depth/adjust?product=' . $seed['product']->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="reason"]');
        $I->dontSeeElement('input[name="quantity"]');

        $I->amOnPage('/admin/bundles/inventory-depth/adjust?product=' . $seed['product']->getId() . '&reason=stock_found');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="quantity"]');

        // This product is on the default policy, which tracks nothing, so no batch is asked for —
        // that independence is the point of #573's flags and #585 respects it. Where a lot IS shown,
        // it carries its code AND its expiry, because the code alone is ambiguous: vendors reuse
        // batch codes across production runs with different dates.
        $I->dontSeeElement('select[name="lot_id"]');
        $I->amOnPage('/admin/bundles/inventory-depth/lots');
        $I->seeResponseCodeIsSuccessful();
        $I->see('2027-06-30');
    }

    /**
     * The reconciliation is the point of the stock-by-product screen: the invariant is the only
     * thing holding the two layers together, and a screen that hid the core number could not tell
     * anyone it had broken.
     */
    public function theProductScreenShowsTheTotalReconcilingToTheInventoryNumber(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedDimensionalStock($I, 47);

        $I->amOnPage('/admin/bundles/inventory-depth/stock/product/' . $seed['product']->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Reconciliation');

        // The opening balance carried 47 in via `quantity`, with every other bucket (received,
        // transfers, write-offs, quarantine, sold) at 0, so Held is 47 and the row agrees.
        //
        // Read out of the cells, not off the page (#627). see('47') was a substring match over the
        // whole document: proved by mutation — rendering `line.held + 1000` in the Held column left
        // this test green, because '1047' contains '47'. Every figure on the reconciliation, the one
        // thing holding the core total and the detail layer together, could have been wrong without
        // moving the suite.
        //
        // The section now carries the same `table-card company-detail-card` classes as the page's
        // other cards (Where It Is, Recent Activity), not the `panel` this used to select for —
        // brought in line so Reconciliation stopped being the only table on the screen with
        // different padding. Several sections now share those classes, so this finds the right one
        // by its own heading instead, the only thing still unique to it.
        $row = '//h2[text()="Reconciliation"]/ancestor::section[1]//table/tbody/tr[1]/';

        $I->assertSame(
            $seed['warehouse']->getName(),
            trim($I->grabTextFrom($row . 'td[1]')),
            'warehouse.name',
        );
        $I->assertSame(
            '47',
            trim($I->grabTextFrom($row . 'td[2]/strong')),
            'Held: quantity + received + transfer_in − transfer_out − write_off − quarantine − sold',
        );
        $I->assertSame(
            '47',
            trim($I->grabTextFrom($row . 'td[3]/strong')),
            'sum of inventory_stock_detail.quantity where status = available',
        );
        $I->assertSame(
            'yes',
            trim($I->grabTextFrom($row . 'td[4]')),
            'the two columns agree; a mismatch prints "NO — run app:inventory-depth:detail-check"',
        );
        $I->assertSame(
            '47',
            trim($I->grabTextFrom($row . 'td[5]')),
            'available to sell: 47 less the cart hold, sales hold, pending and approved claims, all 0 here',
        );
    }

    /**
     * The whole write path, through the real form: CSRF, the controller, the movement service, and
     * the core total recomputed in the same transaction.
     *
     * **This used to drive a bin move**, on the reasoning that both sides are `available` so the
     * number must not change — the cheapest regression test there is for somebody recomputing the
     * total from the wrong status set. A bin move is not an adjustment any more (#585): it is a scan,
     * it changes no bucket at all, and `/admin/bundles/warehouse-ops/scan` already does it. The
     * "both sides available means the number does not move" property is unchanged and is still
     * asserted by StockMovementServiceTest, which exercises it directly rather than through a form
     * that no longer offers it.
     *
     * What is driven here instead is a reason that DOES move the number, so the same stack is
     * exercised end to end and the assertion is about the figure rather than about its absence.
     */
    public function anAdjustmentFormSubmissionWritesThroughTheRealStack(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedDimensionalStock($I, 47);

        $I->amOnPage('/admin/bundles/inventory-depth/adjust?product=' . $seed['product']->getId() . '&reason=damaged');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        // No status, no movement type, no from/to sides. The reason carries all of it.
        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => 'damaged',
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '17',
            'note' => 'Crushed on the pick face',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $row = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $seed['product']->getId(),
            'warehouse' => $seed['warehouse']->getId(),
        ]);
        $I->assertInstanceOf(ProductInventory::class, $row);

        // `quantity` is the client's own imported figure and this layer never writes it (#564); the
        // write-off bucket is what carries the loss, and availability is what falls.
        $I->assertSame('47.0000', $row->getQuantity(), 'the imported figure is not this layer\'s to touch');
        $I->assertSame('17.0000', $row->getWriteOffQuantity(), 'the write-off bucket carries it');
        $I->assertSame('30.0000', $row->getAvailableQuantity(), 'and thirty are still sellable');

        $I->assertSame(
            17,
            (int) $em->getConnection()->fetchOne(
                'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
                [$seed['product']->getId(), InventoryDetail::STATUS_DAMAGED],
            ),
            'and the breakdown behind the number moved with it',
        );

        $I->amOnPage('/admin/bundles/inventory-depth/movements');
        $I->see('Crushed on the pick face');
    }

    /** Standing requirement: a copied URL reproduces the exact result. */
    public function everyListFilterLivesInTheUrl(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedDimensionalStock($I);

        $I->amOnPage('/admin/bundles/inventory-depth/stock/location?filters%5Bbin%5D=A-12&limit=20&sort=quantity&dir=desc');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('filters[bin]', 'A-12');

        $I->amOnPage('/admin/bundles/inventory-depth/bins?filters%5Bcode%5D=A-12&filters%5Bstatus%5D=Active');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('filters[code]', 'A-12');

        // The expiring-soon view is the lot list with a window, not a separate screen.
        $I->amOnPage('/admin/bundles/inventory-depth/lots?filters%5BexpiringWithin%5D=90');
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/bundles/inventory-depth/movements?filters%5Btype%5D=adjustment&filters%5Bfrom%5D=2020-01-01&filters%5Bto%5D=2099-12-31');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('filters[to]', '2099-12-31');
    }

    /** The opening balance appears in the ledger with its reason and actor, not as an unexplained row. */
    public function theOpeningBalanceIsVisibleInTheMovementLedger(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seedDimensionalStock($I);

        $I->amOnPage('/admin/bundles/inventory-depth/movements');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Opening balance on switching to dimensional inventory');
        $I->see('cest@example.test');
    }

    /** Serial lookup answers "where is it now" and "what happened to it". */
    public function serialLookupFindsASerialAndItsHistory(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedDimensionalStock($I);

        $I->grabService(StockMovementService::class)->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'cest-serial-' . uniqid(), 'Received one serialized unit')
                ->receive(
                    $seed['product'],
                    new DetailKey($seed['warehouse'], $seed['bin'], $seed['lot'], 'SN-ABC-123', InventoryDetail::STATUS_AVAILABLE),
                    1,
                )
        );

        $I->amOnPage('/admin/bundles/inventory-depth/stock/serial?serial=SN-ABC-123');
        $I->seeResponseCodeIsSuccessful();
        $I->see('A-12');
        $I->see('Received one serialized unit');
    }

    /**
     * The core grid must offer a link, not an editable box, once a product is dimensional — and the
     * update endpoint must refuse a hand-made POST for the same product.
     */
    public function theCoreInventoryGridStopsOfferingAnEditableBox(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedDimensionalStock($I);

        $productId = $seed['product']->getId();
        $warehouseId = $seed['warehouse']->getId();

        $I->amOnPage('/admin/inventory?filters%5Bid%5D=' . $productId);
        $I->seeResponseCodeIsSuccessful();

        // No EDITABLE box — that is the point. The element with this id is still present but hidden,
        // because #548's backorder-settings JS reads it to echo the current quantity back with a
        // settings change; with nothing there it falls back to '0' and would ask the server to zero
        // a quantity nobody typed. So the assertion is about the editable class, not the id.
        $I->dontSeeElement('input.js-inventory-input#inv_' . $productId . '_' . $warehouseId);
        $I->seeElement('input[type="hidden"]#inv_' . $productId . '_' . $warehouseId);
        $I->assertSame('47', $I->grabAttributeFrom('input[type="hidden"]#inv_' . $productId . '_' . $warehouseId, 'value'),
            'the hidden input must carry the real quantity, so a settings change echoes back the truth');
        $I->seeElement('a[href*="/admin/bundles/inventory-depth/stock/product/' . $productId . '"]');

        // Off the rendered page, the way the browser gets it: the token manager reads the session
        // through the request stack, which is empty once a request has finished.
        $token = (string) $I->grabAttributeFrom('table.wide-price-table', 'data-inventory-update-token');

        $I->sendAjaxPostRequest('/admin/inventory/update', [
            'product_id' => (string) $productId,
            'warehouse_id' => (string) $warehouseId,
            'quantity' => '9999',
            '_token' => $token,
        ]);
        $I->seeResponseCodeIs(409);

        // The number must be exactly what the opening balance left it at.
        $em = $I->grabService('doctrine.orm.entity_manager');
        $row = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $seed['product'],
            'warehouse' => $seed['warehouse'],
        ]);
        $I->assertInstanceOf(ProductInventory::class, $row);
        $em->refresh($row);
        $I->assertSame('47.0000', $row->getQuantity(), 'the refused write must have left the number alone');
    }

    /**
     * Backorder settings (#548) stay editable on a dimensional product.
     *
     * The two features meet on one endpoint: `admin_inventory_update` carries both the quantity and
     * the four backorder settings, and the settings JS echoes the current quantity back with every
     * settings change. Refusing every POST that names a dimensional product would make the settings
     * uneditable for exactly the products someone had bothered to set up properly — so the refusal
     * is scoped to a POST that actually asks to CHANGE the quantity.
     */
    public function backorderSettingsRemainEditableOnADimensionalProduct(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedDimensionalStock($I, 47);

        $I->amOnPage('/admin/inventory?filters%5Bid%5D=' . $seed['product']->getId());
        $token = (string) $I->grabAttributeFrom('table.wide-price-table', 'data-inventory-update-token');

        // Exactly what postBackorderSetting() sends: the current quantity, echoed back unchanged.
        $I->sendAjaxPostRequest('/admin/inventory/update', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '47',
            'allow_backorder' => '1',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $em = $I->grabService('doctrine.orm.entity_manager');
        $row = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $seed['product'],
            'warehouse' => $seed['warehouse'],
        ]);
        $I->assertInstanceOf(ProductInventory::class, $row);
        $em->refresh($row);

        $I->assertTrue($row->isAllowBackorder(), 'the setting must have applied');
        $I->assertSame('47.0000', $row->getQuantity(), 'and the quantity must be untouched');
    }

    /** A simple product — every product until someone opts one in — is completely unaffected. */
    public function aSimpleProductKeepsItsEditableBox(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $region = (new FulfillmentRegion())->setName('Simple Region ' . uniqid());
        $em->persist($region);
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())->setSku('SIMPLE-' . strtoupper(substr(uniqid(), -6)))->setName('Simple Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $em->persist($product);
        $em->persist((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity(12));
        $em->flush();

        $I->amOnPage('/admin/inventory?filters%5Bid%5D=' . $product->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input#inv_' . $product->getId() . '_' . $warehouse->getId());
    }

    /** Inactive reads as absent, not forbidden. */
    /**
     * Points one reason row at a different pair of statuses.
     *
     * **Re-fetched through a freshly grabbed EntityManager every time, and that is not
     * defensiveness.** Codeception reboots the kernel between requests, and the reboot resets
     * Doctrine — so an entity loaded before a request is DETACHED after it, and `flush()` on it
     * writes nothing at all. Holding one `$reason` across the loop below made every iteration after
     * the first a no-op: the row stayed on the first configuration, three of the four POSTs were
     * refused for a status nobody was testing, and the test passed while asserting nothing. It was
     * caught by the one assertion in it that expects something to SUCCEED, which is the argument for
     * having one.
     */
    private function configureReason(FunctionalTester $I, string $code, ?string $from, ?string $to): void
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $reason = $em->getRepository(InventoryAdjustmentReason::class)->findOneBy(['code' => $code]);
        $I->assertInstanceOf(InventoryAdjustmentReason::class, $reason);

        $reason->setFromStatus($from)->setToStatus($to);
        $em->flush();

        $I->assertSame(
            [$from, $to],
            [
                $em->getConnection()->fetchOne('SELECT from_status FROM inventory_adjustment_reason WHERE code = ?', [$code]),
                $em->getConnection()->fetchOne('SELECT to_status FROM inventory_adjustment_reason WHERE code = ?', [$code]),
            ],
            sprintf('the reason row has to actually be reconfigured, or the assertion that follows is about nothing (%s)', $code),
        );
    }

    /**
     * The adjustment screen may not sell or dispatch stock (#581), and #585 did not give that power
     * back by a different door.
     *
     * `sold` and `in_transit` each belong to a document — an invoice, a transfer order — and an
     * adjustment that produced one of them created the effect without the paperwork. `sold` was the
     * damaging one: nothing held the units, so availability carried on offering stock that had left
     * the building.
     *
     * **The door this test drives has changed.** There is no status dropdown to bypass any more; the
     * statuses come from a configurable reason ROW, which is the new place somebody could put one.
     * So the test bends a real reason to write `sold` and submits it — a thing an admin can
     * genuinely do, and the reason the refusal lives in the controller rather than in the seed data.
     *
     * Both sides are checked, for the reason #581 gives: writing `sold` invents a sale nobody
     * billed, and reading FROM `sold` unbills one with no credit note behind it.
     */
    public function anAdjustmentCannotSellOrDispatchStock(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedDimensionalStock($I, 40);

        $I->amOnPage('/admin/bundles/inventory-depth/adjust?product=' . $seed['product']->getId() . '&reason=damaged');
        $I->seeResponseCodeIsSuccessful();

        // The operator never sees a status at all, which is the shape #585 gave this screen.
        $I->dontSeeElement('select[name="to_status"]');
        $I->dontSeeElement('select[name="from_status"]');

        $em = $I->grabService('doctrine.orm.entity_manager');

        foreach (['sold', 'in_transit'] as $forbidden) {
            foreach ([
                // The to-side: a reason configured to WRITE a document-backed status.
                [InventoryDetail::STATUS_AVAILABLE, $forbidden],
                // The from-side: one configured to READ one. Checked separately because it is a
                // different clause with a different failure, and the two drifted apart once already.
                [$forbidden, InventoryDetail::STATUS_AVAILABLE],
            ] as [$from, $to]) {
                $this->configureReason($I, 'damaged', $from, $to);

                // A fresh token per submission. Symfony re-randomises it per render and
                // AbstractInventoryDepthController::operationKey() digests it into the group's
                // idempotency key, so a reused token can be swallowed as a duplicate — which would
                // make every refusal below pass whether or not the guard existed.
                $I->amOnPage('/admin/bundles/inventory-depth/adjust?product=' . $seed['product']->getId() . '&reason=damaged');
                $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

                $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
                    '_token' => $token,
                    'product_id' => (string) $seed['product']->getId(),
                    'reason' => 'damaged',
                    'warehouse_id' => (string) $seed['warehouse']->getId(),
                    'quantity' => '5',
                ]);
            }

            $I->assertSame(
                0,
                (int) $em->getConnection()->fetchOne(
                    'SELECT COUNT(*) FROM inventory_detail WHERE product_id = ? AND status = ?',
                    [$seed['product']->getId(), $forbidden],
                ),
                sprintf('an adjustment must not be able to produce a "%s" row, however its reason is configured', $forbidden),
            );
        }

        $I->assertSame(
            40,
            (int) $em->getConnection()->fetchOne(
                'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
                [$seed['product']->getId(), InventoryDetail::STATUS_AVAILABLE],
            ),
            'four refused adjustments — two statuses by two sides — moved nothing',
        );

        // And a permitted one goes through, so this is a rule about WHICH status and not a broken
        // form. It is also what makes the four refusals above mean something: a screen that had
        // simply stopped working would have passed every one of them.
        $this->configureReason($I, 'damaged', InventoryDetail::STATUS_AVAILABLE, InventoryDetail::STATUS_DAMAGED);

        $I->amOnPage('/admin/bundles/inventory-depth/adjust?product=' . $seed['product']->getId() . '&reason=damaged');
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => 'damaged',
            'warehouse_id' => (string) $seed['warehouse']->getId(),
            'quantity' => '5',
        ]);

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();
        $row = $em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $seed['product']->getId(),
            'warehouse' => $seed['warehouse']->getId(),
        ]);
        $I->assertInstanceOf(ProductInventory::class, $row);
        $I->assertSame('5.0000', $row->getWriteOffQuantity(), 'five units damaged');
        $I->assertSame('35.0000', $row->getAvailableQuantity(), 'and no longer sellable');
    }

    /**
     * And it may not move stock back OUT of a document's status either (#581).
     *
     * The to-side rule stops an adjustment inventing a sale. This is the same rule run backwards:
     * if a `sold` row exists because an invoice billed those units, an admin moving that row to
     * `available` from this screen has unbilled them with no credit note behind it. The invoice
     * still claims the units; the detail rows no longer show them; availability quietly grows by
     * the difference.
     *
     * Kept apart from the test above because it needs something that test deliberately never
     * creates — a real `sold` row — and because it drives the OTHER door #585 opened: naming the
     * sold detail row BY ID as the source of a reason whose source the operator picks. A guard on
     * the reason's configured from-status alone passes every assertion above while leaving that
     * hole wide open.
     *
     * The `sold` row is written through StockMovementService, the way an invoice's dispatch would
     * write it, rather than by hand: a hand-built row could carry a bin that a real `sold` row never
     * has (DetailKey drops the location for terminal statuses), and the POST would then fail to
     * match it for a reason that has nothing to do with the guard.
     */
    public function anAdjustmentCannotMoveStockOutOfASoldRowEither(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $seed = $this->seedDimensionalStock($I, 40);

        $available = new DetailKey($seed['warehouse'], null, null, null, InventoryDetail::STATUS_AVAILABLE);
        $I->grabService(StockMovementService::class)->apply(
            MovementRequest::of(
                InventoryMovementGroup::TYPE_SHIP,
                'cest-sold-' . uniqid(),
                'Shipped against an invoice',
                'cest@example.test',
                'INV-CEST-1',
            )->move($seed['product'], $available, $available->forStatus(InventoryDetail::STATUS_SOLD), 10)
        );

        $em = $I->grabService('doctrine.orm.entity_manager');
        $em->clear();

        $soldRowId = (int) $em->getConnection()->fetchOne(
            'SELECT id FROM inventory_detail WHERE product_id = ? AND status = ?',
            [$seed['product']->getId(), InventoryDetail::STATUS_SOLD],
        );
        $I->assertGreaterThan(0, $soldRowId, 'the sale is set up: there is a sold row for the screen to try to unpick');

        // "Release from hold" names its source row by id, which is the shape a hand-built POST can
        // point anywhere. Pointed at the sold row it must be refused: the row is not quarantine
        // stock, and only the invoice that billed those units can give them back.
        $I->amOnPage('/admin/bundles/inventory-depth/adjust?product=' . $seed['product']->getId() . '&reason=release_hold');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/bundles/inventory-depth/adjust', [
            '_token' => $token,
            'product_id' => (string) $seed['product']->getId(),
            'reason' => 'release_hold',
            'source_detail_id' => (string) $soldRowId,
            'quantity' => '10',
        ]);

        $em->clear();

        $I->assertSame(
            10,
            (int) $em->getConnection()->fetchOne(
                'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
                [$seed['product']->getId(), InventoryDetail::STATUS_SOLD],
            ),
            "the sold row is untouched — unbilling ten units is the invoice's to do, not this screen's",
        );

        // Asserted on the detail rows rather than on ProductInventory::getAvailableQuantity(),
        // because that figure is `quantity + received` less every bucket and every hold, and a
        // shipment moves more than one of those terms at once. A number assembled from six inputs
        // cannot say which one a regression touched.
        $I->assertSame(
            30,
            (int) $em->getConnection()->fetchOne(
                'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
                [$seed['product']->getId(), InventoryDetail::STATUS_AVAILABLE],
            ),
            'and nothing came back into the available rows — thirty before the refused POST, thirty after',
        );
    }

    public function turningTheBundleInactiveHidesItsScreens(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        // Find-or-update rather than a blind insert: `source` is unique, and under the
        // bundles-off environment (#562) a row for this bundle already exists, so inserting a
        // second one is a UNIQUE violation rather than a test failure.
        $em = $I->grabService('doctrine.orm.entity_manager');
        $status = $em->getRepository(\App\Entity\BundleStatus::class)->findOneBy(['source' => 'InventoryDepthBundle']);
        if (!$status instanceof \App\Entity\BundleStatus) {
            $status = (new \App\Entity\BundleStatus())->setSource('InventoryDepthBundle');
            $em->persist($status);
        }
        $status->setStatus(\App\Entity\BundleStatus::STATUS_INACTIVE);
        $em->flush();

        $I->amOnPage('/admin/bundles/inventory-depth');
        $I->seeResponseCodeIs(404);
    }
}
