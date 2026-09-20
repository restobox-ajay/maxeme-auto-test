<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;
use Tests\Support\Helper\BundlesOff;

/**
 * Proves the bundles-off environment actually switches the bundles off (#562).
 *
 * Without this, the whole gate is worthless in the most dangerous way: if the helper silently did
 * nothing — a hook that never fires, a flush inside the wrong transaction, a container that cached
 * the status before `_before` ran — then `bin/ci-bundles-off` would run the ordinary application,
 * pass, and report that the bundles-off behaviour is proven. A false green is worse than no gate,
 * because it stops anyone looking.
 *
 * Every test here runs ONLY in that environment. It is the one thing that must not hold with the
 * bundles on, so it cannot be tagged `bundle-agnostic` like the rest of the group.
 *
 * ## How it runs, and why that needed saying
 *
 * Those two facts used to add up to nothing running it at all. The ordinary suite skips it on the
 * environment annotation below — "No tests executed!" — and `bin/ci-bundles-off`, the only thing
 * that selects that environment, ran a single command ending `-g bundle-agnostic`, which excluded
 * it for the reason the paragraph above gives. So the test whose entire job is to rule out a false
 * green had itself never executed, and the gate that depends on it had never seen it pass.
 *
 * It now has its own invocation in `bin/ci-bundles-off`, with no group filter, ahead of the group
 * run. `BundlesOffPairingTest` asserts that invocation is still there and still ungrouped.
 *
 * (That paragraph says "the environment annotation below" rather than spelling the tag out:
 * Codeception's annotation reader scans the whole docblock line by line, so a second literal
 * `@`env anywhere in this prose registers as a SECOND environment for every test in the class —
 * one named after the rest of the sentence. That is where three "Environment ` below — …` was not
 * configured but used in test" warnings on every run of this Cest came from.)
 *
 * @env bundles-off
 */
final class BundlesOffEnvironmentCest
{
    /** The database says Inactive — the helper wrote, and its write survived into the test. */
    public function everyOptionalBundleIsMarkedInactive(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);

