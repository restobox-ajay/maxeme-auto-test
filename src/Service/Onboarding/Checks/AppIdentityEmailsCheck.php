<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

final class AppIdentityEmailsCheck extends AbstractSettingsFilledCheck
{
    protected function group(): string
    {
        return 'App Identity';
    }

    protected function label(): string
    {
        return 'App Name, App Email & Billing Email';
    }

    protected function key(): string
    {
        return 'app_identity_emails';
    }

    protected function sortOrder(): int
    {
        return 10;
    }

    protected function fields(): array
    {
        return [
            'app_name' => 'App Name',
            'app_email' => 'App Email',
            'billing_email' => 'Billing Email',
        ];
    }
}
