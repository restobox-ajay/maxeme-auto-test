<?php

declare(strict_types=1);

namespace FeeONFoodBundle\ReferenceData;

use App\Service\ReferenceData\AbstractFeeCatalogueSeeder;
use FeeONFoodBundle\Fee\ONFoodFeeCalculator;

/**
 * This bundle's shipped fee rows, seeded centrally instead of by its config screen's GET action.
 *
 * Everything worth saying is on {@see AbstractFeeCatalogueSeeder}; this states only the two facts
 * that are this bundle's — the definitions and the source — and the key its seed mark is recorded
 * under. Tagged `app.reference_data_seeder` by autoconfiguration, so nothing in core knows it
 * exists.
 */
final class ONFoodFeeSeeder extends AbstractFeeCatalogueSeeder
{
    public function getKey(): string
    {
        return 'fee_on_food.catalogue';
    }

    public function getLabel(): string
    {
        return 'Ontario food fees';
    }

    protected function definitions(): array
    {
        return ONFoodFeeCalculator::FEES;
    }

    protected function source(): string
    {
        return ONFoodFeeCalculator::SOURCE;
    }
}
