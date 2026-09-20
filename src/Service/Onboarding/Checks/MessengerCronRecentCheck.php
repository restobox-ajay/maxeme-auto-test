<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

use App\Contract\Onboarding\OnboardingCheckInterface;
use App\Contract\Onboarding\OnboardingCheckResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "check messenger cron ran the past hour (if not, means cron wasn't setup)" (#426).
 *
 * config/packages/messenger.yaml documents the expected crontab entry: `messenger:consume`
 * appending to var/log/messenger.log every minute. A log file that hasn't been touched in over
 * an hour means the cron entry is missing or broken, not just quiet — this app's mail (order
 * confirmations, password resets, etc.) all goes through this same async transport (#337).
 */
final class MessengerCronRecentCheck implements OnboardingCheckInterface
{
    private const STALE_AFTER_SECONDS = 3600;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getKey(): string
    {
        return 'messenger_cron_recent';
    }

    public function getGroup(): string
    {
        return 'Background Jobs';
    }

    public function getLabel(): string
    {
        return 'Messenger Cron Ran In The Past Hour';
    }

    public function getSortOrder(): int
    {
        return 10;
    }

    public function run(): OnboardingCheckResult
    {
        $path = $this->logPath();

        if (!is_file($path)) {
            return OnboardingCheckResult::fail(
                'var/log/messenger.log does not exist. The messenger:consume cron job has never run '
                . '— see config/packages/messenger.yaml for the expected crontab entry.'
            );
        }

        $mtime = @filemtime($path);
        if ($mtime === false) {
            return OnboardingCheckResult::fail('var/log/messenger.log exists but its last-modified time could not be read.');
        }

        $ageSeconds = time() - $mtime;
        if ($ageSeconds > self::STALE_AFTER_SECONDS) {
            return OnboardingCheckResult::fail(sprintf(
                'var/log/messenger.log was last written %s ago (more than an hour) — the '
                . 'messenger:consume cron job looks like it is not running. See '
                . 'config/packages/messenger.yaml for the expected crontab entry.',
                self::formatAge($ageSeconds)
            ));
        }

        return OnboardingCheckResult::pass();
    }

    private function logPath(): string
    {
        return rtrim($this->projectDir, '/') . '/var/log/messenger.log';
    }

    private static function formatAge(int $seconds): string
    {
        if ($seconds < 3600) {
            return max(1, (int) round($seconds / 60)) . ' min';
        }

        $hours = $seconds / 3600;
        if ($hours < 48) {
            return round($hours, 1) . ' hr';
        }

        return round($hours / 24, 1) . ' days';
    }
}
