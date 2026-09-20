<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

final class WebsiteUrlCheck extends AbstractSettingsFilledCheck
{
    protected function group(): string
    {
        return 'Company Profile';
    }

    protected function label(): string
    {
        return 'Website URL';
    }

    protected function key(): string
    {
        return 'website_url';
    }

    protected function sortOrder(): int
    {
        return 51;
    }

    protected function fields(): array
    {
        return [
            'website_url' => 'Website URL',
        ];
    }
}
