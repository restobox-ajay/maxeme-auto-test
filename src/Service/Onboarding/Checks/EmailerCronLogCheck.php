<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

use App\Contract\Onboarding\OnboardingCheckInterface;
use App\Contract\Onboarding\OnboardingCheckResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "check emailer cron is on and ran by checking messenger.log is exist and not empty" (#426).
 *
 * Deliberately separate from MessengerCronRecentCheck: that one asks "did it run in the last
 * hour" (freshness), this one asks the coarser "has it ever produced any output at all"
 * (existence + non-empty) — the two fail independently. A brand-new empty file created by
 * `var/log/.gitkeep`'s sibling tooling but never written to would pass an existence-only check
 * yet still mean the cron never actually ran.
 */
final class EmailerCronLogCheck implements OnboardingCheckInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getKey(): string
    {
        return 'emailer_cron_log';
    }

    public function getGroup(): string
    {
        return 'Background Jobs';
    }

    public function getLabel(): string
    {
        return 'Emailer Cron Log Exists & Is Not Empty';
    }

    public function getSortOrder(): int
    {
        return 20;
    }

    public function run(): OnboardingCheckResult
    {
        $path = rtrim($this->projectDir, '/') . '/var/log/messenger.log';

        if (!is_file($path)) {
            return OnboardingCheckResult::fail('var/log/messenger.log does not exist — the emailer cron has never run.');
        }

        $size = @filesize($path);
        if ($size === false || $size <= 0) {
            return OnboardingCheckResult::fail('var/log/messenger.log exists but is empty — the emailer cron has never produced output.');
        }

        return OnboardingCheckResult::pass();
    }
}
