<?php

declare(strict_types=1);

namespace BarcodeBundle\Tests\Unit;

use App\Contract\Menu\AdminMenuOverrideProviderInterface;
use App\Repository\BundleStatusRepository;
use BarcodeBundle\Barcode\BarcodeDirectory;
use BarcodeBundle\Bundle\BarcodeBundleDescriptor;
use BarcodeBundle\Entity\ProductBarcode;
use BarcodeBundle\Menu\BarcodeMenuOverrideProvider;
use PHPUnit\Framework\TestCase;

/**
 * The structural facts that make this bundle behave like every other one in the repo.
 */
final class BarcodePackagingTest extends TestCase
{
    /** @return array<string, mixed> */
    private function menuItem(string $key): array
    {
        foreach ((new BarcodeMenuOverrideProvider())->getCustomItems() as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        self::fail(sprintf('the sidebar provider contributes no "%s" entry', $key));
    }

    /** House convention: the sidebar link and App Management's Configure button go to the same place. */
    public function testTheSidebarRouteMatchesTheDescriptorsEditRoute(): void
    {
        self::assertSame(
            (new BarcodeBundleDescriptor())->getEditRoute(),
            $this->menuItem('warehouse.barcodes')['route'],
        );
    }

    /**
     * The provider names its own bundle. App\Menu\Admin\AdminMenuTreeBuilder skips a provider
     * whose getSource() is not active per BundleStatusRepository, so a source that disagreed with
     * the descriptor's would leave this entry in the sidebar with the bundle switched off.
     */
    public function testTheSidebarProviderNamesTheSameSourceTheDescriptorDoes(): void
    {
        $provider = new BarcodeMenuOverrideProvider();

        self::assertInstanceOf(AdminMenuOverrideProviderInterface::class, $provider);
        self::assertSame((new BarcodeBundleDescriptor())->getSource(), $provider->getSource());
    }

    /**
     * `isActiveForInstance()` derives the source from the root namespace segment, so a descriptor
     * whose getSource() disagreed with its own namespace would make the Active/Inactive kill-switch
     * silently miss every seam this bundle registers.
     */
    public function testTheDescriptorSourceMatchesTheNamespaceRoot(): void
    {
        $descriptor = new BarcodeBundleDescriptor();

        self::assertSame(
            substr($descriptor::class, 0, strpos($descriptor::class, '\\') ?: 0),
            $descriptor->getSource(),
        );
    }

    /**
     * BarcodeDirectory checks the switch by name rather than by instance, because the thing it is
     * asking about is the bundle rather than itself. The name still has to be the real one.
     */
    public function testTheDirectoryChecksTheSameSourceTheDescriptorDeclares(): void
    {
        self::assertSame((new BarcodeBundleDescriptor())->getSource(), BarcodeDirectory::SOURCE);
        self::assertSame(BarcodeDirectory::SOURCE, BundleStatusRepository::sourceFromClass(BarcodeDirectory::class));
    }

    /**
     * The heading these screens share is now a real top-level GROUP rather than a
     * `nav-sub-section` label inside Apps, and it is SHARED with WarehouseOpsBundle, which declares
     * an identical spec of its own. The two are pinned to the same literal values here and in that
     * bundle's own test rather than compared across the two, so deleting either bundle cannot
     * break the other's tests — and declaring it in BOTH is what keeps Barcodes under a Warehouse
     * group when Warehouse Operations is switched off on its own, instead of becoming a stray
     * top-level link.
     *
     * Order -750 is the negated #632 tag priority: between Labels (-760) and Discrepancies (-740).
     */
    public function testItLandsUnderTheWarehouseGroupItsScreensShare(): void
    {
        self::assertSame([
            'key' => 'warehouse',
            'label' => 'Warehouse',
            'url' => '',
            'parent' => null,
            'order' => 250,
            'group' => true,
            'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V8l9-5 9 5v13"/><path d="M9 21v-6h6v6"/></svg>',
            'routePrefixes' => ['admin_bundle_warehouse_ops_', 'admin_bundle_barcodes'],
        ], BarcodeMenuOverrideProvider::WAREHOUSE_GROUP);

        self::assertSame(BarcodeMenuOverrideProvider::WAREHOUSE_GROUP, $this->menuItem('warehouse'));
        self::assertSame('warehouse', $this->menuItem('warehouse.barcodes')['parent']);
        self::assertSame(-750, $this->menuItem('warehouse.barcodes')['order']);
    }

    /**
     * The kinds are a closed set stated in one place. An unknown kind falls back to `internal`
     * rather than being stored, because a kind nothing recognises would silently opt out of the
     * check-digit rule that makes a GTIN worth verifying.
     */
    public function testAnUnknownKindFallsBackToInternalRatherThanBeingStored(): void
    {
        $barcode = (new ProductBarcode())->setKind('made-up');

        self::assertSame(ProductBarcode::KIND_INTERNAL, $barcode->getKind());
        self::assertContains($barcode->getKind(), ProductBarcode::kinds());
    }

    /** Every kind has a label on the form, so a new kind cannot appear as a bare enum string. */
    public function testEveryKindHasSomethingAPersonCanRead(): void
    {
        foreach (ProductBarcode::kinds() as $kind) {
            self::assertArrayHasKey($kind, ProductBarcode::kindLabels());
            self::assertNotSame($kind, ProductBarcode::describeKind($kind));
        }
    }

    /** A code is trimmed and otherwise stored exactly as printed — case and punctuation included. */
    public function testACodeIsTrimmedAndNotOtherwiseRewritten(): void
    {
        $barcode = (new ProductBarcode())->setCode('  ns-4471/b  ');

        self::assertSame('ns-4471/b', $barcode->getCode());
    }

    public function testABlankPartyIsStoredAsNullRatherThanAnEmptyString(): void
    {
        self::assertNull((new ProductBarcode())->setParty('   ')->getParty());
        self::assertNull((new ProductBarcode())->setParty(null)->getParty());
        self::assertSame('Northern Supply', (new ProductBarcode())->setParty(' Northern Supply ')->getParty());
    }
}
