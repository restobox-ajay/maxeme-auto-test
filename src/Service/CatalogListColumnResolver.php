<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\Bundle\CatalogListColumnProviderInterface;
use App\Repository\BundleStatusRepository;

final class CatalogListColumnResolver
{
    /** @param iterable<CatalogListColumnProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {}

    /** @return list<array{slug: string, label: string}> */
    public function columnsFor(string $point): array
    {
        return $this->winner($point)?->getColumns() ?? [];
    }

    private function winner(string $point): ?CatalogListColumnProviderInterface
    {
        $matching = [];
        foreach ($this->providers as $provider) {
            if ($provider->getPoint() === $point && $this->bundleStatusRepo->isActive($provider->getSource())) {
                $matching[] = $provider;
            }
        }

        if ($matching === []) {
            return null;
        }

        usort($matching, fn (CatalogListColumnProviderInterface $a, CatalogListColumnProviderInterface $b) => $b->getPriority() <=> $a->getPriority());

        return $matching[0];
    }
}
