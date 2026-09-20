<?php

declare(strict_types=1);

namespace AdminMenuBundle\Tests\Unit;

use AdminMenuBundle\Bundle\AdminMenuBundleDescriptor;
use AdminMenuBundle\Menu\AdminMenuOverrideProvider;
use PHPUnit\Framework\TestCase;

final class AdminMenuOverrideProviderTest extends TestCase
{
    /**
     * If these drift apart, App\Menu\Admin\AdminMenuTreeBuilder looks up the status of a bundle
     * that has no status row — and nothing fails loudly either way, which is why this test exists.
     *
     * What the silence looks like has inverted. `BundleStatusRepository::isActive()` used to answer
     * TRUE for a missing row, so a drift meant switching Admin Menu to Inactive quietly stopped
     * restoring the default sidebar. A missing row now answers FALSE, so a drift means the override
     * never applies at all — the bundle is Active, its sidebar is not, and no screen says why.
     */
    public function testTheProvidersSourceMatchesTheBundleDescriptors(): void
    {
        self::assertSame(
            (new AdminMenuBundleDescriptor())->getSource(),
            AdminMenuOverrideProvider::SOURCE,
        );
    }
}
