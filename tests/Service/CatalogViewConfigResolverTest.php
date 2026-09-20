<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Contract\Bundle\CatalogViewConfigProviderInterface;
use App\Repository\BundleStatusRepository;
use App\Service\CatalogViewConfigResolver;
use PHPUnit\Framework\TestCase;

final class CatalogViewConfigResolverTest extends TestCase
{
    /** @param array{gridAvailable: bool, listAvailable: bool, defaultView: string} $viewConfig */
    private function provider(
        string $point,
        int $priority,
        string $source,
        array $viewConfig,
    ): CatalogViewConfigProviderInterface {
        $provider = $this->createStub(CatalogViewConfigProviderInterface::class);
        $provider->method('getPoint')->willReturn($point);
        $provider->method('getPriority')->willReturn($priority);
        $provider->method('getSource')->willReturn($source);
        $provider->method('getViewConfig')->willReturn($viewConfig);

        return $provider;
    }

    private function activeRepo(): BundleStatusRepository
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActive')->willReturn(true);

        return $repo;
    }

    public function testResolveReturnsDefaultConfigWhenNoProviderMatchesPoint(): void
    {
        $resolver = new CatalogViewConfigResolver(
            [$this->provider('other.point', 0, 'OtherBundle', ['gridAvailable' => false, 'listAvailable' => true, 'defaultView' => 'LIST_VIEW'])],
            $this->activeRepo(),
        );

        self::assertSame(
            ['gridAvailable' => true, 'listAvailable' => true, 'defaultView' => 'GRID_VIEW'],
            $resolver->resolve('customer_catalog_index:1'),
        );
    }

    public function testResolveReturnsSoleMatchingProviderConfig(): void
    {
        $config = ['gridAvailable' => false, 'listAvailable' => true, 'defaultView' => 'LIST_VIEW'];

        $resolver = new CatalogViewConfigResolver(
            [$this->provider('customer_catalog_index:1', 0, 'ViewBundle', $config)],
            $this->activeRepo(),
        );

        self::assertSame($config, $resolver->resolve('customer_catalog_index:1'));
    }

    public function testResolvePicksHighestPriorityAmongMatchingProviders(): void
    {
        $lowConfig = ['gridAvailable' => true, 'listAvailable' => true, 'defaultView' => 'GRID_VIEW'];
        $highConfig = ['gridAvailable' => false, 'listAvailable' => true, 'defaultView' => 'LIST_VIEW'];
        $midConfig = ['gridAvailable' => true, 'listAvailable' => false, 'defaultView' => 'GRID_VIEW'];

        $resolver = new CatalogViewConfigResolver(
            [
                $this->provider('customer_catalog_index:1', 1, 'LowBundle', $lowConfig),
                $this->provider('customer_catalog_index:1', 10, 'HighBundle', $highConfig),
                $this->provider('customer_catalog_index:1', 5, 'MidBundle', $midConfig),
            ],
            $this->activeRepo(),
        );

        self::assertSame($highConfig, $resolver->resolve('customer_catalog_index:1'));
    }

    public function testResolveSkipsProvidersFromInactiveBundles(): void
    {
        $activeConfig = ['gridAvailable' => true, 'listAvailable' => false, 'defaultView' => 'GRID_VIEW'];
        $inactiveConfig = ['gridAvailable' => false, 'listAvailable' => true, 'defaultView' => 'LIST_VIEW'];

        $activeProvider = $this->provider('customer_catalog_index:1', 1, 'ActiveBundle', $activeConfig);
        $inactiveProvider = $this->provider('customer_catalog_index:1', 100, 'InactiveBundle', $inactiveConfig);

        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActive')->willReturnMap([
            ['ActiveBundle', true],
            ['InactiveBundle', false],
        ]);

        $resolver = new CatalogViewConfigResolver([$activeProvider, $inactiveProvider], $repo);

        self::assertSame($activeConfig, $resolver->resolve('customer_catalog_index:1'));
    }

    public function testResolveReturnsDefaultConfigWhenProviderListIsEmpty(): void
    {
        $resolver = new CatalogViewConfigResolver([], $this->activeRepo());

        self::assertSame(
            ['gridAvailable' => true, 'listAvailable' => true, 'defaultView' => 'GRID_VIEW'],
            $resolver->resolve('customer_catalog_index:1'),
        );
    }
}
