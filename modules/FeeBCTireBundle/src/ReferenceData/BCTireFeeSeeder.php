<?php

declare(strict_types=1);

namespace FeeBCTireBundle\ReferenceData;

use App\Service\ReferenceData\AbstractFeeCatalogueSeeder;
use FeeBCTireBundle\Fee\BCTireFeeCalculator;

/**
 * This bundle's shipped fee rows, seeded centrally instead of by its config screen's GET action.
 *
 * Everything worth saying is on {@see AbstractFeeCatalogueSeeder}; this states only the two facts
 * that are this bundle's — the definitions and the source — and the key its seed mark is recorded
 * under. Tagged `app.reference_data_seeder` by autoconfiguration, so nothing in core knows it
 * exists.
 */
final class BCTireFeeSeeder extends AbstractFeeCatalogueSeeder
{
    public function getKey(): string
    {
        return 'fee_bc_tire.catalogue';
    }

    public function getLabel(): string
    {
        return 'BC tire stewardship fees';
    }

    protected function definitions(): array
    {
        return BCTireFeeCalculator::FEES;
    }

    protected function source(): string
    {
        return BCTireFeeCalculator::SOURCE;
    }
}
