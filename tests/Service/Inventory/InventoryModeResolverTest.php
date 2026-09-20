<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Contract\Inventory\DimensionalInventoryProviderInterface;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\Inventory\InventoryModeResolver;
use PHPUnit\Framework\TestCase;

/**
 * The bundle-removal guarantee, asserted from core's side (#550).
 *
 * "Removing the bundle loses the breakdown, not the number" is only true if core, with no provider
 * registered, treats every product as `simple` — including one whose `inventory_mode` column still
 * says `dimensional` from before the bundle was deleted. Otherwise the column is a lockout: a
 * product whose quantity nobody types and nobody maintains.
 *
 * modules/InventoryDepthBundle is present in the test kernel, so the no-provider case cannot be
 * observed through the container. Hence a plain unit test over the resolver's collaborators.
 */
final class InventoryModeResolverTest extends TestCase
{
    private function bundleStatus(bool $active): BundleStatusRepository
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActive')->willReturn($active);

        return $repo;
    }

    private function provider(?string $url = 'https://admin.example/breakdown'): DimensionalInventoryProviderInterface
    {
        $provider = $this->createStub(DimensionalInventoryProviderInterface::class);
        $provider->method('getSource')->willReturn('InventoryDepthBundle');
        $provider->method('breakdownUrl')->willReturn($url);

        return $provider;
    }

    private function dimensionalProduct(): ProductCore
    {
        return (new ProductCore())
            ->setSku('D-1')
            ->setName('Dimensional')
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
    }

    /** The whole safety property, in one assertion: no provider means no dimensional products. */
    public function testWithNoProviderEvenAStoredDimensionalProductReadsAsSimple(): void
    {
        $resolver = new InventoryModeResolver([], $this->bundleStatus(true));
        $product = $this->dimensionalProduct();

        self::assertFalse($resolver->isDimensionalAvailable());
        self::assertFalse($resolver->isDimensional($product), 'the column must never be a lockout');
        self::assertNull($resolver->breakdownUrl($product));
    }

    /** The App Management kill-switch reaches this seam like every other. */
    public function testAnInactiveBundlesProviderIsIgnored(): void
    {
        $resolver = new InventoryModeResolver([$this->provider()], $this->bundleStatus(false));

        self::assertFalse($resolver->isDimensionalAvailable());
        self::assertFalse($resolver->isDimensional($this->dimensionalProduct()));
    }

    /** isDimensional() is the conjunction of BOTH halves, never either alone. */
    public function testASimpleProductIsNeverDimensionalEvenWithALiveProvider(): void
    {
        $resolver = new InventoryModeResolver([$this->provider()], $this->bundleStatus(true));

        $simple = (new ProductCore())->setSku('S-1')->setName('Simple');

        self::assertTrue($resolver->isDimensionalAvailable());
        self::assertFalse($resolver->isDimensional($simple));
        self::assertNull($resolver->breakdownUrl($simple), 'a simple product has no breakdown to link to');
    }

    public function testALiveProviderMakesTheProductDimensionalAndSuppliesTheLink(): void
    {
        $resolver = new InventoryModeResolver([$this->provider()], $this->bundleStatus(true));
        $product = $this->dimensionalProduct();

        self::assertTrue($resolver->isDimensional($product));
        self::assertSame('https://admin.example/breakdown', $resolver->breakdownUrl($product, new Warehouse()));
    }

    /**
     * With nothing able to maintain a breakdown, quietly flipping the column to `dimensional` would
     * leave a product pointing at a layer that does not exist. `simple` needs nothing installed, so
     * it is always allowed.
     */
    public function testWithNoProviderOnlyTheSwitchBackToSimpleIsAllowed(): void
    {
        $resolver = new InventoryModeResolver([], $this->bundleStatus(true));
        $product = $this->dimensionalProduct();

        self::assertFalse($resolver->switchMode($product, ProductCore::INVENTORY_MODE_DIMENSIONAL));
        self::assertSame(ProductCore::INVENTORY_MODE_DIMENSIONAL, $product->getInventoryMode(), 'nothing was written');

        self::assertTrue($resolver->switchMode($product, ProductCore::INVENTORY_MODE_SIMPLE));
        self::assertSame(ProductCore::INVENTORY_MODE_SIMPLE, $product->getInventoryMode());
    }

    /** Anything unrecognised falls back to the mode that means "nothing changes". */
    public function testAnUnknownModeFallsBackToSimple(): void
    {
        $product = (new ProductCore())->setInventoryMode('teleportation');

        self::assertSame(ProductCore::INVENTORY_MODE_SIMPLE, $product->getInventoryMode());
    }

    /** Every product ever created is on the mode that needs nothing installed. */
    public function testANewProductDefaultsToSimple(): void
    {
        self::assertSame(ProductCore::INVENTORY_MODE_SIMPLE, (new ProductCore())->getInventoryMode());
    }
}
