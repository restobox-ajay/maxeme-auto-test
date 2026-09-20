<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

final class PrivacyPolicyCheck extends AbstractSettingsFilledCheck
{
    protected function group(): string
    {
        return 'Policies';
    }

    protected function label(): string
    {
        return 'Privacy Policy';
    }

    protected function key(): string
    {
        return 'policy_privacy';
    }

    protected function sortOrder(): int
    {
        return 80;
    }

    protected function fields(): array
    {
        return ['privacy_url' => 'Privacy URL'];
    }

    protected function whereToFix(): string
    {
        return 'Set the Privacy URL under Settings > Config.';
    }
}
