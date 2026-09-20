<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

final class TimezoneCheck extends AbstractSettingsFilledCheck
{
    protected function group(): string
    {
        return 'Company Profile';
    }

    protected function label(): string
    {
        return 'Timezone';
    }

    protected function key(): string
    {
        return 'timezone';
    }

    protected function sortOrder(): int
    {
        return 50;
    }

    protected function fields(): array
    {
        return [
            'timezone' => 'Timezone',
        ];
    }
}
