<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

use App\Contract\Onboarding\OnboardingCheckInterface;
use App\Contract\Onboarding\OnboardingCheckResult;

/**
 * "check smtp DSN is not dummy default value from .env" (#426). `.env` ships
 * `MAILER_DSN="null://null"` (config/packages/mailer.yaml reads `%env(MAILER_DSN)%`) — a
 * placeholder that silently discards every email. A fresh install that never overrides it in
 * `.env.local`/the real environment sends no mail and gets no error anywhere, so this is checked
 * directly against the process environment rather than through AppSettings (which has no
 * MAILER_DSN entry at all — it's a deploy-time env var, not an admin-editable setting).
 *
 * Never renders the actual DSN value: a real one carries SMTP credentials in the userinfo
 * component (smtp://user:pass@host:port), and this page is visible to every admin.
 */
final class SmtpDsnConfiguredCheck implements OnboardingCheckInterface
{
    private const DUMMY_DEFAULT = 'null://null';

    public function getKey(): string
    {
        return 'smtp_dsn_configured';
    }

    public function getGroup(): string
    {
        return 'Email Delivery';
    }

    public function getLabel(): string
    {
        return 'SMTP DSN Configured';
    }

    public function getSortOrder(): int
    {
        return 10;
    }

    public function run(): OnboardingCheckResult
    {
        $dsn = trim((string) $this->readEnv('MAILER_DSN'));

        if ($dsn === '') {
            return OnboardingCheckResult::fail('MAILER_DSN is not set. Configure a real SMTP DSN in the environment.');
        }

        if (strcasecmp($dsn, self::DUMMY_DEFAULT) === 0) {
            return OnboardingCheckResult::fail(
                'MAILER_DSN is still the placeholder "null://null" from .env. Set a real SMTP DSN '
                . 'in .env.local or the environment — mail is currently discarded, not sent.'
            );
        }

        return OnboardingCheckResult::pass();
    }

    private function readEnv(string $name): string
    {
        $value = getenv($name);
        if ($value !== false) {
            return $value;
        }

        return (string) ($_ENV[$name] ?? $_SERVER[$name] ?? '');
    }
}
