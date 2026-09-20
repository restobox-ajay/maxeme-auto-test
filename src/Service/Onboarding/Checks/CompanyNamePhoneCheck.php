<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

final class CompanyNamePhoneCheck extends AbstractSettingsFilledCheck
{
    protected function group(): string
    {
        return 'Company Profile';
    }

    protected function label(): string
    {
        return 'Company Name & Phone';
    }

    protected function key(): string
    {
        return 'company_name_phone';
    }

    protected function sortOrder(): int
    {
        return 40;
    }

    protected function fields(): array
    {
        return [
            'company_name' => 'Company Name',
            'company_phone' => 'Company Phone',
        ];
    }

    protected function whereToFix(): string
    {
        return 'Set under Settings > Company Information.';
    }
}
