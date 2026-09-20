<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\Product\ProductPicker;
use App\Service\WarehouseFulfillmentRegionService;
use InventoryDepthBundle\Inventory\InventoryModeSwitcher;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Inventory Depth asks for a product by NAME now, not by database id (#8) — conducted per #624,
 * through the real screens, with plain form posts.
 *
 * Two screens still rendered `Product ID <input type="number" name="product_id">`: the lots screen
 * and the reorder-level screen. Both are create forms, not filters — the filters beside them have
 * always taken a SKU or a name — so what the box asked for was the one thing nobody knows, on the
 * one control where it mattered.
 *
 * ## What these tests are pinned to, and why in this order
 *
 * Every absence here is asserted only AFTER a positive assertion on the same page (#627). A bare
 * `dontSeeElement('input[type=number]')` passes just as happily when the selector has a typo, when
 * the screen 500s into an error template and when the route was renamed — so the select is proved
 * present first, which proves both that the page rendered and that the selector language is right.
 *
 * No assertion here matches a bare number. These screens are full of quantities and levels, and
 * `see('50')` matches '1050' (#627); every row assertion names a SKU or a lot code, and every
 * figure is read out of the database as a column instead.
 *
 * ## Why the field these screens include is core's and not ProcurementBundle's
 *
 * The picker was written in ProcurementBundle and moved to core for this change. Modules are
 * discovered by `glob()` in `config/bundles.php` and switched off through `bundle_status`, so
 * including `@Procurement/_product_field.html.twig` here would have made an inventory screen die
 * the day somebody deleted the purchasing module — the Twig namespace and the route the partial
 * resolves with `path()` both leave with the folder. `theSearchTheseScreensPointAtIsCoresOwn()`
 * pins that: the rendered `data-search-url` is core's endpoint, not procurement's.
 */
final class InventoryDepthProductPickerCest
{
    private const LOTS = '/admin/bundles/inventory-depth/lots';
    private const LOW_STOCK = '/admin/bundles/inventory-depth/low-stock';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('depth-picker-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * One warehouse and two dimensional products with deliberately unlike SKUs and names.
     *
     * TWO products, always: every assertion about what a screen shows is worth something only
     * beside a second product that must NOT appear. Both are made dimensional through the bundle's
     * own switcher because the lots form refuses a simple-inventory product outright, and a test
     * that seeded the mode by hand would be asserting against a state the application will not
     * produce.
     *
     * @return array{warehouseId: int, alphaId: int, betaId: int, alphaSku: string, betaSku: string, tag: string}
     */
    private function seed(FunctionalTester $I): array
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $tag = strtoupper(substr(uniqid(), -6));

        $region = (new FulfillmentRegion())->setName('Depth Picker Region ' . $tag);
        $em->persist($region);
        $em->flush();
        $warehouse = $I->grabService(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $alpha = (new ProductCore())
            ->setSku('DEPTHPICK-ZENITH-' . $tag)
            ->setName('Zenith Depth Widget ' . $tag)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $alpha->activate();
        $beta = (new ProductCore())
            ->setSku('DEPTHPICK-BOULDER-' . $tag)
            ->setName('Boulder Depth Gadget ' . $tag)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $beta->activate();
        $em->persist($alpha);
        $em->persist($beta);
        $em->flush();

        $em->persist((new ProductInventory())->setProduct($alpha)->setWarehouse($warehouse)->setQuantity(4));
        $em->persist((new ProductInventory())->setProduct($beta)->setWarehouse($warehouse)->setQuantity(4));
        $em->flush();

        $switcher = $I->grabService(InventoryModeSwitcher::class);
        $switcher->toDimensional($alpha, 'depth-picker@example.test');
        $switcher->toDimensional($beta, 'depth-picker@example.test');
        $em->flush();

        return [
            'warehouseId' => (int) $warehouse->getId(),
            'alphaId' => (int) $alpha->getId(),
            'betaId' => (int) $beta->getId(),
            'alphaSku' => $alpha->getSku(),
            'betaSku' => $beta->getSku(),
            'tag' => $tag,
        ];
    }

    /** The CSRF token of the create form on the page currently loaded. */
    private function tokenOf(FunctionalTester $I, string $formSelector): string
    {
        return (string) $I->grabAttributeFrom($formSelector . ' input[name="_token"]', 'value');
    }

    /** `inventory_lot` as the database holds it, not as the response described it. */
    private function lotRow(FunctionalTester $I, string $code): array|false
    {
        return $I->grabService('doctrine.orm.entity_manager')->getConnection()->fetchAssociative(
            'SELECT id, product_id, code FROM inventory_lot WHERE code = ?',
            [$code],
        );
    }

    /** `inventory_reorder_rule` for one (product, warehouse) pair, re-read. */
    private function ruleRow(FunctionalTester $I, int $productId, int $warehouseId): array|false
    {
        return $I->grabService('doctrine.orm.entity_manager')->getConnection()->fetchAssociative(
            'SELECT id, product_id, warehouse_id, reorder_point FROM inventory_reorder_rule WHERE product_id = ? AND warehouse_id = ?',
            [$productId, $warehouseId],
        );
    }

    // ------------------------------------------------------------------ the field itself

    /**
     * Both screens offer a real `<select>` of products, and neither offers a bare number box.
     *
     * The positive assertions come first and name a product this test created, so the absence
     * below them is known to be an absence from a page that rendered.
     */
    public function neitherScreenAsksForADatabaseIdAnyMore(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $context = $this->seed($I);

        foreach ([self::LOTS, self::LOW_STOCK] as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            // Present: a select under the name the save reads, carrying both products by SKU.
            $I->seeElement('select[name="product_id"]');
            $I->seeElement(sprintf('select[name="product_id"] option[value="%d"]', $context['alphaId']));
            $I->seeElement(sprintf('select[name="product_id"] option[value="%d"]', $context['betaId']));
            $I->see($context['alphaSku'], 'select[name="product_id"]');

            // Absent: the number box, and the label that told an admin to type an id into it.
            $I->dontSeeElement('input[type="number"][name="product_id"]');
            $I->dontSeeElement('input[name="product_id"]');
        }
    }

    /**
     * Under the inline limit the select carries the catalog and there is no fallback box, because a
     * real select needs none — a browser makes it type-to-searchable on its own.
     */
    public function underTheInlineLimitTheSelectCarriesTheCatalogAndNeedsNoFallback(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->seed($I);

        $I->assertFalse(
            $I->grabService(ProductPicker::class)->isRemote(),
            'this test is about the under-the-limit tier, so the test catalog must be under it',
        );

        foreach ([self::LOTS, self::LOW_STOCK] as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            $I->seeElement('select[name="product_id"]');
            $I->dontSeeElement('select[name="product_id"][data-search-url]');
            // Asserted against the source, not the DOM, because that is how
            // pastTheInlineLimitTheNoScriptBoxRendersAndSaves() asserts its PRESENCE: <noscript>
            // content is parsed differently depending on whether the parser has scripting on, and
            // two opposite assertions through one mechanism cannot both pass by accident.
            $I->dontSeeInSource('name="product_id_manual"');
        }
    }

    // ------------------------------------------------------------------ what actually saves

    /**
     * A lot is created against the product the select named — and the other product's lot is
     * exactly as it was.
     *
     * Both lots are raised through the real form, so the rows being read back are rows the
     * application wrote, and both are then re-read out of `inventory_lot` rather than trusted to
     * the flash message that announced them.
     */
    public function aLotIsCreatedAgainstTheProductTheSelectNames(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $context = $this->seed($I);
        $tag = $context['tag'];

        foreach ([['alphaId', 'ZENITHLOT-' . $tag], ['betaId', 'BOULDERLOT-' . $tag]] as [$key, $code]) {
            $I->amOnPage(self::LOTS);
            $I->sendFormPostRequest('/admin/bundles/inventory-depth/lots/save', [
                '_token' => $this->tokenOf($I, '#lot-form form'),
                'id' => '0',
                'product_id' => (string) $context[$key],
                'code' => $code,
                'expiry' => '2027-06-30',
                'received_at' => '',
                'source' => 'Picker conducted test',
            ]);
            $I->seeResponseCodeIsSuccessful();
        }

        $alphaLot = $this->lotRow($I, 'ZENITHLOT-' . $tag);
        $betaLot = $this->lotRow($I, 'BOULDERLOT-' . $tag);

        $I->assertNotFalse($alphaLot, 'the lot the select named was created');
        $I->assertNotFalse($betaLot, 'the second lot was created');
        $I->assertSame($context['alphaId'], (int) $alphaLot['product_id'], 'inventory_lot.product_id is the product the select named');
        // The row that must NOT have moved: the second lot still hangs off its own product.
        $I->assertSame($context['betaId'], (int) $betaLot['product_id'], 'the other product\'s lot was reassigned by a save that had nothing to do with it');

        // And the list filtered to one product shows that product's lot and NOT the other's.
        $I->amOnPage(self::LOTS . '?filters[product]=' . urlencode($context['alphaSku']));
        $I->seeResponseCodeIsSuccessful();
        $I->see('ZENITHLOT-' . $tag, '.table-card table');       // positive control: the page rendered the grid
        $I->dontSee('BOULDERLOT-' . $tag, '.table-card table');  // and the second product's lot is absent
    }

    /**
     * A reorder level is set against the product the select named, and the other pair is untouched.
     */
    public function aReorderLevelIsSetAgainstTheProductTheSelectNames(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $context = $this->seed($I);

        foreach ([['alphaId', '5'], ['betaId', '7']] as [$key, $point]) {
            $I->amOnPage(self::LOW_STOCK);
            $I->sendFormPostRequest('/admin/bundles/inventory-depth/low-stock/save', [
                '_token' => $this->tokenOf($I, '#reorder-form form'),
                'id' => '0',
                'product_id' => (string) $context[$key],
                'warehouse_id' => (string) $context['warehouseId'],
                'reorder_point' => $point,
                'reorder_quantity' => '',
                'safety_stock_quantity' => '',
            ]);
            $I->seeResponseCodeIsSuccessful();
        }

        $alphaRule = $this->ruleRow($I, $context['alphaId'], $context['warehouseId']);
        $betaRule = $this->ruleRow($I, $context['betaId'], $context['warehouseId']);

        $I->assertNotFalse($alphaRule, 'a level was set for the product the select named');
        $I->assertNotFalse($betaRule, 'a level was set for the second product');
        $I->assertSame(5, (int) $alphaRule['reorder_point'], 'inventory_reorder_rule.reorder_point is the level posted for that product');
        // The row that must NOT have moved.
        $I->assertSame(7, (int) $betaRule['reorder_point'], 'the second pair\'s level changed under a save for another product');

        // Filtered to one product, the grid shows that product and not the other.
        $I->amOnPage(self::LOW_STOCK . '?filters[show]=all&filters[product]=' . urlencode($context['alphaSku']));
        $I->seeResponseCodeIsSuccessful();
        $I->see($context['alphaSku'], '.table-card table');
        $I->dontSee($context['betaSku'], '.table-card table');
    }

    // ------------------------------------------------------------------ the no-JS path

    /**
     * The `<noscript>` id box names the product when the select posts empty — on both screens.
     *
     * This is the tier that would quietly rot: the box renders only past the inline limit, so
     * nothing else on these screens ever posts it. It carries a different name from the select
     * deliberately, so that neither depends on which of the two PHP parses last, and
     * `ProductPicker::idFromPostedRow()` is the single place that decides. An EMPTY select posted
     * alongside a filled box is exactly what a scripting-off browser sends.
     *
     * Each half also carries the row that must not change: a lot and a level created the ordinary
     * way, which the fallback post must leave exactly where they were.
     */
    public function theNoJsIdBoxNamesTheProductWhenTheSelectPostsEmpty(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $context = $this->seed($I);
        $tag = $context['tag'];

        // --- lots: one lot through the select first, then one through the no-JS box.
        $I->amOnPage(self::LOTS);
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/lots/save', [
            '_token' => $this->tokenOf($I, '#lot-form form'),
            'id' => '0',
            'product_id' => (string) $context['betaId'],
            'code' => 'SELECTLOT-' . $tag,
            'expiry' => '2027-01-31',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage(self::LOTS);
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/lots/save', [
            '_token' => $this->tokenOf($I, '#lot-form form'),
            'id' => '0',
            // Scripting off, past the limit: the select had nothing to offer and posts empty.
            'product_id' => '',
            'product_id_manual' => (string) $context['alphaId'],
            'code' => 'NOJSLOT-' . $tag,
            'expiry' => '2027-02-28',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $noJsLot = $this->lotRow($I, 'NOJSLOT-' . $tag);
        $selectLot = $this->lotRow($I, 'SELECTLOT-' . $tag);
        $I->assertNotFalse($noJsLot, 'the no-JS id box created a lot');
        $I->assertSame($context['alphaId'], (int) $noJsLot['product_id'], 'the no-JS id box named the lot\'s product');
        $I->assertNotFalse($selectLot, 'the lot created through the select is still there');
        $I->assertSame($context['betaId'], (int) $selectLot['product_id'], 'the lot that used the select is untouched by the fallback post');

        // --- reorder levels: the same two halves.
        $I->amOnPage(self::LOW_STOCK);
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/low-stock/save', [
            '_token' => $this->tokenOf($I, '#reorder-form form'),
            'id' => '0',
            'product_id' => (string) $context['betaId'],
            'warehouse_id' => (string) $context['warehouseId'],
            'reorder_point' => '3',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage(self::LOW_STOCK);
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/low-stock/save', [
            '_token' => $this->tokenOf($I, '#reorder-form form'),
            'id' => '0',
            'product_id' => '',
            'product_id_manual' => (string) $context['alphaId'],
            'warehouse_id' => (string) $context['warehouseId'],
            'reorder_point' => '8',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $noJsRule = $this->ruleRow($I, $context['alphaId'], $context['warehouseId']);
        $selectRule = $this->ruleRow($I, $context['betaId'], $context['warehouseId']);
        $I->assertNotFalse($noJsRule, 'the no-JS id box set a level');
        $I->assertSame(8, (int) $noJsRule['reorder_point'], 'the level landed on the pair the no-JS box named');
        $I->assertNotFalse($selectRule, 'the level set through the select is still there');
        $I->assertSame(3, (int) $selectRule['reorder_point'], 'the pair set through the select was changed by the fallback post');
    }

    /**
     * Past the inline limit both screens really do render the `<noscript>` box — and it saves.
     *
     * The only test in the suite that crosses `ProductPicker::INLINE_LIMIT`, and the one that makes
     * the tier above a fact rather than an intention: under the limit the box is not in the markup
     * at all, so every other assertion about it is about a control nobody has proved renders.
     *
     * It also pins the seeded select: past the limit it must carry ONLY what the screen already
     * names, so a second product must not be among its options.
     */
    public function pastTheInlineLimitTheNoScriptBoxRendersAndSaves(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $context = $this->seed($I);
        $em = $I->grabService('doctrine.orm.entity_manager');

        // Enough filler to cross the limit, whatever the suite happened to leave behind.
        $existing = (int) $em->getRepository(ProductCore::class)->count(['deleted' => false]);
        for ($i = $existing; $i <= ProductPicker::INLINE_LIMIT; ++$i) {
            $filler = (new ProductCore())
                ->setSku('DEPTHFILL-' . $context['tag'] . '-' . $i)
                ->setName('Depth Filler ' . $i)
                ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
            $filler->activate();
            $em->persist($filler);
        }
        $em->flush();

        $I->assertTrue(
            $I->grabService(ProductPicker::class)->isRemote(),
            'the filler did not cross the inline limit, so this test is not exercising the remote tier',
        );

        foreach ([self::LOTS, self::LOW_STOCK] as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();

            // Present: the tier-2 select, wired to a search endpoint.
            $I->seeElement('select[name="product_id"][data-search-url]');
            // Absent: the catalog. The select is seeded with what the screen names, which here is
            // nothing — a create form names no product yet.
            $I->dontSeeElement(sprintf('select[name="product_id"] option[value="%d"]', $context['alphaId']));
            // Present: tier 3, the no-JS way in, under its own name.
            $I->seeInSource('name="product_id_manual"');
        }

        // And posting it is honoured, which is the whole point of the fallback.
        $I->amOnPage(self::LOTS);
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/lots/save', [
            '_token' => $this->tokenOf($I, '#lot-form form'),
            'id' => '0',
            'product_id' => '',
            'product_id_manual' => (string) $context['betaId'],
            'code' => 'REMOTELOT-' . $context['tag'],
            'expiry' => '2027-03-31',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $lot = $this->lotRow($I, 'REMOTELOT-' . $context['tag']);
        $I->assertNotFalse($lot, 'the no-JS box saved past the inline limit');
        $I->assertSame($context['betaId'], (int) $lot['product_id'], 'the lot hangs off the product the no-JS box named');
        $I->assertNotSame($context['alphaId'], (int) $lot['product_id'], 'the lot must not have landed on the other product');
    }

    // ------------------------------------------------------------------ independence from procurement

    /**
     * The endpoint these screens search against is CORE's, not ProcurementBundle's.
     *
     * This is the assertion the placement decision rests on. Modules are discovered by `glob()` and
     * can be deleted outright, taking their routes with them, so an inventory screen pointing at
     * `admin_bundle_procurement_product_search` would be a 500 on a page that has nothing to do
     * with purchasing — the same failure `ReorderController::purchaseOrderRoute()` already guards a
     * mere LINK against.
     */
    public function theSearchTheseScreensPointAtIsCoresOwn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $context = $this->seed($I);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $existing = (int) $em->getRepository(ProductCore::class)->count(['deleted' => false]);
        for ($i = $existing; $i <= ProductPicker::INLINE_LIMIT; ++$i) {
            $filler = (new ProductCore())
                ->setSku('DEPTHURL-' . $context['tag'] . '-' . $i)
                ->setName('Depth Url Filler ' . $i)
                ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
            $filler->activate();
            $em->persist($filler);
        }
        $em->flush();

        foreach ([self::LOTS, self::LOW_STOCK] as $url) {
            $I->amOnPage($url);
            $I->seeResponseCodeIsSuccessful();
            $I->seeElement('select[name="product_id"][data-search-url]');

            $searchUrl = (string) $I->grabAttributeFrom('select[name="product_id"]', 'data-search-url');
            $I->assertStringStartsWith('/admin/products/picker-search', $searchUrl, $url . ' searches somewhere unexpected');
            $I->assertStringNotContainsString(
                'procurement',
                $searchUrl,
                $url . ' reaches into ProcurementBundle, which can be switched off or deleted from under it',
            );
        }

        // The endpoint itself: it finds the product asked for and not the other one.
        $I->amOnPage('/admin/products/picker-search?q=' . urlencode('Zenith Depth Widget ' . $context['tag']));
        $I->seeResponseCodeIs(200);
        $payload = json_decode($I->grabPageSource(), true);
        $I->assertIsArray($payload);
        $skus = array_column($payload['products'] ?? [], 'sku');
        $I->assertContains($context['alphaSku'], $skus, 'the product searched for must come back');
        $I->assertNotContains($context['betaSku'], $skus, 'a product that does not match leaked into the results');

        // And a term under the floor is answered with nothing rather than a slice of the catalog.
        $I->amOnPage('/admin/products/picker-search?q=z');
        $I->seeResponseCodeIs(200);
        $short = json_decode($I->grabPageSource(), true);
        $I->assertSame([], $short['products'] ?? null, 'a one-character term must match nothing');
    }

    // ------------------------------------------------------------------ the refusal

    /**
     * Posting no product at all is refused by name, and writes nothing.
     *
     * The field is marked required, but native validation is a browser feature and these screens
     * are required to work without one — so the server states the rule too. It used to answer a
     * 404 page, which for a form somebody can simply fix is the wrong answer.
     */
    public function aSaveThatNamesNoProductIsRefusedRatherThanGuessed(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $context = $this->seed($I);
        $em = $I->grabService('doctrine.orm.entity_manager');

        $before = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_lot');

        $I->amOnPage(self::LOTS);
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/lots/save', [
            '_token' => $this->tokenOf($I, '#lot-form form'),
            'id' => '0',
            'product_id' => '',
            'code' => 'NOPRODUCT-' . $context['tag'],
            'expiry' => '2027-04-30',
        ]);
        $I->seeResponseCodeIsSuccessful();

        // The positive control: the screen said why, which proves this landed in the refusal and not
        // in some other failure that also happens to write nothing.
        $I->see('A lot belongs to a product', '.flash.flash-error');
        $I->assertFalse($this->lotRow($I, 'NOPRODUCT-' . $context['tag']), 'a lot was created with no product named');
        $I->assertSame(
            $before,
            (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_lot'),
            'the refused save still wrote a row',
        );

        $I->amOnPage(self::LOW_STOCK);
        $I->sendFormPostRequest('/admin/bundles/inventory-depth/low-stock/save', [
            '_token' => $this->tokenOf($I, '#reorder-form form'),
            'id' => '0',
            'product_id' => '',
            'warehouse_id' => (string) $context['warehouseId'],
            'reorder_point' => '6',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->see('A reorder level is set for a product', '.flash.flash-error');
        $I->assertFalse(
            $this->ruleRow($I, $context['alphaId'], $context['warehouseId']),
            'a level was set for a product nobody named',
        );
        $I->assertFalse(
            $this->ruleRow($I, $context['betaId'], $context['warehouseId']),
            'a level was set for a product nobody named',
        );
    }
}
