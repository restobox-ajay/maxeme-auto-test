<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Unit;

use App\Contract\Menu\AdminMenuOverrideProviderInterface;
use ProcurementBundle\Bundle\ProcurementBundleDescriptor;
use ProcurementBundle\Menu\ProcurementMenuOverrideProvider;
use PHPUnit\Framework\TestCase;
use ProcurementBundle\Enum\PurchaseOrderStatus;
use ProcurementBundle\Enum\VendorBillStatus;

/**
 * The packaging rules this bundle is built on (#555), pinned so they cannot drift silently.
 *
 * The one that matters most is the last: **core reads nothing this bundle writes.** That is what
 * makes the bundle removable, and it is a property of the whole repository rather than of any file
 * in it — so it is checked by grepping core for the table names, which is the only way to notice
 * the day somebody adds a join.
 */
final class ProcurementPackagingTest extends TestCase
{
    /** @return array<string, mixed> */
    private function menuItem(string $key): array
    {
        foreach ((new ProcurementMenuOverrideProvider())->getCustomItems() as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        self::fail(sprintf('the sidebar provider contributes no "%s" entry', $key));
    }

    /**
     * House convention: the sidebar link and App Management's Configure button go to the same
     * place. The hub is the Configure target (ProcurementBundleDescriptor::getEditRoute), so it
     * keeps a sidebar line of its own — at the foot of the Purchases group since the screens
     * became a tree instead of a flat injection-point list.
     */
    public function testRouteMatchesTheBundleDescriptorsEditRoute(): void
    {
        self::assertSame(
            (new ProcurementBundleDescriptor())->getEditRoute(),
            $this->menuItem('purchases.home')['route'],
        );
        self::assertSame('purchases', $this->menuItem('purchases.home')['parent']);
    }

    /**
     * The provider names its own bundle. App\Menu\Admin\AdminMenuTreeBuilder skips a provider whose
     * getSource() is not active per BundleStatusRepository, so a source that disagreed with the
     * descriptor's would leave every one of its entries in the sidebar with the bundle switched
     * off. (It said "all fourteen" while there were fifteen, and queue item 56's removal of the
     * RFQ row made that stale sentence accidentally true — a hand-kept count in a comment is the
     * same hazard as one in a test, so it is gone rather than corrected.)
     */
    public function testTheSidebarProviderNamesTheSameSourceTheDescriptorDoes(): void
    {
        $provider = new ProcurementMenuOverrideProvider();

        self::assertInstanceOf(AdminMenuOverrideProviderInterface::class, $provider);
        self::assertSame((new ProcurementBundleDescriptor())->getSource(), $provider->getSource());
    }

    /**
     * The two groups are TOP-LEVEL groups, not links and not children of anything: `group => true`
     * with no route is what makes App\Menu\Admin\AdminMenuTreeBuilder keep `custom === false`, and
     * that is the only thing templates/admin/_main/layout.html.twig renders as a `nav-group` with
     * a children list. Drop the flag and both sections silently become dead top-level links.
     */
    public function testVendorsAndPurchasesAreTopLevelGroups(): void
    {
        foreach (['vendors' => 'Vendors', 'purchases' => 'Purchases'] as $key => $label) {
            $group = $this->menuItem($key);

            self::assertSame($label, $group['label']);
            self::assertTrue($group['group']);
            self::assertNull($group['parent']);
            self::assertArrayNotHasKey('route', $group);
            self::assertNotSame([], $group['routePrefixes']);
        }
    }

    /**
     * Every screen entry is a child of one of the two groups and carries a route, and the groups
     * are declared before their children — App\Menu\Admin\AdminMenuTreeBuilder validates a custom
     * item's parent against the nodes merged SO FAR, so a child listed first would quietly land at
     * the top level instead of failing.
     */
    public function testEveryScreenIsAChildOfAGroupDeclaredBeforeIt(): void
    {
        $seen = [];

        foreach ((new ProcurementMenuOverrideProvider())->getCustomItems() as $item) {
            if (($item['group'] ?? false) === true) {
                $seen[$item['key']] = true;
                continue;
            }

            self::assertArrayHasKey('route', $item, $item['key'] . ' has no route');
            self::assertArrayHasKey($item['parent'], $seen, $item['key'] . ' names a parent not declared before it');
        }

        self::assertSame(['warehouse', 'vendors', 'purchases'], array_keys($seen));
    }

    /**
     * `isActiveForInstance()` derives the source from the root namespace segment, so a descriptor
     * whose getSource() disagrees with its own namespace would make the Active/Inactive kill-switch
     * silently miss every seam this bundle registers.
     */
    public function testTheDescriptorSourceMatchesTheNamespaceRoot(): void
    {
        $descriptor = new ProcurementBundleDescriptor();

        self::assertSame(
            substr($descriptor::class, 0, strpos($descriptor::class, '\\') ?: 0),
            $descriptor->getSource(),
        );
    }

    /**
     * Core reads nothing this bundle writes.
     *
     * The rule the plan states and the reason this can be deleted: remove it and stock still enters
     * the building through the adjustment screen, with no precondition and nothing to migrate. The
     * one thing that would break it is valuation — a receipt's cost feeding the product's cost —
     * which is why that is explicitly not in this job.
     *
     * Checked by grep because it cannot be checked any other way: a single `JOIN vendor_bill` added
     * to a core query would break the rule with every unit test still green.
     */
    public function testCoreDoesNotReadAnyProcurementTable(): void
    {
        $tables = [
            'vendor',
            'vendor_address',
            // #605's two new tables. They are the bundle's own master data and core must be as blind
            // to them as to the rest — in particular, `vendor_contact` must never be reachable from
            // anything in `src/`, which is where every security decision this app makes lives.
            'vendor_contact',
            'vendor_note',
            'purchase_order',
            'purchase_order_line',
            'purchase_order_log',
            'goods_receipt',
            'goods_receipt_line',
            'vendor_bill',
            'vendor_bill_line',
            'vendor_bill_log',
            'procurement_product_rule',
        ];

        $coreDir = \dirname(__DIR__, 4) . '/src';
        self::assertDirectoryExists($coreDir);

        $offenders = [];

        foreach ($this->phpFilesIn($coreDir) as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($tables as $table) {
                // Word-boundary match against SQL-ish usage only. `vendor` is a common English word
                // and appears in core prose and in the `vendor/` path, so the pattern requires it to
                // be sitting where a table name sits: after FROM, JOIN, INTO or UPDATE.
                if (preg_match('/\b(?:FROM|JOIN|INTO|UPDATE)\s+' . preg_quote($table, '/') . '\b/i', $contents) === 1) {
                    $offenders[] = sprintf('%s reads %s', basename($file), $table);
                }
            }

            if (str_contains($contents, 'ProcurementBundle\\')) {
                $offenders[] = sprintf('%s references the ProcurementBundle namespace', basename($file));
            }
        }

        self::assertSame([], $offenders, 'core must not read anything the procurement bundle writes');
    }

    /**
     * Which statuses are decided and which are derived.
     *
     * Pinned because the split is the whole design: a status that quietly became derivable would
     * let the deriver overwrite somebody's decision — most expensively, a purchase order closed
     * short springing back to Received when the goods finally turn up.
     */
    public function testOnlyTheProjectedPurchaseOrderStatusesAreDerivable(): void
    {
        $derivable = array_values(array_filter(
            PurchaseOrderStatus::cases(),
            static fn (PurchaseOrderStatus $s): bool => $s->isDerivable(),
        ));

        self::assertSame(
            [PurchaseOrderStatus::Issued, PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Received],
            $derivable,
        );
    }

    public function testOnlyTheMoneyDrivenBillStatusesAreDerivable(): void
    {
        $derivable = array_values(array_filter(
            VendorBillStatus::cases(),
            static fn (VendorBillStatus $s): bool => $s->isDerivable(),
        ));

        self::assertSame(
            [VendorBillStatus::Open, VendorBillStatus::PartiallyPaid, VendorBillStatus::Paid],
            $derivable,
        );
    }

    /**
     * Only Draft and Cancelled refuse goods.
     *
     * Closed deliberately does not: a written-off remainder that turns up late is recorded against
     * the line it belongs to, and the deriver leaves the Closed status alone. See
     * PurchaseOrderStatus::refusesReceipts().
     */
    public function testOnlyDraftAndCancelledRefuseGoods(): void
    {
        $refusing = array_values(array_filter(
            PurchaseOrderStatus::cases(),
            static fn (PurchaseOrderStatus $s): bool => $s->refusesReceipts(),
        ));

        self::assertSame([PurchaseOrderStatus::Draft, PurchaseOrderStatus::Cancelled], $refusing);
    }

    /** @return list<string> */
    private function phpFilesIn(string $dir): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
