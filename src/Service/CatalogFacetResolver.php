<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\Bundle\CatalogFacetProviderInterface;
use App\Repository\BundleStatusRepository;

final class CatalogFacetResolver
{
    /** @param iterable<CatalogFacetProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {}

    /** @return list<array{slug: string, label: string, advanced: bool}> */
    public function facetsFor(string $point): array
    {
        $matching = [];
        foreach ($this->providers as $provider) {
            if ($provider->getPoint() === $point && $this->bundleStatusRepo->isActive($provider->getSource())) {
                $matching[] = $provider;
            }
        }

        usort($matching, fn (CatalogFacetProviderInterface $a, CatalogFacetProviderInterface $b) => $a->getPriority() <=> $b->getPriority());

        $facets = [];
        foreach ($matching as $provider) {
            foreach ($provider->getFacets() as $facet) {
                $facets[] = $facet;
            }
        }

        return $facets;
    }
}
