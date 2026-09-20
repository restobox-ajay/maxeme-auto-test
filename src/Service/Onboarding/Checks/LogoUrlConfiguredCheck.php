<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

/** Is a logo_url setting recorded at all — separate from LogoFileExistsCheck, which verifies the file it points to is actually on disk. */
final class LogoUrlConfiguredCheck extends AbstractSettingsFilledCheck
{
    protected function group(): string
    {
        return 'Branding';
    }

    protected function label(): string
    {
        return 'Logo URL Configured';
    }

    protected function key(): string
    {
        return 'logo_url_configured';
    }

    protected function sortOrder(): int
    {
        return 60;
    }

    protected function fields(): array
    {
        return ['logo_url' => 'Logo URL'];
    }

    protected function whereToFix(): string
    {
        return 'Upload a logo under Settings > Branding.';
    }
}
