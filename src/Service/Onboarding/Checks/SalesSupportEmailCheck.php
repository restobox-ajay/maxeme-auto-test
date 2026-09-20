<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

final class SalesSupportEmailCheck extends AbstractSettingsFilledCheck
{
    protected function group(): string
    {
        return 'App Identity';
    }

    protected function label(): string
    {
        return 'Sales & Support Email';
    }

    protected function key(): string
    {
        return 'sales_support_email';
    }

    protected function sortOrder(): int
    {
        return 20;
    }

    protected function fields(): array
    {
        return [
            'sales_email' => 'Sales Email',
            'support_email' => 'Support Email',
        ];
    }
}
