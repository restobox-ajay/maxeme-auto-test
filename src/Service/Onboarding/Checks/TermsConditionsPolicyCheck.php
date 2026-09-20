<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

final class TermsConditionsPolicyCheck extends AbstractSettingsFilledCheck
{
    protected function group(): string
    {
        return 'Policies';
    }

    protected function label(): string
    {
        return 'Terms & Conditions';
    }

    protected function key(): string
    {
        return 'policy_terms';
    }

    protected function sortOrder(): int
    {
        return 70;
    }

    protected function fields(): array
    {
        return ['terms_url' => 'Terms URL'];
    }

    protected function whereToFix(): string
    {
        return 'Set the Terms URL under Settings > Config.';
    }
}
