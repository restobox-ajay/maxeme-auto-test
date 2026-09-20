<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

final class CompanyGstNumberCheck extends AbstractSettingsFilledCheck
{
    protected function group(): string
    {
        return 'Company Profile';
    }

    protected function label(): string
    {
        return 'Company GST/Tax Number';
    }

    protected function key(): string
    {
        return 'company_gst_number';
    }

    protected function sortOrder(): int
    {
        return 52;
    }

    protected function fields(): array
    {
        return [
            'company_gst_number' => 'Company GST/Tax Number',
        ];
    }
}
