<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\Cart\CartInfoField;
use App\Contract\Cart\CartInfoFieldInterface;
use App\Entity\Company;
use App\Repository\BundleStatusRepository;

final class CartInfoFieldResolver
{
    /** @param iterable<CartInfoFieldInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {}

    /** @return CartInfoField[] */
    public function getFields(Company $company): array
    {
        $fields = [];
        foreach ($this->providers as $provider) {
            if (!$this->bundleStatusRepo->isActiveForInstance($provider)) {
                continue;
            }

            foreach ($provider->getFields($company) as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
