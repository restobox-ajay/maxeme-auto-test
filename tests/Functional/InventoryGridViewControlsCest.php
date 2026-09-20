<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminColumnPreference;
use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Repository\AdminColumnPreferenceRepository;
use App\Service\Inventory\InventoryGridColumns;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The two controls that make fourteen buckets bearable, and the four backorder settings that
 * became columns of their own (#36).
 *
 * ## Why there are two mechanisms and not one
 *
 * WHICH WAREHOUSES is a filter. It narrows what you are looking at right now, so it lives in the
 * query string beside the id/name/sku/category filters, where it can be linked, bookmarked and
 * backed out of — and where a colleague can be sent the exact view being argued about.
 *
 * WHICH COLUMNS is a way of working. It is saved against the admin in `admin_column_preference`,
 * the table the Product Detail grid has used since #120, so it is still there tomorrow and on the
 * other machine. No migration: the table and its (admin, view_key) key already exist.
 *
 * Putting columns in the query string was the alternative and was rejected: this screen builds
 * four separate sets of links — three sort headers, a per-warehouse sort header each, the pager,
 * the per-page select — and a column choice carried in the URL has to be threaded through every
 * one of them, to be lost the first time somebody follows a link that forgot it.
 *
 * ## Why every one of these is driven with JavaScript off
 *
 * The app's baseline is a working page without JS, and the chooser is where that bites hardest:
 * this grid can be nineteen columns wide per warehouse, and a chooser behind a JS toggle would
 * leave a no-JS admin with whatever set was saved last and no way to change it. So it is a
 * `<details>` — the browser's own disclosure — wrapped round a plain POST form, and these tests
 * post exactly what a browser with scripting turned off sends.
 *
 * @group bundle-agnostic
 */
final class InventoryGridViewControlsCest
{
    private const GRID = '/admin/inventory';

    /**
     * Filtering to one warehouse leaves the other's columns off the page entirely.
     *
     * With a positive control, which is the half that makes the negative one mean anything: a
     * `dontSeeElement` passes just as happily against a 500, a login redirect or an empty grid, and
     * the row that SHOULD still be there is what separates "filtered" from "broken".
     */
    public function theWarehouseFilterNarrowsTheGridAndTheOtherWarehouseIsGone(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $west = (new Warehouse())->setName('Chooser West')->setStatus('Active');
        $east = (new Warehouse())->setName('Chooser East')->setStatus('Active');
        $I->haveInRepository($west);
        $I->haveInRepository($east);

        $product = (new ProductCore())->setSku('FILTER-SKU')->setName('Filter Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $I->haveInRepository((new ProductInventory())->setProduct($product)->setWarehouse($west)->setQuantity(311));
        $I->haveInRepository((new ProductInventory())->setProduct($product)->setWarehouse($east)->setQuantity(722));

        $I->haveHttpHeader('Host', 'admin.localhost');

        // Unfiltered: both warehouses have a column group.
        $I->amOnPage(self::GRID);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            ['722', '311'],
            array_map(trim(...), $I->grabMultiple('td[data-column="starting"]')),
            'both warehouses render, East before West, because active warehouses are ordered by name',
        );

        // Filtered to West.
        $I->amOnPage(self::GRID . '?filters%5Bwarehouses%5D%5B%5D=' . $west->getId());
        $I->seeResponseCodeIsSuccessful();

        // Positive control first: the page rendered, the product is on it, and West's own figure is
        // the one being shown.
        $I->assertSame('FILTER-SKU', trim($I->grabTextFrom('td[data-label="SKU"]')));
        $I->assertSame(
            '311',
            trim($I->grabTextFrom('td[data-label="Chooser West — Starting"]')),
            'the warehouse that was asked for is still showing its own count',
        );

        // Now the exclusion. Asserted on the CELLS, never with dontSee('Chooser East') — the
        // warehouse picker still lists every warehouse as a checkbox, as it must, so a page-wide
        // text assertion here would fail for a reason that has nothing to do with the grid (#627).
        $I->dontSeeElement('td[data-label="Chooser East — Starting"]');
        $I->assertSame(
            ['311'],
            array_map(trim(...), $I->grabMultiple('td[data-column="starting"]')),
            'exactly one warehouse group of cells, and it is the one that was asked for',
        );
        $I->assertSame(
            ['Chooser West'],
            array_map(trim(...), $I->grabMultiple('table.wide-price-table thead tr:nth-child(1) th[colspan] a')),
            'and exactly one warehouse group HEADER, or the header and the body disagree about how wide the table is',
        );

        // The picker comes back with the choice checked, so the person can see what they asked for
        // rather than a control that has forgotten it.
        $I->seeCheckboxIsChecked('#inventory-warehouses input[value="' . $west->getId() . '"]');
        $I->dontSeeCheckboxIsChecked('#inventory-warehouses input[value="' . $east->getId() . '"]');
    }

    /**
     * A warehouse id that names nothing narrows to nothing rather than being quietly ignored.
     *
     * Widening a filter the person set is the worse failure of the two: it shows stock they asked
     * not to see, and it looks like the filter working.
     */
    public function anUnknownWarehouseIdShowsNoWarehouseColumnsAtAll(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $warehouse = (new Warehouse())->setName('Only Warehouse')->setStatus('Active');
        $I->haveInRepository($warehouse);

        $product = (new ProductCore())->setSku('UNKNOWN-WH-SKU')->setName('Unknown Warehouse Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $I->haveInRepository((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity(44));

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage(self::GRID . '?filters%5Bwarehouses%5D%5B%5D=' . ((int) $warehouse->getId() + 9000));
        $I->seeResponseCodeIsSuccessful();

        $I->assertSame('UNKNOWN-WH-SKU', trim($I->grabTextFrom('td[data-label="SKU"]')), 'the product row still renders');
        $I->assertSame([], $I->grabMultiple('td[data-column="starting"]'), 'and no warehouse figures with it');
    }

    /**
     * The chooser is a real form, and what it saves is still chosen on the next visit.
     *
     * Both halves matter. A chooser that works only under JS is not a chooser here; a chooser whose
     * choice evaporates on the next page load is a worse screen than no chooser at all, because the
     * person re-picks three columns every time they open it.
     */
    public function theColumnChooserWorksWithoutJavascriptAndItsChoiceSurvivesTheNextVisit(FunctionalTester $I): void
    {
        $admin = $this->loginAsAdmin($I);

        $warehouse = (new Warehouse())->setName('Chooser Warehouse')->setStatus('Active');
        $I->haveInRepository($warehouse);

        $product = (new ProductCore())->setSku('CHOOSER-SKU')->setName('Chooser Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);
        $I->haveInRepository((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity(90));

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage(self::GRID);
        $I->seeResponseCodeIsSuccessful();

        // It is a <details> with a POST form inside, not an element hidden until a script unhides
        // it. The distinction is the whole no-JS argument: `hidden` plus a type="button" toggle —
        // which is how the Product Detail chooser is built — is unreachable with scripting off.
        $I->seeElement('details.inventory-column-chooser > summary');
        $I->seeElement('details.inventory-column-chooser > form[method="post"]');
        $I->dontSeeElement('.inventory-column-chooser[hidden]');
        $I->dontSeeElement('.inventory-column-chooser form [hidden]');

        // The warehouse picker is the same primitive — a <details> popup with checkboxes, not a
        // <select multiple>, and not a JS-only overlay either.
        $I->seeElement('details.inventory-warehouse-chooser > summary');
        $I->seeElement('details.inventory-warehouse-chooser > form[method="get"]');
        $I->seeElement('#inventory-warehouses input[type="checkbox"][name="filters[warehouses][]"]');
        $I->dontSeeElement('select[name="filters[warehouses][]"]');

        // The default set is what the page opens on, and it is NOT everything.
        $registry = $I->grabService(InventoryGridColumns::class);
        $I->assertSame(
            $registry->defaultKeys(),
            array_map(trim(...), $I->grabMultiple('table.wide-price-table thead tr:nth-child(2) th', 'data-column')),
            'the grid opens on the default column set',
        );
        $I->assertGreaterThan(
            count($registry->defaultKeys()),
            count($registry->all()),
            'a default that is already everything would make the chooser pointless and the width complaint unanswered',
        );

        // Save three columns, the way a browser with no JavaScript does it: the form's own action,
        // the form's own CSRF token, no X-Requested-With.
        $this->saveColumns($I, ['starting', 'sales_hold', 'available']);

        $I->assertSame(
            ['Starting', 'Sales Hold', 'Available'],
            array_map(trim(...), $I->grabMultiple('table.wide-price-table thead tr:nth-child(2) th .inventory-bucket-label')),
            'the redirect lands back on the grid with the chosen columns already applied',
        );

        // Re-read from the database rather than believing the response (#624).
        $saved = $I->grabService(AdminColumnPreferenceRepository::class)
            ->findForAdmin($admin, InventoryGridColumns::VIEW_KEY);
        $I->assertInstanceOf(AdminColumnPreference::class, $saved, 'the choice is a row, not a flash of session state');
        $I->assertSame(['starting', 'sales_hold', 'available'], $saved->getColumns());

        // A SECOND page load, fetched fresh. This is the assertion the whole preference exists for.
        $I->amOnPage(self::GRID);
        $I->seeResponseCodeIsSuccessful();
        $I->assertSame(
            ['Starting', 'Sales Hold', 'Available'],
            array_map(trim(...), $I->grabMultiple('table.wide-price-table thead tr:nth-child(2) th .inventory-bucket-label')),
            'the choice is still the choice on the next visit',
        );

        // And the narrowed grid is still a well-formed table: the group header and the filter row
        // both shrank with it. Getting this wrong shears every warehouse group off by one, which
        // reads as a CSS problem and is not one.
        $I->assertSame('3', $I->grabAttributeFrom('table.wide-price-table thead tr:nth-child(1) th[colspan]', 'colspan'));
        $I->assertSame('3', $I->grabAttributeFrom('table.wide-price-table thead tr.filter-row th[colspan]', 'colspan'));
    }

    /**
     * Choosing nothing falls back to the default set rather than rendering a grid with no columns.
     *
     * An empty table is indistinguishable from a broken page, and the person who unticked
     * everything gets told what happened instead of a blank screen.
     */
    public function savingAnEmptySelectionFallsBackToTheDefaults(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $warehouse = (new Warehouse())->setName('Empty Choice Warehouse')->setStatus('Active');
        $I->haveInRepository($warehouse);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $this->openGridAndSave($I, []);

        $registry = $I->grabService(InventoryGridColumns::class);
        $I->assertSame(
            $registry->defaultKeys(),
            array_map(trim(...), $I->grabMultiple('table.wide-price-table thead tr:nth-child(2) th', 'data-column')),
        );
    }

    /**
     * Each of the four backorder controls saves on its own, and none of them touches the stock.
     *
     * This is the regression risk in breaking the stacked cell apart. The four used to share one
     * `<td>`; they now have a column each, which is what lets three of them be hidden while the
     * fourth is shown — and it is what makes "does each one still save?" a question worth asking
     * four times instead of once.
     *
     * The columns are chosen WITHOUT Starting on purpose. The endpoint validates a quantity on
     * every save because a settings change and a stock change share it, and the JS used to read
     * that quantity off the row's own editable box — which is not on the page when Starting is
     * hidden, and the old fallback was the string '0'. So the control carries the figure itself in
     * data-current-quantity, and what this posts is exactly what the browser posts: the attribute's
     * value, not a number the test made up.
     */
    public function eachBackorderControlSavesAsItsOwnColumnAndLeavesTheStockAlone(FunctionalTester $I): void
    {
        $admin = $this->loginAsAdmin($I);

        $warehouse = (new Warehouse())->setName('Backorder Warehouse')->setStatus('Active');
        $other = (new Warehouse())->setName('Untouched Warehouse')->setStatus('Active');
        $I->haveInRepository($warehouse);
        $I->haveInRepository($other);

        $product = (new ProductCore())->setSku('BACKORDER-SKU')->setName('Backorder Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $I->haveInRepository((new ProductInventory())->setProduct($product)->setWarehouse($warehouse)->setQuantity(64));
        // The row that must NOT change — the cheap half of #624, and the half that catches a save
        // written against the product instead of the (product, warehouse) pair.
        $I->haveInRepository((new ProductInventory())->setProduct($product)->setWarehouse($other)->setQuantity(19));

        $I->haveHttpHeader('Host', 'admin.localhost');
        $this->openGridAndSave($I, ['allow_backorder', 'max_backorder', 'backorder_cap_shrinks', 'auto_release_on_restock']);

        // Four columns, four cells, one control each. Stacked in one cell this assertion could not
        // even be written.
        foreach (['allow_backorder', 'max_backorder', 'backorder_cap_shrinks', 'auto_release_on_restock'] as $key) {
            $I->assertCount(
                2,
                $I->grabMultiple('td[data-column="' . $key . '"] input'),
                'one control in its own cell, per warehouse, for ' . $key,
            );
        }

        $I->dontSeeElement('td[data-column="starting"]');
        $I->dontSeeElement('#inv_' . $product->getId() . '_' . $warehouse->getId());

        $token = (string) $I->grabAttributeFrom('table.wide-price-table', 'data-inventory-update-token');
        $carried = $I->grabAttributeFrom(
            'td[data-column="allow_backorder"] input[data-warehouse-id="' . $warehouse->getId() . '"]',
            'data-current-quantity',
        );
        $I->assertSame('64', $carried, 'the control has to carry the stored count, or a settings save posts a quantity nobody typed');

        $settings = [
            'allow_backorder' => '1',
            'max_backorder_quantity' => '25',
            'backorder_cap_shrinks_on_restock' => '1',
            'auto_release_on_restock' => '1',
        ];

        // One request per control, because that is one request per control in the browser: only the
        // field that changed is posted, and the server treats an absent setting as unchanged rather
        // than false. Posting all four together would pass while three of them were unreachable.
        foreach ($settings as $field => $value) {
            $I->sendAjaxPostRequest('/admin/inventory/update', [
                '_token' => $token,
                'product_id' => (string) $product->getId(),
                'warehouse_id' => (string) $warehouse->getId(),
                'quantity' => $carried,
                $field => $value,
            ]);
            $I->seeResponseCodeIsSuccessful();
        }

        // Re-read from the database, not from the response and not from the entity built above.
        $saved = $I->grabEntityFromRepository(ProductInventory::class, [
            'product' => $product->getId(),
            'warehouse' => $warehouse->getId(),
        ]);

        $I->assertTrue($saved->isAllowBackorder(), 'Allow backorder');
        $I->assertSame('25.0000', $saved->getMaxBackorderQuantity(), 'Max backorder');
        $I->assertTrue($saved->isBackorderCapShrinksOnRestock(), 'Shrink cap on restock');
        $I->assertTrue($saved->isAutoReleaseOnRestock(), 'Auto-release on restock');
        $I->assertSame(
            '64.0000',
            $saved->getQuantity(),
            'and four settings saves left the stock exactly where it was — with the Starting column'
            . ' hidden, which is the case that used to post a 0',
        );

        $untouched = $I->grabEntityFromRepository(ProductInventory::class, [
            'product' => $product->getId(),
            'warehouse' => $other->getId(),
        ]);
        $I->assertSame('19.0000', $untouched->getQuantity(), 'the other warehouse holds what it held');
        $I->assertFalse($untouched->isAllowBackorder(), 'and was not opted into backordering by a save aimed elsewhere');

        // Back on the screen, each control opens on what was saved — the round trip, not just the
        // write.
        $I->amOnPage(self::GRID);
        $I->seeCheckboxIsChecked('td[data-column="allow_backorder"] input[data-warehouse-id="' . $warehouse->getId() . '"]');
        $I->seeCheckboxIsChecked('td[data-column="backorder_cap_shrinks"] input[data-warehouse-id="' . $warehouse->getId() . '"]');
        $I->seeCheckboxIsChecked('td[data-column="auto_release_on_restock"] input[data-warehouse-id="' . $warehouse->getId() . '"]');
        $I->assertSame(
            '25',
            $I->grabAttributeFrom('td[data-column="max_backorder"] input[data-warehouse-id="' . $warehouse->getId() . '"]', 'value'),
        );
        $I->dontSeeCheckboxIsChecked('td[data-column="allow_backorder"] input[data-warehouse-id="' . $other->getId() . '"]');

        // The preference is the acting admin's own, not a site-wide default somebody else inherits.
        $pref = $I->grabService(AdminColumnPreferenceRepository::class)
            ->findForAdmin($admin, InventoryGridColumns::VIEW_KEY);
        $I->assertNotNull($pref);
        $I->assertNull(
            $I->grabService(AdminColumnPreferenceRepository::class)->findGlobal(InventoryGridColumns::VIEW_KEY),
            'this chooser deliberately has no "apply to all users": which buckets you want in front of you depends on what you do',
        );
    }

    // ── Plumbing ────────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $keys */
    private function openGridAndSave(FunctionalTester $I, array $keys): void
    {
        $I->amOnPage(self::GRID);
        $I->seeResponseCodeIsSuccessful();
        $this->saveColumns($I, $keys);
    }

    /**
     * Submits the chooser exactly as a browser with JavaScript off does.
     *
     * The action and the token are read off the rendered form rather than typed here, so a form
     * that stopped carrying either fails. submitForm() cannot be used at all on an admin-host page:
     * the crawler resolves the action against the Host header and the module then refuses it as an
     * external URL — see Tests\Support\Helper\Functional::sendFormPostRequest().
     *
     * @param list<string> $keys
     */
    private function saveColumns(FunctionalTester $I, array $keys): void
    {
        $I->sendFormPostRequest($I->grabAttributeFrom('.inventory-column-chooser form', 'action'), [
            '_token' => $I->grabAttributeFrom('.inventory-column-chooser form input[name="_token"]', 'value'),
            'columns' => $keys,
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('table.wide-price-table');
    }

    /** @see AdminInventoryCest::loginAsAdmin() for why the login form itself is skipped. */
    private function loginAsAdmin(FunctionalTester $I): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('inventory-view-controls@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');

        return $admin;
    }
}
