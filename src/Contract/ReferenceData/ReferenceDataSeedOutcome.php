<?php

declare(strict_types=1);

namespace App\Contract\ReferenceData;

/**
 * What happened to ONE {@see ReferenceDataSeederInterface} during a
 * {@see \App\Service\ReferenceData\ReferenceDataSeeder::run()} call.
 *
 * The sibling of {@see \App\Contract\Onboarding\OnboardingCheckResult}, and it carries the same
 * warning: `$message` is written into the application log and is returned to whatever called the
 * seeder, so it must say WHAT happened and never WHAT VALUE — no setting values, no emails, no
 * DSNs. A failing seeder's exception message is logged with the exception attached and summarised
 * here rather than quoted verbatim, for exactly the reason `OnboardingChecklistService` gives.
 */
final class ReferenceDataSeedOutcome
{
    /** The seeder ran and created rows (or ran, found everything present, and created none). */
    public const STATE_SEEDED = 'seeded';

    /** The seeder's key was already marked, so it was not called at all. The steady state. */
    public const STATE_ALREADY_SEEDED = 'already_seeded';

    /**
     * Another process claimed the same key while this one was working.
     *
     * Reported separately from STATE_ALREADY_SEEDED because they are not the same event: this one
     * means the UNIQUE constraint on `reference_data_seed_mark.seeder_key` did its job and stopped
     * a duplicate seed, which is worth being able to see in a log.
     */
    public const STATE_CLAIMED_ELSEWHERE = 'claimed_elsewhere';

    /** The seeder threw. Its mark was NOT written, so it is retried on the next login. */
    public const STATE_FAILED = 'failed';

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $state,
        public readonly int $rowsCreated,
        public readonly string $message,
    ) {
    }

    public static function seeded(string $key, string $label, int $rowsCreated): self
    {
        return new self($key, $label, self::STATE_SEEDED, $rowsCreated, sprintf(
            '%s: seeded, %d row(s) created.',
            $label,
            $rowsCreated,
        ));
    }

    public static function alreadySeeded(string $key, string $label): self
    {
        return new self($key, $label, self::STATE_ALREADY_SEEDED, 0, $label . ': already seeded.');
    }

    public static function claimedElsewhere(string $key, string $label): self
    {
        return new self($key, $label, self::STATE_CLAIMED_ELSEWHERE, 0, sprintf(
            '%s: another process seeded this concurrently.',
            $label,
        ));
    }

    public static function failed(string $key, string $label, string $message): self
    {
        return new self($key, $label, self::STATE_FAILED, 0, $label . ': ' . $message);
    }

    /** True when this seeder wrote nothing at all — every state but a seed that created rows. */
    public function wroteNothing(): bool
    {
        return $this->rowsCreated === 0;
    }
}
