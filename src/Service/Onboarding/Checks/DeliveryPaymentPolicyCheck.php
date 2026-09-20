<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

/**
 * #426 asks for a "Delivery & Payment Policy" check alongside Terms & Conditions and Privacy
 * Policy. The app has no setting under that exact name — the closest existing one is
 * `returns_url` ("Link to Returns/Refund policy page"), the only other admin-editable policy
 * link besides terms_url/privacy_url (see ConfigController::coreSettingDefaults()). Reusing it
 * here rather than inventing a fourth near-duplicate URL setting; if a distinct Delivery &
 * Payment policy field is wanted later, add its own AppSettings key and point this check at it
 * instead.
 */
final class DeliveryPaymentPolicyCheck extends AbstractSettingsFilledCheck
{
    protected function group(): string
    {
        return 'Policies';
    }

    protected function label(): string
    {
        return 'Delivery & Payment Policy';
    }

    protected function key(): string
    {
        return 'policy_delivery_payment';
    }

    protected function sortOrder(): int
    {
        return 65;
    }

    protected function fields(): array
    {
        return ['returns_url' => 'Returns URL'];
    }

    protected function whereToFix(): string
    {
        return 'Set the Returns URL under Settings > Config (used here as the Delivery & Payment policy link).';
    }
}
