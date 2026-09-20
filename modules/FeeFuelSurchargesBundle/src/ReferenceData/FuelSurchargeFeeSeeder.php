<?php

declare(strict_types=1);

namespace FeeFuelSurchargesBundle\ReferenceData;

use App\Service\ReferenceData\AbstractFeeCatalogueSeeder;
use FeeFuelSurchargesBundle\Fee\FuelSurchargeFeeCalculator;

/**
 * This bundle's shipped fee rows, seeded centrally instead of by its config screen's GET action.
 *
 * Everything worth saying is on {@see AbstractFeeCatalogueSeeder}; this states only the two facts
 * that are this bundle's — the definitions and the source — and the key its seed mark is recorded
 * under. Tagged `app.reference_data_seeder` by autoconfiguration, so nothing in core knows it
 * exists.
 */
final class FuelSurchargeFeeSeeder extends AbstractFeeCatalogueSeeder
{
    public function getKey(): string
    {
        return 'fee_fuel_surcharge.catalogue';
    }

    public function getLabel(): string
    {
        return 'Fuel surcharges';
    }

    protected function definitions(): array
    {
        return FuelSurchargeFeeCalculator::FEES;
    }

    protected function source(): string
    {
        return FuelSurchargeFeeCalculator::SOURCE;
    }
}
