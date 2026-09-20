<?php

declare(strict_types=1);

namespace CustomHeaderFooterBundle\Tests\Unit;

use CustomHeaderFooterBundle\Hook\CustomHeaderProvider;
use CustomHeaderFooterBundle\Tests\Support\CreatesInMemoryStore;
use PHPUnit\Framework\TestCase;

final class CustomHeaderProviderTest extends TestCase
{
    use CreatesInMemoryStore;

    public function testTargetsTheCustomerHeadTopInjectionPoint(): void
    {
        [$store] = $this->createStore();
        $provider = new CustomHeaderProvider($store);

        self::assertSame('customer_head_top', $provider->getPoint());
    }

    public function testSourceMatchesTheBundleDescriptorSoTheKillSwitchLinesUp(): void
    {
        [$store] = $this->createStore();
        $provider = new CustomHeaderProvider($store);

        self::assertSame('CustomHeaderFooterBundle', $provider->getSource());
    }

    public function testRenderReturnsWhateverIsCurrentlySavedInTheStore(): void
    {
        [$store] = $this->createStore();
        $store->saveHeaderHtml('<link rel="stylesheet" href="/custom.css">');
        $provider = new CustomHeaderProvider($store);

        self::assertSame('<link rel="stylesheet" href="/custom.css">', $provider->render([]));
    }

    public function testRenderIsEmptyWhenNothingHasBeenSaved(): void
    {
        [$store] = $this->createStore();
        $provider = new CustomHeaderProvider($store);

        self::assertSame('', $provider->render([]));
    }

    public function testRenderIgnoresTheContextArrayItIsGivenSinceHeaderHtmlIsGlobal(): void
    {
        [$store] = $this->createStore();
        $store->saveHeaderHtml('<meta name="global">');
        $provider = new CustomHeaderProvider($store);

        self::assertSame('<meta name="global">', $provider->render(['product' => new \stdClass()]));
    }
}
