<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use App\Service\AppSettings;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The four bundle-contributed sidebar GROUPS — Vendors, Purchases, Warehouse, Inventory — driven
 * through the real screens.
 *
 * Until now these fourteen/seven/ten/one screens were flat `app.injection_point_menu_item` lines
 * appended to the bottom of the Apps group: no nesting, and a highlight that only ever came from
 * the template's own `route == item.route` test. They now come from four
 * App\Contract\Menu\AdminMenuOverrideProviderInterface implementations, one per bundle, which puts
 * them in the tree beside Orders and Products.
 *
 * Tests\Functional\AdminMenuDefaultSidebarCest pins the whole rendered nav as a snapshot, which is
 * what proves the ORDER and the labels. This asserts the two things a snapshot of one page cannot:
 * that a group opens when you are inside it, and that the right child — and only the right child —
 * highlights on its own page.
 */
final class AdminBundleMenuGroupsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('menu-groups-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /** The `is-open` class on the nav-group whose title reads $label. Text is not a CSS selector, so XPath. */
    private function seeGroupIsOpen(FunctionalTester $I, string $label): void
    {
        $I->seeElement(sprintf(
            '//div[contains(concat(" ", normalize-space(@class), " "), " nav-group ") and contains(concat(" ", normalize-space(@class), " "), " is-open ")]/button[span[@class="nav-label"][normalize-space()="%s"]]',
            $label,
        ));
    }

    /**
     * Both halves on one page, and the negative half is the one that matters: a highlight rule
     * broad enough to light up every sibling passes a "the current entry is current" assertion.
     */
    public function eachGroupOpensOnItsOwnScreenAndOnlyThatScreensEntryIsCurrent(FunctionalTester $I): void
    {
        $cases = [
            // page, the group that must open, the entry that must be current, a sibling that must not
            ['/admin/bundles/procurement/vendors', 'Vendors', '/admin/bundles/procurement/vendors', '/admin/bundles/procurement/vendor-prices'],
            ['/admin/bundles/procurement/vendor-prices', 'Vendors', '/admin/bundles/procurement/vendor-prices', '/admin/bundles/procurement/vendors'],
            ['/admin/bundles/procurement/bills', 'Purchases', '/admin/bundles/procurement/bills', '/admin/bundles/procurement/bills/new'],
            // The RFQ list used to be a case here. Queue item 56 took its row off the menu
            // (GitHub #662), so there is no entry left to be current — what the Purchases group
            // still does on that screen is asserted in RfqIsOffTheMenuCest instead.
            ['/admin/bundles/warehouse-ops/labels', 'Warehouse', '/admin/bundles/warehouse-ops/labels', '/admin/bundles/barcodes'],
            ['/admin/bundles/warehouse-ops/scan', 'Warehouse', '/admin/bundles/warehouse-ops/scan', '/admin/bundles/warehouse-ops/transfers'],
            ['/admin/bundles/inventory-depth/lots', 'Inventory', '/admin/bundles/inventory-depth/lots', '/admin/bundles/inventory-depth/bins'],
            ['/admin/bundles/inventory-depth/low-stock', 'Inventory', '/admin/bundles/inventory-depth/low-stock', '/admin/bundles/inventory-depth/movements'],
        ];

        foreach ($cases as [$page, $group, $current, $sibling]) {
            $I->amOnPage($page);
            $I->seeResponseCodeIs(200);

            $this->seeGroupIsOpen($I, $group);
            $I->seeElement('nav#primary-navigation a.nav-sub.is-current', ['href' => $current]);
            $I->dontSeeElement('nav#primary-navigation a.nav-sub.is-current', ['href' => $sibling]);
        }
    }

    /**
     * BarcodeBundle contributes one entry into the group WarehouseOpsBundle contributes the rest
     * of — the shared heading #632 introduced, kept as a shared group. On the barcode screen the
     * Warehouse group opens and the Barcodes entry is the current one, from a different bundle's
     * provider than the one that owns most of the list.
     */
    public function theBarcodeScreenLightsUpInsideTheGroupItSharesWithWarehouseOperations(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/bundles/barcodes');
        $I->seeResponseCodeIs(200);

        $this->seeGroupIsOpen($I, 'Warehouse');
        $I->seeElement('nav#primary-navigation a.nav-sub.is-current', ['href' => '/admin/bundles/barcodes']);
        $I->dontSeeElement('nav#primary-navigation a.nav-sub.is-current', ['href' => '/admin/bundles/warehouse-ops/labels']);
    }

    /**
     * The Warehouse group is declared by BOTH bundles, so switching one off leaves the group
     * standing with the other bundle's entries in it — the property #632 wanted from a shared
     * heading, now that the heading is a group.
     */
    public function switchingWarehouseOperationsOffLeavesBarcodesInAWarehouseGroupOfItsOwn(FunctionalTester $I): void
    {
        // Switched off through the one activation path. A fresh BundleStatus used to be safe here
        // because nothing had created one — absence of a row was the enabled default. Every
        // installed bundle now gets an explicit Active row before the suite (tests/_bootstrap.php),
        // so a second insert for the same source trips the UNIQUE index instead of switching
        // anything off.
        $I->grabService(BundleStatusRepository::class)->deactivate('WarehouseOpsBundle');

        $I->amOnPage('/admin/bundles/barcodes');
        $I->seeResponseCodeIs(200);

        $this->seeGroupIsOpen($I, 'Warehouse');
        $I->seeElement('nav#primary-navigation a.nav-sub.is-current', ['href' => '/admin/bundles/barcodes']);

        foreach ([
            '/admin/bundles/warehouse-ops/scan',
            '/admin/bundles/warehouse-ops/labels',
            '/admin/bundles/warehouse-ops/discrepancies',
            '/admin/bundles/warehouse-ops',
        ] as $gone) {
            $I->dontSeeElement('nav#primary-navigation a', ['href' => $gone]);
        }
    }

    /**
     * The mirror image: with BarcodeBundle off, the group and the other bundle's seven entries are
     * untouched and only the one line goes.
     */
    public function switchingBarcodesOffTakesOnlyItsOwnLineOutOfTheSharedGroup(FunctionalTester $I): void
    {
        // Switched off through the one activation path. A fresh BundleStatus used to be safe here
        // because nothing had created one — absence of a row was the enabled default. Every
        // installed bundle now gets an explicit Active row before the suite (tests/_bootstrap.php),
        // so a second insert for the same source trips the UNIQUE index instead of switching
        // anything off.
        $I->grabService(BundleStatusRepository::class)->deactivate('BarcodeBundle');

        $I->amOnPage('/admin/bundles/warehouse-ops/labels');
        $I->seeResponseCodeIs(200);

        $this->seeGroupIsOpen($I, 'Warehouse');
        $I->seeElement('nav#primary-navigation a.nav-sub.is-current', ['href' => '/admin/bundles/warehouse-ops/labels']);
        $I->seeElement('nav#primary-navigation a', ['href' => '/admin/bundles/warehouse-ops/discrepancies']);
        $I->dontSeeElement('nav#primary-navigation a', ['href' => '/admin/bundles/barcodes']);
    }

    /**
     * Switching a bundle Inactive takes its group and every entry in it. The bundles-off
     * environment asserts all four at once (Tests\Functional\BundlesOffEnvironmentCest); this is
     * the one-bundle-at-a-time version, on the ordinary environment, which is the switch an admin
     * actually flips on App Management.
     */
    public function switchingProcurementOffTakesBothOfItsGroupsAndEveryEntryInThem(FunctionalTester $I): void
    {
        // Switched off through the one activation path. A fresh BundleStatus used to be safe here
        // because nothing had created one — absence of a row was the enabled default. Every
        // installed bundle now gets an explicit Active row before the suite (tests/_bootstrap.php),
        // so a second insert for the same source trips the UNIQUE index instead of switching
        // anything off.
        $I->grabService(BundleStatusRepository::class)->deactivate('ProcurementBundle');

        $I->amOnPage('/admin');
        $I->seeResponseCodeIs(200);

        preg_match('/<nav id="primary-navigation".*?<\/nav>/s', $I->grabPageSource(), $matches);
        $nav = $matches[0] ?? '';
        $I->assertNotSame('', $nav, 'no sidebar was rendered at all, so nothing below is testing anything');
        // Positive control: without it the two "not contains" assertions would pass on an empty nav.
        $I->assertStringContainsString('<span class="nav-label">Sales Orders</span>', $nav);

        $I->assertStringNotContainsString('<span class="nav-label">Vendors</span>', $nav);
        $I->assertStringNotContainsString('<span class="nav-label">Purchases</span>', $nav);

        foreach ([
            '/admin/bundles/procurement/vendors',
            '/admin/bundles/procurement/vendor-prices',
            // No RFQ list here: queue item 56 took its row off the menu whether the bundle is on
            // or off, so naming it would be an absence that cannot fail.
            '/admin/bundles/procurement/purchase-orders',
            '/admin/bundles/procurement/bills',
            '/admin/bundles/procurement/debit-memos',
            '/admin/bundles/procurement/exceptions',
            '/admin/bundles/procurement',
        ] as $gone) {
            $I->dontSeeElement('nav#primary-navigation a', ['href' => $gone]);
        }

        // The groups that are NOT this bundle's are untouched — the row that should not have changed.
        $I->assertStringContainsString('<span class="nav-label">Warehouse</span>', $nav);
        $I->assertStringContainsString('<span class="nav-label">Inventory</span>', $nav);
        $I->seeElement('nav#primary-navigation a', ['href' => '/admin/bundles/warehouse-ops/scan']);
        $I->seeElement('nav#primary-navigation a', ['href' => '/admin/bundles/inventory-depth/lots']);
    }

    /**
     * The four config menus under Apps are a DIFFERENT mechanism (shipping_menu_items(),
     * fee_menu_items(), tax_menu_items(), payment_menu_items() in
     * templates/admin/_main/layout.html.twig) that happens to live in the same `{% if node.key ==
     * 'apps' %}` branch as the injection-point loop these four bundles stopped feeding. Deleting
     * that branch would have taken all four with it, so this asserts they are still there.
     */
    public function theShippingFeeTaxAndPaymentMenusUnderAppsAreUntouched(FunctionalTester $I): void
    {
        $I->amOnPage('/admin');
        $I->seeResponseCodeIs(200);

        preg_match('/<nav id="primary-navigation".*?<\/nav>/s', $I->grabPageSource(), $matches);
        $nav = $matches[0] ?? '';
        $I->assertNotSame('', $nav);

        foreach (['Shipping', 'Fees', 'Tax', 'Payments'] as $section) {
            $I->assertStringContainsString(sprintf('<span class="nav-sub-section">%s</span>', $section), $nav);
        }

        foreach ([
            '/admin/bundles/shipping/canada-post',
            '/admin/bundles/fees/user-defined',
            '/admin/bundles/tax/bc',
            '/admin/bundles/payments/stripe',
        ] as $href) {
            $I->seeElement('nav#primary-navigation a', ['href' => $href]);
        }
    }
}
