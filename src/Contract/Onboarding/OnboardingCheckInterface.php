<?php

declare(strict_types=1);

namespace App\Contract\Onboarding;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One prelaunch checklist item on the admin Onboarding page (#426).
 *
 * Extensible by construction, per the issue's own request ("make it very extensible so if we
 * want to add more check it is just easily add in a place"): implement this interface anywhere
 * under src/ (or a bundle's own src/, same as every other App\Contract seam in this app) and it
 * is picked up automatically — no registry to edit, no services.yaml entry, no enum to extend.
 * #[AutoconfigureTag] below plus this app's blanket `autoconfigure: true`
 * (config/services.yaml) tags every implementation with 'app.onboarding_check', which
 * App\Service\Onboarding\OnboardingChecklistService collects via
 * #[AutowireIterator('app.onboarding_check')] — the identical pattern
 * App\Twig\AdminMenuExtension already uses for admin_menu_tree().
 *
 * A check must be side-effect-free and safe to run on every page load (no writes, no outbound
 * network calls) and must never throw — OnboardingChecklistService catches exceptions
 * defensively anyway (a bug in one check must never take the whole page down), but a well-formed
 * check reports its own failure via OnboardingCheckResult::fail() instead of throwing.
 */
#[AutoconfigureTag('app.onboarding_check')]
interface OnboardingCheckInterface
{
    /**
     * Stable identifier for this check, e.g. 'company_name_phone'. Used only as a DOM id/anchor
     * on the onboarding page — never persisted, never used for access control.
     */
    public function getKey(): string;

    /** Section heading this check is displayed under, e.g. "Company Profile". */
    public function getGroup(): string;

    /** Short human-readable label, e.g. "Company Name & Phone". */
    public function getLabel(): string;

    /**
     * Lower values sort first within their group when all checks in the group share the same
     * pass/fail state (failed checks always float to the top of the page regardless of this).
     */
    public function getSortOrder(): int;

    /** Run the check now and report the result. */
    public function run(): OnboardingCheckResult;
}
