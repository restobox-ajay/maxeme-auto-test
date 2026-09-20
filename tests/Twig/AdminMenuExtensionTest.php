<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Contract\Menu\AdminMenuOverrideProviderInterface;
use App\Menu\Admin\AdminMenuCatalog;
use App\Menu\Admin\AdminMenuTreeBuilder;
use App\Repository\BundleStatusRepository;
use App\Twig\AdminMenuExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

/**
 * The detachability contract at the Twig layer: with no provider registered (or none active),
 * getTree()/admin_menu_tree() returns exactly the default catalog tree, unchanged — the merge
 * logic itself (hide/reorder/reparent/custom items, and every adversarial case) is covered
 * exhaustively by App\Tests\Menu\Admin\AdminMenuTreeBuilderTest; this file covers what the
 * extension itself adds on top: registering the Twig function and per-request caching.
 */
final class AdminMenuExtensionTest extends TestCase
{
    /**
     * AdminMenuCatalog::keys() with the catalog's six default-attached create entries removed —
     * each renders only as a `+` on the row it names now (queue item 35), not as its own tree
     * node, so flattenKeys() never includes them. Mirrors
     * App\Tests\Menu\Admin\AdminMenuTreeBuilderTest::ATTACHED_BY_DEFAULT/defaultFlattenedKeys(),
     * which is the authoritative list and where a newly-flagged key gets added first.
     */
    private function defaultFlattenedKeys(): array
    {
        return array_values(array_diff(AdminMenuCatalog::keys(), [
            'products.add', 'sales.customers_add', 'sales.quotes_create', 'sales.orders_create',
            'sales.invoices_create', 'sales.credit_notes_create',
        ]));
    }

    private function bundleStatusRepo(bool $active): BundleStatusRepository
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActive')->willReturn($active);

        return $repo;
    }

    private function provider(string $source = 'AdminMenuBundle'): AdminMenuOverrideProviderInterface
    {
        $provider = $this->createStub(AdminMenuOverrideProviderInterface::class);
        $provider->method('getSource')->willReturn($source);
        $provider->method('getHiddenKeys')->willReturn(['help']);
        $provider->method('getOrderOverrides')->willReturn([]);
        $provider->method('getParentOverrides')->willReturn([]);
        $provider->method('getCustomItems')->willReturn([]);

        return $provider;
    }

    public function testExposesTheAdminMenuTreeFunction(): void
    {
        $extension = new AdminMenuExtension([], new AdminMenuTreeBuilder($this->bundleStatusRepo(true)));

        $functions = $extension->getFunctions();
        self::assertCount(1, $functions);
        self::assertInstanceOf(TwigFunction::class, $functions[0]);
        self::assertSame('admin_menu_tree', $functions[0]->getName());
    }

    public function testWithNoProvidersRegisteredTheTreeIsExactlyTheDefaultCatalog(): void
    {
        $extension = new AdminMenuExtension([], new AdminMenuTreeBuilder($this->bundleStatusRepo(true)));

        self::assertSame($this->defaultFlattenedKeys(), AdminMenuTreeBuilder::flattenKeys($extension->getTree()));
    }

    public function testAnInactiveProvidersBundleLeavesTheTreeAtDefault(): void
    {
        $extension = new AdminMenuExtension([$this->provider()], new AdminMenuTreeBuilder($this->bundleStatusRepo(false)));

        self::assertSame($this->defaultFlattenedKeys(), AdminMenuTreeBuilder::flattenKeys($extension->getTree()));
    }

    public function testAnActiveProvidersOverridesApply(): void
    {
        $extension = new AdminMenuExtension([$this->provider()], new AdminMenuTreeBuilder($this->bundleStatusRepo(true)));

        self::assertNotContains('help', AdminMenuTreeBuilder::flattenKeys($extension->getTree()));
    }

    /**
     * The tree is built once and reused for the rest of the request — the layout asks for it
     * multiple times in one render (once for the loop, plus each node's own methods), and
     * building it costs a provider/BundleStatusRepository read per active provider.
     */
    public function testTheTreeIsBuiltOnceAndCachedUntilReset(): void
    {
        $extension = new AdminMenuExtension([], new AdminMenuTreeBuilder($this->bundleStatusRepo(true)));

        $first = $extension->getTree();
        $second = $extension->getTree();
        self::assertSame($first, $second, 'same array instance/content across calls before reset()');

        $extension->reset();
        $third = $extension->getTree();
        self::assertEquals($first, $third, 'content is still equivalent after a rebuild post-reset()');
    }
}