        foreach (BundlesOff::SOURCES as $source) {
            $status = $em->getRepository(BundleStatus::class)->findOneBy(['source' => $source]);

            $I->assertNotNull($status, sprintf('%s has no BundleStatus row at all', $source));
            $I->assertSame(
                BundleStatus::STATUS_INACTIVE,
                $status->getStatus(),
                sprintf('%s is not Inactive, so this environment is not testing what it claims to', $source),
            );
        }
    }

    /**
     * And the APPLICATION agrees, which is the assertion that matters.
     *
     * A row saying Inactive proves the helper wrote. It does not prove anything consults it — the
     * container could have resolved the bundle before `_before` ran. A bundle screen returning 404
     * is the application itself reporting that the bundle is absent, which is the property the gate
     * depends on. `InventoryDepthCest` establishes that Inactive reads as absent rather than
     * forbidden; this asserts the environment produces that state.
     */
    public function theApplicationTreatsThemAsAbsent(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/inventory-depth');
        $I->seeResponseCodeIs(404);
    }

    /**
     * A switched-off bundle takes its WHOLE group with it, not just its hub line.
     *
     * #632 gave each bundle its own sidebar section and the bundle-contributed menu providers
     * turned each of those into a real top-level GROUP: Procurement contributes fourteen entries
     * plus two groups where it used to contribute one line. The gate is now
     * App\Menu\Admin\AdminMenuTreeBuilder::build() skipping a provider whose getSource() is not
     * active — a provider naming the wrong source would keep rendering with its bundle off, and
     * the 404 above would still pass, because the route gate is a different mechanism. So this
     * asserts the sidebar itself.
     *
     * Asserted against the rendered nav markup rather than a CSS selector plus a `text` filter:
     * Codeception's attribute filter compares real DOM ATTRIBUTES, so `['text' => 'Vendors']`
     * matches nothing whatever the page says and the assertion passes for the wrong reason. Href
     * is a real attribute, so dontSeeElement is honest for the links; the group titles are checked
     * as exact label markup instead, which is also what keeps "Warehouse" from colliding with
     * Settings > "Warehouses" and "Inventory" with Products > "Product Inventory".
     */
    public function aSwitchedOffBundleTakesEveryOneOfItsNavEntries(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin');
        $I->seeResponseCodeIs(200);

        preg_match('/<nav id="primary-navigation".*?<\/nav>/s', $I->grabPageSource(), $matches);
        $nav = $matches[0] ?? '';
        $I->assertNotSame('', $nav, 'no sidebar was rendered at all, so nothing below is testing anything');

        // The positive control. Without it every assertion below would also pass against an empty
        // string, which is exactly the false green this whole Cest exists to rule out.
        $I->assertStringContainsString('<span class="nav-label">Orders</span>', $nav);

        // The four top-level groups the bundles contribute. Two bundles declare Warehouse, so it
        // only disappears when BOTH are off — which, in this environment, they are.
        foreach (['Vendors', 'Purchases', 'Warehouse', 'Inventory'] as $group) {
            $I->assertStringNotContainsString(
                sprintf('<span class="nav-label">%s</span>', $group),
                $nav,
                sprintf('the %s group is still in the sidebar with its bundle switched off', $group),
            );
        }

        // Every entry, named rather than counted: a count passes while the wrong ones hide.
        foreach ([
            // ProcurementBundle — Vendors
            '/admin/bundles/procurement/vendors',
            '/admin/bundles/procurement/vendor-prices',
            // ProcurementBundle — Purchases. No RFQ list: queue item 56 took its row off the menu
            // with the bundle Active too (GitHub #662), so naming it here would assert an absence
            // that cannot fail whichever way this environment is switched.
            '/admin/bundles/procurement/purchase-orders',
            '/admin/bundles/procurement/purchase-orders/new',
            '/admin/bundles/procurement/purchase-orders/expected',
            '/admin/bundles/procurement/receiving',
            '/admin/bundles/procurement/receiving/new',
            '/admin/bundles/procurement/vendor-returns',
            '/admin/bundles/procurement/bills',
            '/admin/bundles/procurement/bills/new',
            '/admin/bundles/procurement/debit-memos',
            '/admin/bundles/procurement/exceptions',
            '/admin/bundles/procurement',
            // WarehouseOpsBundle
            '/admin/bundles/warehouse-ops/scan',
            '/admin/bundles/warehouse-ops/pick-lists',
            '/admin/bundles/warehouse-ops/transfers',
            '/admin/bundles/warehouse-ops/bin-map',
            '/admin/bundles/warehouse-ops/labels',
            '/admin/bundles/warehouse-ops/discrepancies',
            '/admin/bundles/warehouse-ops',
            // BarcodeBundle, which shares the Warehouse group with the bundle above
            '/admin/bundles/barcodes',
            // InventoryDepthBundle
            '/admin/bundles/inventory-depth/stock/location',
            '/admin/bundles/inventory-depth/stock/serial',
            '/admin/bundles/inventory-depth/lots',
            '/admin/bundles/inventory-depth/bins',
            '/admin/bundles/inventory-depth/cycle-count',
            '/admin/bundles/inventory-depth/movements',
            '/admin/bundles/inventory-depth/adjust',
            '/admin/bundles/inventory-depth/pack-conversion',
            '/admin/bundles/inventory-depth/low-stock',
            '/admin/bundles/inventory-depth/tracking',
            '/admin/bundles/inventory-depth',
        ] as $href) {
            $I->dontSeeElement('nav#primary-navigation a', ['href' => $href]);
        }
    }

    /** The firewall name and the Host header both matter — without either, the request lands on the login page with a 200. */
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('bundles-off-' . uniqid() . '@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }
}
