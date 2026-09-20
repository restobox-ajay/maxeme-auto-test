<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Service\AppSettings;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The province and the country are PICKED, not typed — and the picking is backed server-side.
 *
 * ## The defect, in one line
 *
 * A free-text province box accepts the COUNTRY code. `CA` is not a Canadian province, so
 * `RegionSeedData::knownProvinceAnyCountry()` falls through the Canadian table into the US one and
 * resolves it to CALIFORNIA. That is a province the value passes every "is the province known"
 * guard with, and then no Canadian calculator claims it and the purchase order quotes $0.00 tax —
 * a zero derived from a typo, indistinguishable on the document from a genuine zero.
 *
 * The config screens typed it. The document screens have picked it from a country-scoped
 * `<select>` since the region single-source-of-truth work. This makes the config screens agree.
 *
 * ## What is asserted, and how
 *
 * Per #624 every case drives the REAL screen with a plain form POST carrying a CSRF token scraped
 * off the page it posts to, creates its own data, and reads `table.column` back out of SQLite after
 * every POST rather than trusting a flash or a string on the page.
 *
 * Per #627 nothing here calls `see()` on a bare word — 'BC' and 'CA' appear throughout the copy on
 * these pages. Every presence and absence assertion is anchored to an element id (`#warehouse-province`,
 * `#vendor-address-province`, …) and every absence is paired with a POSITIVE CONTROL asserted on
 * that SAME element, so an assertion cannot pass because the element vanished.
 *
 * ## Two failure modes are pinned here, not one
 *
 *  - the one this change exists for: `CA` typed as a Canadian province, which is
 *    {@see caIsNotOfferedAndIsRefusedAsACanadianProvince} and the consequence in
 *    {@see aWarehouseSavedThroughTheControlTaxesFromItsProvince};
 *  - the one a `<select>` INTRODUCES if written carelessly: a stored value absent from the list
 *    renders with nothing selected and the browser posts the first option instead. That is how
 *    opening a Draft product to fix a typo once silently ACTIVATED it. It is pinned in
 *    {@see aStoredValueOutsideTheListStaysSelectedAndIsNotSilentlyRewritten} on both screens.
 *
 * Codeception reuses one Cest instance across methods, so nothing is cached on `$this`; every
 * helper takes the tester and every case makes its own tag.
 */
final class ProvinceAndCountryArePresetCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('preset-region-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function tag(): string
    {
        return strtoupper(substr(uniqid(), -6));
    }

    private function connection(FunctionalTester $I): \Doctrine\DBAL\Connection
    {
        return $I->grabService('doctrine.orm.entity_manager')->getConnection();
    }

    /** @return array<string, mixed> the warehouse row, straight out of SQLite */
    private function warehouseRow(FunctionalTester $I, int $id): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT name, status, address_line1, city, province, postal_code, country FROM warehouse WHERE id = ?',
            [$id],
        );
        $I->assertIsArray($row, 'warehouse row ' . $id . ' is missing');

        return $row;
    }

    /** @return array<string, mixed> the vendor_address row, straight out of SQLite */
    private function vendorAddressRow(FunctionalTester $I, int $id): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT label, address_line1, city, province, postal_code, country FROM vendor_address WHERE id = ?',
            [$id],
        );
        $I->assertIsArray($row, 'vendor_address row ' . $id . ' is missing');

        return $row;
    }

    /**
     * POST the Create Warehouse screen with exactly the fields it renders.
     *
     * Returns the id when the save went through and 0 when it did not, so a caller asserts either
     * outcome without this helper deciding which is correct.
     *
     * @param array<string, string> $fields
     */
    private function postCreateWarehouse(FunctionalTester $I, string $name, array $fields): int
    {
        $I->amOnPage('/admin/warehouse/create');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form.config-form-grid input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/warehouse/create', array_merge([
            '_token' => $token,
            'name' => $name,
            'status' => 'Active',
        ], $fields));

        return (int) $this->connection($I)->fetchOne('SELECT id FROM warehouse WHERE name = ?', [$name]);
    }

    /** @param array<string, string> $fields */
    private function postUpdateWarehouse(FunctionalTester $I, int $id, array $fields): void
    {
        $I->amOnPage('/admin/warehouse/' . $id . '/update');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form.config-form-grid input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/warehouse/' . $id . '/update', array_merge(['_token' => $token], $fields));
    }

    /**
     * A warehouse row written straight to the table, which is how a row carrying a value the list
     * does not offer actually arrives: it predates the rule, or a bulk path wrote it.
     *
     * Written with SQL rather than through the entity on purpose — `Warehouse::setProvince()`
     * normalises, and the point of these cases is a column that already holds something the screen
     * has to cope with.
     */
    private function seedWarehouseRow(FunctionalTester $I, string $name, ?string $province, string $country): int
    {
        $this->connection($I)->executeStatement(
            'INSERT INTO warehouse (name, status, address_line1, city, province, postal_code, country, created_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$name, 'Active', '1 Seeded Way', 'Nowhere', $province, 'V0V 0V0', $country, '2026-01-01 00:00:00'],
        );

        return (int) $this->connection($I)->fetchOne('SELECT id FROM warehouse WHERE name = ?', [$name]);
    }

    private function seedVendor(FunctionalTester $I, string $name): int
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $vendor = (new Vendor())->setName($name)->setCurrency('CAD')->setStatus('Active');
        $em->persist($vendor);
        $em->flush();

        return (int) $vendor->getId();
    }

    /** @param array<string, string> $fields */
    private function postVendorAddress(FunctionalTester $I, int $vendorId, array $fields): void
    {
        $url = '/admin/bundles/procurement/vendors/' . $vendorId;
        // The address form is its own page now (admin_company_address_create's shape); the token
        // comes from there, not from the detail page.
        $I->amOnPage($url . '/address');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form[action$="/address"] input[name="_token"]', 'value');

        $I->sendFormPostRequest($url . '/address', array_merge([
            '_token' => $token,
            'address_id' => '0',
            'address_line_1' => '400 Dock St',
            'city' => 'Surrey',
        ], $fields));
    }

    // ================================================================ 1. CA is not a Canadian province

    /**
     * 1. `CA` cannot be submitted as a Canadian province, because it is not in the list — and the
     *    server refuses it even when the list is bypassed.
     *
     * The absence assertion is anchored to the province select's id, and its positive control is a
     * REAL Canadian province asserted on that same select: if the element were missing or empty,
     * the control fails and the absence proves nothing.
     */
    public function caIsNotOfferedAndIsRefusedAsACanadianProvince(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);

        $I->amOnPage('/admin/warehouse/create');
        $I->seeResponseCodeIsSuccessful();

        // It is a dropdown at all, and it is the one the document forms use.
        $I->seeElement('select#warehouse-province.js-region-province[name="province"]');
        $I->seeElement('select#warehouse-country.js-region-country[name="country"]');
        $I->dontSeeElement('input[name="province"]');
        $I->dontSeeElement('input[name="country"]');

        // The positive control FIRST, on the element the absence is about.
        $I->seeElement('select#warehouse-province option[value="BC"]');
        $I->seeElement('select#warehouse-province option[value="ON"]');
        // …and the defect: 'CA' is a US state code, so it must not be offered under Canada.
        $I->dontSeeElement('select#warehouse-province option[value="CA"]');

        // The country select offers CA as a COUNTRY — the same two letters, the other list.
        $I->seeElement('select#warehouse-country option[value="CA"]');
        $I->seeElement('select#warehouse-country option[value="US"]');
        // And no province ever leaks into the country list.
        $I->dontSeeElement('select#warehouse-country option[value="BC"]');
    }

    // ================================================================ 2. the consequence: real tax

    /**
     * 2. A warehouse saved through the new control stores the province BY COLUMN, and the Canadian
     *    tax path computes a real figure from it.
     *
     * This is the case that proves the fix reaches the thing that was broken. Refusing a bad value
     * is worth nothing on its own; what this change is for is that the tax on a purchase order
     * raised against this building is a figure and not an accident. $20 of GST+PST goods is $2.40
     * in British Columbia (5% + 7%). Typing `CA` into the province box produced $0.00 for the same
     * order, because California is claimed by no Canadian calculator.
     *
     * The exempt order beside it is the control: it makes the $2.40 the province's doing and not a
     * constant, and it is the genuine zero the invented one used to be indistinguishable from.
     */
    public function aWarehouseSavedThroughTheControlTaxesFromItsProvince(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = $this->tag();
        $em = $I->grabService('doctrine.orm.entity_manager');

        // The province that is about to drive this tax was PICKED off a server-rendered list, not
        // typed. Asserted here rather than taken on trust, so this case fails if the screen ever
        // goes back to a free-text box even while the tax arithmetic below still works.
        $I->amOnPage('/admin/warehouse/create');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select#warehouse-province option[value="BC"]');
        $I->dontSeeElement('input[name="province"]');

        $warehouseId = $this->postCreateWarehouse($I, 'Preset Tax DC ' . $tag, [
            'address_line1' => '8 Bridgeview Dr',
            'city' => 'Surrey',
            'province' => 'BC',
            'postal_code' => 'V3S 1A1',
            'country' => 'CA',
        ]);
        $I->assertGreaterThan(0, $warehouseId, 'the warehouse saved through the dropdowns was refused');

        // Read by COLUMN, not off the page.
        $stored = $this->warehouseRow($I, $warehouseId);
        $I->assertSame('BC', $stored['province'], 'warehouse.province after saving through the province dropdown');
        $I->assertSame('CA', $stored['country'], 'warehouse.country after saving through the country dropdown');

        $vendorId = $this->seedVendor($I, 'Preset Vendor ' . $tag);
        $taxed = (new ProductCore())->setSku('PRT-' . $tag)->setName('Preset Taxed Widget ' . $tag)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $taxed->setSalesTaxCode('S');
        $exempt = (new ProductCore())->setSku('PRE-' . $tag)->setName('Preset Exempt Gadget ' . $tag)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $exempt->setSalesTaxCode('E');
        $em->persist($taxed);
        $em->persist($exempt);
        $em->flush();

        $taxedId = $this->raisePurchaseOrder($I, $vendorId, $warehouseId, $taxed->getName(), 'S');
        $exemptId = $this->raisePurchaseOrder($I, $vendorId, $warehouseId, $exempt->getName(), 'E');

        $taxedRow = $this->purchaseOrderRow($I, $taxedId);
        $exemptRow = $this->purchaseOrderRow($I, $exemptId);

        $I->assertSame('BC', $taxedRow['tax_province'], 'purchase_order.tax_province was not derived from the warehouse');
        $I->assertSame(20.0, (float) $taxedRow['subtotal'], 'purchase_order.subtotal on the taxable order');

        // Skipped rather than failed where the bundle that owns British Columbia is switched off:
        // an instance with its tax bundles off has no opinion to assert, and that is not this
        // change's doing.
        if ($I->grabService(BundleStatusRepository::class)->isActive('TaxBCBundle')) {
            $I->assertGreaterThan(
                0.0,
                (float) $taxedRow['tax'],
                'purchase_order.tax against a BC warehouse — a zero here is the California defect',
            );
            $I->assertSame(2.40, (float) $taxedRow['tax'], 'purchase_order.tax for $20 of GST+PST goods delivered in BC');
            $I->assertSame(
                0.0,
                (float) $exemptRow['tax'],
                'purchase_order.tax on exempt goods — the control, so the figure above is the province and not a constant',
            );
        }
    }

    private function raisePurchaseOrder(FunctionalTester $I, int $vendorId, int $warehouseId, string $productName, string $taxCode): int
    {
        $I->amOnPage('/admin/bundles/procurement/purchase-orders/new');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form[action$="/purchase-orders/save"] input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/bundles/procurement/purchase-orders/save', [
            '_token' => $token,
            'id' => '0',
            'charge_lines_present' => '1',
            'vendor_id' => (string) $vendorId,
            'warehouse_id' => (string) $warehouseId,
            'document_date' => '2026-09-10',
            'lines' => [
                0 => ['name' => $productName, 'qty' => '2', 'unit_cost' => '10.0000', 'tax_code' => $taxCode],
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $id = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM purchase_order WHERE warehouse_id = ? AND vendor_id = ? ORDER BY id DESC LIMIT 1',
            [$warehouseId, $vendorId],
        );
        $I->assertGreaterThan(0, $id, 'the purchase order was not raised');

        return $id;
    }

    /** @return array<string, mixed> */
    private function purchaseOrderRow(FunctionalTester $I, int $id): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT status, tax_province, warehouse_id, subtotal, tax, total FROM purchase_order WHERE id = ?',
            [$id],
        );
        $I->assertIsArray($row, 'purchase_order row ' . $id . ' is missing');

        return $row;
    }

    // ================================================================ 3. a stranded stored value

    /**
     * 3. A stored value the list does not offer still renders SELECTED, and re-saving the row does
     *    not silently rewrite it to the first option.
     *
     * This is the Draft-product-silently-activated failure mode, and it is the one a `<select>`
     * introduces rather than fixes: a select whose current value is absent from its options renders
     * with nothing selected, and the browser then posts whatever is first. On this screen the first
     * Canadian option is Alberta, so an admin opening a warehouse to correct its CITY would have
     * moved the building to Alberta and changed the tax on every future purchase order against it,
     * without touching the province box and without being told.
     *
     * `province = 'CA'` on a Canadian warehouse is exactly such a row and is reachable on main:
     * `Warehouse::setProvince()` resolves it country-blind to California and keeps it. So the row
     * seeded here is not a contrivance — it is what the defect leaves behind.
     *
     * The assertion is that the value is VISIBLE and SELECTED, and that a re-save either keeps it or
     * is refused by name — never that it quietly became something else.
     */
    public function aStoredValueOutsideTheListStaysSelectedAndIsNotSilentlyRewritten(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = $this->tag();

        $name = 'Preset Stranded DC ' . $tag;
        $id = $this->seedWarehouseRow($I, $name, 'CA', 'CA');
        $I->assertGreaterThan(0, $id, 'the stranded warehouse row was not seeded');
        $before = $this->warehouseRow($I, $id);
        $I->assertSame('CA', $before['province'], 'the seeded row does not hold the value under test');

        $I->amOnPage('/admin/warehouse/' . $id . '/update');
        $I->seeResponseCodeIsSuccessful();

        // It is rendered, it is selected, and it is on the province select — not blank, not dropped.
        $I->seeElement('select#warehouse-province option[value="CA"][selected]');
        // The positive control on the SAME element: the real list is there too, so the assertion
        // above cannot be passing because the select rendered nothing but the stranded value.
        $I->seeElement('select#warehouse-province option[value="BC"]');
        // And the first real Canadian option is NOT the one selected — the silent-rewrite mode.
        $I->dontSeeElement('select#warehouse-province option[value="AB"][selected]');

        // Now re-save the row the way an admin correcting the CITY would: post the province exactly
        // as the page rendered it selected, and change nothing else about it.
        $this->postUpdateWarehouse($I, $id, [
            'name' => $name,
            'status' => 'Active',
            'address_line1' => '1 Seeded Way',
            'city' => 'Kamloops',
            'province' => 'CA',
            'postal_code' => 'V0V 0V0',
            'country' => 'CA',
        ]);

        $after = $this->warehouseRow($I, $id);
        // The one thing that must never happen: it became Alberta (or anything else) in silence.
        $I->assertNotSame('AB', $after['province'], 'warehouse.province was silently rewritten to the first option in the list');
        $I->assertSame('CA', $after['province'], 'warehouse.province after re-saving a row holding a value outside the list');
    }

    // ================================================================ 4. no JavaScript

    /**
     * 4. It works with scripting off.
     *
     * The functional suite IS the no-JS baseline — Codeception's framework module never runs
     * `app.js` — so this case proves the thing that actually matters: the province options are
     * SERVER-RENDERED for the saved country, not built in the browser. `app.js` only repopulates a
     * province select that arrived with one option or fewer, which is precisely the contract this
     * asserts the server keeps.
     *
     * Then it drives the whole loop with plain form POSTs and reads the column back.
     */
    public function aProvinceCanBeChosenAndSavedWithoutJavaScript(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = $this->tag();

        $I->amOnPage('/admin/warehouse/create');
        $I->seeResponseCodeIsSuccessful();

        // Server-rendered: the real options are in the HTML the server sent, with no JS to build
        // them. Several of them, so this cannot pass on an empty select with a placeholder.
        $I->seeElement('select#warehouse-province option[value="BC"]');
        $I->seeElement('select#warehouse-province option[value="ON"]');
        $I->seeElement('select#warehouse-province option[value="QC"]');
        // The province control is not hidden behind scripting.
        $I->dontSeeElement('select#warehouse-province.js-only');
        $I->dontSeeElement('select#warehouse-country.js-only');

        $name = 'Preset NoJS DC ' . $tag;
        $id = $this->postCreateWarehouse($I, $name, [
            'address_line1' => '77 Yonge St',
            'city' => 'Toronto',
            'province' => 'ON',
            'postal_code' => 'M5E 1J9',
            'country' => 'CA',
        ]);
        $I->assertGreaterThan(0, $id, 'a warehouse could not be created with scripting off');
        $I->assertSame('ON', $this->warehouseRow($I, $id)['province'], 'warehouse.province chosen and saved with no JS');

        // Re-opening it, the saved province comes back SELECTED from the server — the half a no-JS
        // admin needs in order to change anything else on the row without losing it.
        $I->amOnPage('/admin/warehouse/' . $id . '/update');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select#warehouse-province option[value="ON"][selected]');
        $I->seeElement('select#warehouse-province option[value="BC"]');
        $I->dontSeeElement('select#warehouse-province option[value="BC"][selected]');

        // And a second save through the same control moves it, so the control is live and not a
        // read-only echo of what was stored.
        $this->postUpdateWarehouse($I, $id, [
            'name' => $name,
            'status' => 'Active',
            'address_line1' => '77 Yonge St',
            'city' => 'Toronto',
            'province' => 'QC',
            'postal_code' => 'M5E 1J9',
            'country' => 'CA',
        ]);
        $I->assertSame('QC', $this->warehouseRow($I, $id)['province'], 'warehouse.province after a second no-JS save');
    }

    /**
     * 4b. The country select re-scopes the province list SERVER-SIDE.
     *
     * With scripting on, `app.js` rebuilds the province options the moment the country changes.
     * With scripting off there is no such moment, so the re-scope has to happen on the round trip:
     * the page that comes back after a save — or after a refusal — must list the provinces of the
     * country that was submitted, or a no-JS admin can never reach a US state at all.
     */
    public function changingTheCountryReScopesTheProvinceListOnTheRoundTrip(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = $this->tag();

        $name = 'Preset Rescope DC ' . $tag;
        $id = $this->postCreateWarehouse($I, $name, [
            'address_line1' => '1 Pike St', 'city' => 'Seattle', 'province' => 'WA', 'postal_code' => '98101', 'country' => 'US',
        ]);
        $I->assertGreaterThan(0, $id, 'a US warehouse could not be created');
        $I->assertSame('WA', $this->warehouseRow($I, $id)['province'], 'warehouse.province for the US warehouse');

        $I->amOnPage('/admin/warehouse/' . $id . '/update');
        $I->seeResponseCodeIsSuccessful();
        // The list is the US one now, server-rendered, with the saved state selected.
        $I->seeElement('select#warehouse-province option[value="WA"][selected]');
        $I->seeElement('select#warehouse-province option[value="CA"]');
        $I->seeElement('select#warehouse-province option[value="TX"]');
        // …and the Canadian provinces are gone, which is the scoping.
        $I->dontSeeElement('select#warehouse-province option[value="BC"]');
        $I->dontSeeElement('select#warehouse-province option[value="ON"]');

        // The refusal round trip re-scopes too: submit a Canadian country with a US state still in
        // the box, and the page that comes back offers the CANADIAN list so the admin can correct
        // it in one more hop rather than being stuck.
        $this->postUpdateWarehouse($I, $id, [
            'name' => $name,
            'status' => 'Active',
            'address_line1' => '1 Pike St',
            'city' => 'Seattle',
            'province' => 'WA',
            'postal_code' => '98101',
            'country' => 'CA',
        ]);
        $I->seeElement('select#warehouse-province option[value="BC"]');
        $I->seeElement('select#warehouse-province option[value="ON"]');
        $I->see('is not a province or state of', 'form.config-form-grid .field-error');

        // Nothing was written by the refusal.
        $I->assertSame('WA', $this->warehouseRow($I, $id)['province'], 'a refused save changed warehouse.province');
        $I->assertSame('US', $this->warehouseRow($I, $id)['country'], 'a refused save changed warehouse.country');
    }

    // ================================================================ 5. the vendor address screen

    /**
     * 5. The vendor address screen — the same three, on the screen that had NO server-side check at
     *    all before this change.
     *
     * `VendorController::saveAddress()` normalised through `Region` and, when that resolved nothing,
     * kept the typed value clipped to the column width. So `province=CA` on a `country=CA` address
     * stored the two letters `CA` and the screen printed a Canadian address whose province is a US
     * state. Nothing refused it and nothing said so.
     */
    public function theVendorAddressScreenPicksAndRefusesTheSameWay(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = $this->tag();
        $vendorId = $this->seedVendor($I, 'Preset Vendor Addr ' . $tag);

        // The address form is its own page now (admin_company_address_create's shape).
        $I->amOnPage('/admin/bundles/procurement/vendors/' . $vendorId . '/address');
        $I->seeResponseCodeIsSuccessful();

        // (a) It is a dropdown, scoped to the country, and CA is not a Canadian province.
        $I->seeElement('select#vendor-address-province.js-region-province[name="province"]');
        $I->seeElement('select#vendor-address-country.js-region-country[name="country"]');
        $I->dontSeeElement('input[name="province"]');
        $I->dontSeeElement('input[name="country"]');
        $I->seeElement('select#vendor-address-province option[value="BC"]');
        $I->seeElement('select#vendor-address-province option[value="ON"]');
        $I->dontSeeElement('select#vendor-address-province option[value="CA"]');
        $I->seeElement('select#vendor-address-country option[value="CA"]');

        // (b) Saving through the control stores the province by column.
        $this->postVendorAddress($I, $vendorId, [
            'label' => 'Head office',
            'province' => 'BC',
            'country' => 'CA',
            'postal_code' => 'V3S 1A1',
        ]);
        $addressId = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM vendor_address WHERE vendor_id = ? ORDER BY id DESC LIMIT 1',
            [$vendorId],
        );
        $I->assertGreaterThan(0, $addressId, 'the vendor address was not saved');
        $saved = $this->vendorAddressRow($I, $addressId);
        $I->assertSame('BC', $saved['province'], 'vendor_address.province saved through the dropdown');
        $I->assertSame('CA', $saved['country'], 'vendor_address.country saved through the dropdown');

        // (c) A hand-crafted POST carrying the country code as the province is REFUSED, and the
        // column does not move. This is the defect, on this screen, bypassing the dropdown.
        $this->postVendorAddress($I, $vendorId, [
            'address_id' => (string) $addressId,
            'label' => 'Head office',
            'province' => 'CA',
            'country' => 'CA',
            'postal_code' => 'V3S 1A1',
        ]);
        $I->assertSame(
            'BC',
            $this->vendorAddressRow($I, $addressId)['province'],
            'vendor_address.province after posting the country code as the province — CA is California, not a Canadian province',
        );

        // The positive control for that refusal: the same POST with a real Canadian province goes
        // through, so the assertion above is the validation and not a screen that stopped saving.
        $this->postVendorAddress($I, $vendorId, [
            'address_id' => (string) $addressId,
            'label' => 'Head office',
            'province' => 'ON',
            'country' => 'CA',
            'postal_code' => 'M5E 1J9',
        ]);
        $I->assertSame('ON', $this->vendorAddressRow($I, $addressId)['province'], 'a legitimate province was refused');
    }

    /**
     * 5b. A vendor address row holding a value outside the list still renders it selected, on the
     *     screen where such a row is trivially reachable: `saveAddress()` stored anything it could
     *     not resolve, clipped to eight characters.
     */
    public function aStrandedVendorAddressProvinceStaysSelected(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = $this->tag();
        $vendorId = $this->seedVendor($I, 'Preset Vendor Stranded ' . $tag);

        $this->connection($I)->executeStatement(
            'INSERT INTO vendor_address (vendor_id, label, address_line1, city, province, postal_code, country,'
                . ' is_default, is_order_to, is_ship_from, is_remit_to, is_return_to)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, 0, 0, 0)',
            [$vendorId, 'Legacy', '9 Old Rd', 'Victoria', 'ZZ', 'V8W 1A1', 'CA'],
        );
        $addressId = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM vendor_address WHERE vendor_id = ? ORDER BY id DESC LIMIT 1',
            [$vendorId],
        );
        $I->assertGreaterThan(0, $addressId, 'the stranded vendor address was not seeded');

        // The row went in behind Doctrine, and this tester shares its EntityManager with the kernel
        // serving amOnPage() — so the vendor's address collection is already initialised and empty.
        // Without this the screen renders an ADD form and the assertions below would be testing a
        // blank select rather than a stranded value.
        $I->grabService('doctrine.orm.entity_manager')->clear();

        $I->amOnPage('/admin/bundles/procurement/vendors/' . $vendorId . '/address?address=' . $addressId);
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('select#vendor-address-province option[value="ZZ"][selected]');
        // Positive control on the same element.
        $I->seeElement('select#vendor-address-province option[value="BC"]');
        // And it did not silently become the first option in the Canadian list.
        $I->dontSeeElement('select#vendor-address-province option[value="AB"][selected]');
    }

    /**
     * 5c. The vendor screen's BACKEND guard, isolated from its dropdowns.
     *
     * Deliberately asserts nothing about the markup, so it fails for the reason it is about rather
     * than because the select is not there yet. On main this case stores `CA` as the province of a
     * Canadian address and the assertion below reads it back: `saveAddress()` had no check at all,
     * and `normalizedProvince()` keeps whatever `Region` cannot resolve, clipped to the column
     * width. This is the one screen of the four where the California defect was still live.
     */
    public function theVendorScreenRefusesABadProvinceWhenThePostBypassesTheForm(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = $this->tag();
        $vendorId = $this->seedVendor($I, 'Preset Vendor Guard ' . $tag);

        $this->postVendorAddress($I, $vendorId, ['label' => 'Head office', 'province' => 'BC', 'country' => 'CA']);
        $addressId = (int) $this->connection($I)->fetchOne(
            'SELECT id FROM vendor_address WHERE vendor_id = ? ORDER BY id DESC LIMIT 1',
            [$vendorId],
        );
        $I->assertGreaterThan(0, $addressId, 'the control address was not created');
        $before = $this->vendorAddressRow($I, $addressId);
        $I->assertSame('BC', $before['province'], 'the control address did not store its province');

        // The country code in the province box. California, under Canada.
        $this->postVendorAddress($I, $vendorId, [
            'address_id' => (string) $addressId, 'label' => 'Head office', 'province' => 'CA', 'country' => 'CA',
        ]);
        $I->assertSame($before, $this->vendorAddressRow($I, $addressId), 'a vendor address row moved behind a refused province');

        // A province of the WRONG country, which a country-blind check would wave through.
        $this->postVendorAddress($I, $vendorId, [
            'address_id' => (string) $addressId, 'label' => 'Head office', 'province' => 'TX', 'country' => 'CA',
        ]);
        $I->assertSame($before, $this->vendorAddressRow($I, $addressId), 'a Texas province was stored on a Canadian address');

        // A country outside the list.
        $this->postVendorAddress($I, $vendorId, [
            'address_id' => (string) $addressId, 'label' => 'Head office', 'province' => 'BC', 'country' => 'ZZ',
        ]);
        $I->assertSame($before, $this->vendorAddressRow($I, $addressId), 'a vendor address row moved behind a refused country');

        // Positive controls, so every assertion above is the guard and not a screen that stopped
        // saving: the same POST shape with legitimate values goes through, in both countries.
        $this->postVendorAddress($I, $vendorId, [
            'address_id' => (string) $addressId, 'label' => 'Head office', 'province' => 'ON', 'country' => 'CA',
        ]);
        $I->assertSame('ON', $this->vendorAddressRow($I, $addressId)['province'], 'a legitimate Canadian province was refused');

        $this->postVendorAddress($I, $vendorId, [
            'address_id' => (string) $addressId, 'label' => 'Head office', 'province' => 'CA', 'country' => 'US',
        ]);
        $afterUs = $this->vendorAddressRow($I, $addressId);
        $I->assertSame('CA', $afterUs['province'], 'California was refused as a US state');
        $I->assertSame('US', $afterUs['country'], 'vendor_address.country after the accepted US save');

        // And a blank province stays legal here — this screen never required one and this change
        // does not start.
        $this->postVendorAddress($I, $vendorId, [
            'address_id' => (string) $addressId, 'label' => 'Head office', 'province' => '', 'country' => 'US',
        ]);
        $I->assertNull($this->vendorAddressRow($I, $addressId)['province'], 'a blank province was refused on a screen that allows one');
    }

    /**
     * The fourth screen, and the one that is a different shape: the CSV import's location mapping.
     *
     * Its rows repeat, so the two controls are the shared partial with INDEXED names rather than
     * `[]`. That is not cosmetic — `app.js` pairs a country select with its province by name, using
     * `.first()`, so with `[]` every row's country would name the same partner and changing the
     * country on the ninth row would repopulate the FIRST row's province list. Indexed names give
     * each row its own pair. The controller reads both through `request->all()` and zips them
     * against `locations[]` by position, so the POST it parses is unchanged — which is what
     * {@see WarehouseProvinceIsRequiredCest} asserts by driving the real upload.
     *
     * Asserted against the response source rather than through seeElement() for the reason that
     * Cest records: sendMultipartPostRequest() leaves the crawler on the page before the upload.
     */
    public function theImportLocationMappingUsesTheSameControlsPerRow(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = $this->tag();

        $html = $this->uploadOneRowCsvForMapping($I, 'PRESET-' . $tag, 'Preset Location ' . $tag);

        // The controls are the shared partial, one pair per row, each row's own.
        $I->assertStringContainsString('id="region-province-0"', $html, 'the import row has no province select');
        $I->assertStringContainsString('id="region-country-0"', $html, 'the import row has no country select');
        $I->assertStringContainsString('name="region_provinces[0]"', $html, 'the province name is not indexed per row');
        $I->assertStringContainsString('name="region_countries[0]"', $html, 'the country name is not indexed per row');
        // Paired to ITS OWN row, which is the whole reason the names are indexed.
        $I->assertStringContainsString('data-province-target="region_provinces[0]"', $html, 'the row country is not paired to its own province');

        // The list is server-rendered and scoped: a real Canadian province is offered…
        $I->assertStringContainsString('British Columbia', $html, 'the province list was not rendered');
        // …and the free-text box it replaced is gone.
        $I->assertStringNotContainsString('name="region_provinces[]"', $html, 'the old free-text province field is still rendered');
        $I->assertStringNotContainsString('pattern="[A-Za-z]{2}"', $html, 'a two-letter text pattern is still standing in for a list');
    }

    /**
     * Upload a one-row CSV and return the mapping screen's HTML.
     *
     * @return string the rendered mapping step
     */
    private function uploadOneRowCsvForMapping(FunctionalTester $I, string $sku, string $location): string
    {
        $em = $I->grabService('doctrine.orm.entity_manager');
        $category = (new \App\Entity\ProductCategory())->setName('Preset Import Cat ' . $sku);
        $em->persist($category);
        $em->flush();

        $I->amOnPage('/admin/bundles/number1-product-import');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $header = 'NAME,REFNUM,INVITEMTYPE,DESC,QNTY,PRICE,COST,VALUE,TAXABLE,SALESTAXCODE,ACCNT,ASSETACCNT,COGSACCNT,VENDOR,LOCATION,NOTES';
        $row = sprintf(
            'Preset Import Widget,%s,INVENTORY,Widget for the preset test,7,10.00,5.00,35.00,Y,S,Sales,Inventory Asset,COGS,Preset Vendor,%s,',
            $sku,
            $location,
        );
        $path = tempnam(sys_get_temp_dir(), 'preset_import_');
        file_put_contents($path, $header . "\n" . $row . "\n");

        $I->sendMultipartPostRequest('/admin/bundles/number1-product-import', [
            '_token' => $token,
            'missing_rows' => 'do_nothing',
            'fallback_region_id' => '0',
            'fallback_category_id' => (string) $category->getId(),
        ], [
            'csv_file' => [
                'name' => 'preset-region.csv',
                'type' => 'text/csv',
                'tmp_name' => $path,
                'error' => \UPLOAD_ERR_OK,
                'size' => filesize($path),
            ],
        ]);

        // Read out of the response SOURCE: after a multipart POST the module's crawler is still on
        // the upload screen, so a CSS grab would inspect the wrong page entirely.
        $html = (string) $I->grabPageSource();
        $I->assertStringContainsString($location, $html, 'the mapping screen never listed the uploaded location');

        return $html;
    }

    // ================================================================ 6-8. the backend is the guard

    /**
     * 6. A province outside the list, posted with the form bypassed entirely, is REFUSED and the
     *    columns do not move.
     *
     * A `<select>` constrains a browser and nothing else. Every assertion in this case is a POST
     * that no rendering of the page could have produced.
     */
    public function aProvinceOutsideTheListIsRefusedServerSide(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = $this->tag();

        $name = 'Preset Guard DC ' . $tag;
        $id = $this->postCreateWarehouse($I, $name, [
            'address_line1' => '8 Bridgeview Dr', 'city' => 'Surrey', 'province' => 'BC', 'postal_code' => 'V3S 1A1', 'country' => 'CA',
        ]);
        $I->assertGreaterThan(0, $id, 'the control warehouse was not created');
        $before = $this->warehouseRow($I, $id);

        // Nothing in either list spells this.
        $this->postUpdateWarehouse($I, $id, [
            'name' => $name, 'status' => 'Active', 'address_line1' => '8 Bridgeview Dr',
            'city' => 'Surrey', 'province' => 'ZZ', 'postal_code' => 'V3S 1A1', 'country' => 'CA',
        ]);
        $I->see('is not a province or state of', 'form.config-form-grid .field-error');
        $I->assertSame($before, $this->warehouseRow($I, $id), 'a warehouse row moved behind a refused province');

        // Positive control: the same POST shape with a legitimate province succeeds.
        $this->postUpdateWarehouse($I, $id, [
            'name' => $name, 'status' => 'Active', 'address_line1' => '8 Bridgeview Dr',
            'city' => 'Surrey', 'province' => 'ON', 'postal_code' => 'V3S 1A1', 'country' => 'CA',
        ]);
        $I->assertSame('ON', $this->warehouseRow($I, $id)['province'], 'a legitimate province was refused by the same POST shape');
    }

    /**
     * 7. `province=CA` with `country=CA` is refused; `province=CA` with `country=US` is accepted.
     *
     * The whole defect in two assertions. The same two letters are a mistake under one country and
     * California under the other, and only a check scoped to the SUBMITTED country can tell them
     * apart — which is exactly what `knownProvinceAnyCountry()` cannot do, because it loops the
     * country tables and returns the first match.
     */
    public function caIsRefusedUnderCanadaAndAcceptedUnderTheUnitedStates(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = $this->tag();

        $name = 'Preset CACA DC ' . $tag;
        $id = $this->postCreateWarehouse($I, $name, [
            'address_line1' => '8 Bridgeview Dr', 'city' => 'Surrey', 'province' => 'BC', 'postal_code' => 'V3S 1A1', 'country' => 'CA',
        ]);
        $I->assertGreaterThan(0, $id, 'the warehouse under test was not created');
        $before = $this->warehouseRow($I, $id);

        // CA under Canada: refused, by name, and nothing written.
        $this->postUpdateWarehouse($I, $id, [
            'name' => $name, 'status' => 'Active', 'address_line1' => '8 Bridgeview Dr',
            'city' => 'Surrey', 'province' => 'CA', 'postal_code' => 'V3S 1A1', 'country' => 'CA',
        ]);
        $I->see('is not a province or state of', 'form.config-form-grid .field-error');
        $I->assertSame($before, $this->warehouseRow($I, $id), 'CA was stored as a Canadian province');

        // CA under the United States: accepted, because California is real.
        $this->postUpdateWarehouse($I, $id, [
            'name' => $name, 'status' => 'Active', 'address_line1' => '1 Market St',
            'city' => 'San Francisco', 'province' => 'CA', 'postal_code' => '94105', 'country' => 'US',
        ]);
        $after = $this->warehouseRow($I, $id);
        $I->assertSame('CA', $after['province'], 'California was refused as a US state');
        $I->assertSame('US', $after['country'], 'warehouse.country after the accepted US save');
    }

    /**
     * 8. A country outside the list is refused, and the columns do not move.
     */
    public function aCountryOutsideTheListIsRefusedServerSide(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = $this->tag();

        $name = 'Preset Country Guard DC ' . $tag;
        $id = $this->postCreateWarehouse($I, $name, [
            'address_line1' => '8 Bridgeview Dr', 'city' => 'Surrey', 'province' => 'BC', 'postal_code' => 'V3S 1A1', 'country' => 'CA',
        ]);
        $I->assertGreaterThan(0, $id, 'the control warehouse was not created');
        $before = $this->warehouseRow($I, $id);

        $this->postUpdateWarehouse($I, $id, [
            'name' => $name, 'status' => 'Active', 'address_line1' => '8 Bridgeview Dr',
            'city' => 'Surrey', 'province' => 'BC', 'postal_code' => 'V3S 1A1', 'country' => 'ZZ',
        ]);
        $I->see('is not a country', 'form.config-form-grid .field-error');
        $I->assertSame($before, $this->warehouseRow($I, $id), 'a warehouse row moved behind a refused country');

        // Positive control on the same field: a real country goes through.
        $this->postUpdateWarehouse($I, $id, [
            'name' => $name, 'status' => 'Active', 'address_line1' => '1 Market St',
            'city' => 'San Francisco', 'province' => 'CA', 'postal_code' => '94105', 'country' => 'US',
        ]);
        $I->assertSame('US', $this->warehouseRow($I, $id)['country'], 'a legitimate country was refused');
    }

    // ================================================================ the fulfillment region screen

    /**
     * The third config screen: creating a region asks for the warehouse it will create, and those
     * two boxes are the same controls fed from the same list.
     */
    public function theFulfillmentRegionScreenAsksForTheWarehouseAddressWithTheSameControls(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $tag = $this->tag();

        $I->amOnPage('/admin/fulfillment-region/create');
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('select#fulfillment-region-warehouse-province.js-region-province[name="warehouse_province"]');
        $I->seeElement('select#fulfillment-region-warehouse-country.js-region-country[name="warehouse_country"]');
        $I->dontSeeElement('input[name="warehouse_province"]');
        $I->dontSeeElement('input[name="warehouse_country"]');
        $I->seeElement('select#fulfillment-region-warehouse-province option[value="BC"]');
        $I->dontSeeElement('select#fulfillment-region-warehouse-province option[value="CA"]');

        // And the loop still works end to end: the region is created and the building it creates
        // carries the province by column.
        $regionName = 'Preset Region ' . $tag;
        $token = (string) $I->grabAttributeFrom('form.config-form-grid input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/fulfillment-region/create', [
            '_token' => $token,
            'name' => $regionName,
            'status' => 'Active',
            'default_for_new_company' => '0',
            'warehouse_province' => 'BC',
            'warehouse_country' => 'CA',
        ]);

        $regionId = (int) $this->connection($I)->fetchOne('SELECT id FROM fulfillment_region WHERE name = ?', [$regionName]);
        $I->assertGreaterThan(0, $regionId, 'the region was not created through the dropdowns');
        $warehouseId = (int) $this->connection($I)->fetchOne(
            'SELECT warehouse_id FROM warehouse_fulfillment_region WHERE fulfillment_region_id = ?',
            [$regionId],
        );
        $I->assertGreaterThan(0, $warehouseId, 'the region got no warehouse');
        $I->assertSame('BC', $this->warehouseRow($I, $warehouseId)['province'], 'warehouse.province on the building created with the region');
    }
}
