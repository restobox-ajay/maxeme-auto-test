<?php

declare(strict_types=1);

namespace CustomHeaderFooterBundle\Tests\Unit;

use CustomHeaderFooterBundle\Bundle\CustomHeaderFooterBundleDescriptor;
use CustomHeaderFooterBundle\Hook\CustomFooterProvider;
use CustomHeaderFooterBundle\Hook\CustomHeaderProvider;
use CustomHeaderFooterBundle\Tests\Support\CreatesInMemoryStore;
use PHPUnit\Framework\TestCase;

final class CustomHeaderFooterBundleDescriptorTest extends TestCase
{
    use CreatesInMemoryStore;

    public function testSourceMatchesBothInjectionPointProvidersSoTheKillSwitchAppliesToBoth(): void
    {
        [$store] = $this->createStore();
        $descriptor = new CustomHeaderFooterBundleDescriptor();

        self::assertSame($descriptor->getSource(), (new CustomHeaderProvider($store))->getSource());
        self::assertSame($descriptor->getSource(), (new CustomFooterProvider($store))->getSource());
        self::assertSame('CustomHeaderFooterBundle', $descriptor->getSource());
    }

    public function testEditRouteIsSetSoItAppearsAsConfigurableInBundleManagement(): void
    {
        $descriptor = new CustomHeaderFooterBundleDescriptor();

        self::assertSame('admin_bundle_custom_header_footer_index', $descriptor->getEditRoute());
    }

    public function testExposesAHumanReadableNameAndType(): void
    {
        $descriptor = new CustomHeaderFooterBundleDescriptor();

        self::assertNotSame('', $descriptor->getName());
        self::assertSame('Injection Point', $descriptor->getType());
    }

    public function testDocsUrlPointsAtThisBundlesReadme(): void
    {
        $descriptor = new CustomHeaderFooterBundleDescriptor();

        self::assertStringContainsString('modules/CustomHeaderFooterBundle/README.md', (string) $descriptor->getDocsUrl());
    }
}
