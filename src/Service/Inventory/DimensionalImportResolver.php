<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Contract\Inventory\DimensionalImportProviderInterface;
use App\Repository\BundleStatusRepository;

/**
 * Whether the advanced import mode exists right now, and who implements it (#565).
 *
 * Mirrors InventoryModeResolver exactly, for the same reason: core owns the file and the columns
 * and does not own bins, so it asks rather than knows.
 *
 * Absent or Inactive means the whole advanced mode does not exist — the import screen has no
 * advanced columns, a file that happens to carry them has them **ignored rather than rejected**,
 * and there are no sentinels, no negative row and no per-product refusal. Someone uploading last
 * month's advanced file to an instance with the bundle switched off gets the plain import, not an
 * error.
 */
final class DimensionalImportResolver
{
    /** @param iterable<DimensionalImportProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatuses,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->activeProvider() !== null;
    }

    /**
     * The advanced column names, or none at all when the mode does not exist.
     *
     * @return list<string>
     */
    public function advancedColumns(): array
    {
        return $this->activeProvider()?->advancedColumns() ?? [];
    }

    public function activeProvider(): ?DimensionalImportProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof DimensionalImportProviderInterface
                && $this->bundleStatuses->isActive($provider->getSource())
            ) {
                return $provider;
            }
        }

        return null;
    }
}
