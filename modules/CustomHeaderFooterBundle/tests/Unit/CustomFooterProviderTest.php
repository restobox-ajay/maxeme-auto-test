<?php

declare(strict_types=1);

namespace CustomHeaderFooterBundle\Tests\Unit;

use CustomHeaderFooterBundle\Hook\CustomFooterProvider;
use CustomHeaderFooterBundle\Tests\Support\CreatesInMemoryStore;
use PHPUnit\Framework\TestCase;

final class CustomFooterProviderTest extends TestCase
{
    use CreatesInMemoryStore;

    public function testTargetsTheCustomerBodyEndInjectionPoint(): void
    {
        [$store] = $this->createStore();
        $provider = new CustomFooterProvider($store);

        self::assertSame('customer_body_end', $provider->getPoint());
    }

    public function testSourceMatchesTheBundleDescriptorSoTheKillSwitchLinesUp(): void
    {
        [$store] = $this->createStore();
        $provider = new CustomFooterProvider($store);

        self::assertSame('CustomHeaderFooterBundle', $provider->getSource());
    }

    public function testRenderReturnsWhateverIsCurrentlySavedInTheStore(): void
    {
        [$store] = $this->createStore();
        $store->saveFooterHtml('<script src="/custom.js"></script>');
        $provider = new CustomFooterProvider($store);

        self::assertSame('<script src="/custom.js"></script>', $provider->render([]));
    }

    public function testRenderIsEmptyWhenNothingHasBeenSaved(): void
    {
        [$store] = $this->createStore();
        $provider = new CustomFooterProvider($store);

        self::assertSame('', $provider->render([]));
    }

    public function testHeaderAndFooterProvidersReadIndependentValues(): void
    {
        [$store] = $this->createStore();
        $store->saveHeaderHtml('<meta name="header-only">');
        $footerProvider = new CustomFooterProvider($store);

        self::assertSame('', $footerProvider->render([]));
    }
}
