<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\Bundle\CatalogViewConfigProviderInterface;
use App\Repository\BundleStatusRepository;

final class CatalogViewConfigResolver
{
    /** @var array{gridAvailable: bool, listAvailable: bool, defaultView: string} */
    private const DEFAULT_CONFIG = [
        'gridAvailable' => true,
        'listAvailable' => true,
        'defaultView' => 'GRID_VIEW',
    ];

    /** @param iterable<CatalogViewConfigProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {}

    /** @return array{gridAvailable: bool, listAvailable: bool, defaultView: string} */
    public function resolve(string $point): array
    {
        return $this->winner($point)?->getViewConfig() ?? self::DEFAULT_CONFIG;
    }

    private function winner(string $point): ?CatalogViewConfigProviderInterface
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

        usort($matching, fn (CatalogViewConfigProviderInterface $a, CatalogViewConfigProviderInterface $b) => $b->getPriority() <=> $a->getPriority());

        return $matching[0];
    }
}
