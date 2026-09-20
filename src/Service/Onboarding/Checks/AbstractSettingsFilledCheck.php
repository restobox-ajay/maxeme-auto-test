<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

use App\Contract\Onboarding\OnboardingCheckInterface;
use App\Contract\Onboarding\OnboardingCheckResult;
use App\Service\AppSettings;

/**
 * Shared "is this AppSettings field filled out" shape used by most of #426's checklist items.
 * Never reports the configured value itself (only which fields are blank) — see
 * OnboardingCheckResult's docblock on why this page must not surface setting values.
 */
abstract class AbstractSettingsFilledCheck implements OnboardingCheckInterface
{
    public function __construct(
        private readonly AppSettings $appSettings,
    ) {
    }

    abstract protected function group(): string;

    abstract protected function label(): string;

    abstract protected function key(): string;

    abstract protected function sortOrder(): int;

    /** @return array<string, string> AppSettings key => human-readable field name */
    abstract protected function fields(): array;

    /** Extra guidance appended to a failure message, e.g. "Set under Settings > Branding." */
    protected function whereToFix(): string
    {
        return 'Set under Settings > Config.';
    }

    final public function getKey(): string
    {
        return $this->key();
    }

    final public function getGroup(): string
    {
        return $this->group();
    }

    final public function getLabel(): string
    {
        return $this->label();
    }

    final public function getSortOrder(): int
    {
        return $this->sortOrder();
    }

    final public function run(): OnboardingCheckResult
    {
        $missing = [];
        foreach ($this->fields() as $settingKey => $fieldLabel) {
            $value = trim((string) ($this->appSettings->get($settingKey) ?? ''));
            if ($value === '') {
                $missing[] = $fieldLabel;
            }
        }

        if ($missing === []) {
            return OnboardingCheckResult::pass();
        }

        $plural = count($missing) > 1 ? 'are' : 'is';

        return OnboardingCheckResult::fail(
            implode(', ', $missing) . ' ' . $plural . ' not filled out. ' . $this->whereToFix()
        );
    }
}
