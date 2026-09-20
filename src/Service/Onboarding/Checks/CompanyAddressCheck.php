<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

final class CompanyAddressCheck extends AbstractSettingsFilledCheck
{
    protected function group(): string
    {
        return 'Company Profile';
    }

    protected function label(): string
    {
        return 'Company Address, City, Country & Postal Code';
    }

    protected function key(): string
    {
        return 'company_address';
    }

    protected function sortOrder(): int
    {
        return 30;
    }

    protected function fields(): array
    {
        return [
            'company_address' => 'Company Address',
            'company_city' => 'Company City',
            'company_country' => 'Company Country',
            'company_postal_code' => 'Company Postal Code',
        ];
    }

    protected function whereToFix(): string
    {
        return 'Set under Settings > Company Information.';
    }
}
