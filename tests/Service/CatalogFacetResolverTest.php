<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Contract\Bundle\CatalogFacetProviderInterface;
use App\Repository\BundleStatusRepository;
use App\Service\CatalogFacetResolver;
use PHPUnit\Framework\TestCase;

final class CatalogFacetResolverTest extends TestCase
{
    /** @param list<array{slug: string, label: string, advanced: bool}> $facets */
    private function provider(
        string $point,
        int $priority,
        string $source,
        array $facets,
    ): CatalogFacetProviderInterface {
        $provider = $this->createStub(CatalogFacetProviderInterface::class);
        $provider->method('getPoint')->willReturn($point);
        $provider->method('getPriority')->willReturn($priority);
        $provider->method('getSource')->willReturn($source);
        $provider->method('getFacets')->willReturn($facets);

        return $provider;
    }

    private function activeRepo(): BundleStatusRepository
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActive')->willReturn(true);

        return $repo;
    }

    public function testFacetsForReturnsEmptyWhenNoProviderMatchesPoint(): void
    {
        $resolver = new CatalogFacetResolver(
            [$this->provider('other.point', 0, 'OtherBundle', [['slug' => 'color', 'label' => 'Color', 'advanced' => false]])],
            $this->activeRepo(),
        );

        self::assertSame([], $resolver->facetsFor('customer_catalog_index:1'));
    }

    public function testFacetsForReturnsFacetsFromSoleMatchingProvider(): void
    {
        $facets = [['slug' => 'color', 'label' => 'Color', 'advanced' => false]];

        $resolver = new CatalogFacetResolver(
            [$this->provider('customer_catalog_index:1', 0, 'ColorBundle', $facets)],
            $this->activeRepo(),
        );

        self::assertSame($facets, $resolver->facetsFor('customer_catalog_index:1'));
    }

    public function testFacetsForUnionsAcrossMultipleMatchingProvidersInAscendingPriorityOrder(): void
    {
        $lowFacet = ['slug' => 'size', 'label' => 'Size', 'advanced' => false];
        $highFacet = ['slug' => 'material', 'label' => 'Material', 'advanced' => true];

        $resolver = new CatalogFacetResolver(
            [
                $this->provider('customer_catalog_index:1', 10, 'MaterialBundle', [$highFacet]),
                $this->provider('customer_catalog_index:1', 1, 'SizeBundle', [$lowFacet]),
            ],
            $this->activeRepo(),
        );

        self::assertSame([$lowFacet, $highFacet], $resolver->facetsFor('customer_catalog_index:1'));
    }

    public function testFacetsForSkipsProvidersFromInactiveBundles(): void
    {
        $activeFacet = ['slug' => 'color', 'label' => 'Color', 'advanced' => false];
        $inactiveFacet = ['slug' => 'material', 'label' => 'Material', 'advanced' => true];

        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActive')->willReturnMap([
            ['ActiveBundle', true],
            ['InactiveBundle', false],
        ]);

        $resolver = new CatalogFacetResolver(
            [
                $this->provider('customer_catalog_index:1', 0, 'ActiveBundle', [$activeFacet]),
                $this->provider('customer_catalog_index:1', 1, 'InactiveBundle', [$inactiveFacet]),
            ],
            $repo,
        );

        self::assertSame([$activeFacet], $resolver->facetsFor('customer_catalog_index:1'));
    }

    public function testFacetsForFlattensMultipleFacetsFromSameProvider(): void
    {
        $facets = [
            ['slug' => 'color', 'label' => 'Color', 'advanced' => false],
            ['slug' => 'material', 'label' => 'Material', 'advanced' => true],
        ];

        $resolver = new CatalogFacetResolver(
            [$this->provider('customer_catalog_index:1', 0, 'CatalogBundle', $facets)],
            $this->activeRepo(),
        );

        self::assertSame($facets, $resolver->facetsFor('customer_catalog_index:1'));
    }
}
