<?php

declare(strict_types=1);

namespace App\Tests\Service\Onboarding\Checks;

use App\Service\Onboarding\Checks\SmtpDsnConfiguredCheck;
use PHPUnit\Framework\TestCase;

/**
 * #426: "check smtp DSN is not dummy default value from .env". Adversarial focus: every way an
 * operator could think they configured MAILER_DSN and not actually have, and the reverse — every
 * way a real DSN must not be mistaken for the placeholder.
 */
final class SmtpDsnConfiguredCheckTest extends TestCase
{
    /**
     * MAILER_DSN is a real env var the container itself needs (config/packages/mailer.yaml
     * reads `%env(MAILER_DSN)%`) — every other test in the same PHPUnit process that boots the
     * kernel would fail with EnvNotFoundException if this test left it unset. tests/bootstrap.php
     * loads it once per process via Symfony Dotenv, which (since Symfony 5.1) populates only
     * `$_ENV`/`$_SERVER`, deliberately NOT the real process environment (no putenv) — so the
     * "original value" to save and restore lives in those superglobals, not in getenv(). Saving
     * only getenv() here (as an earlier version of this test did) reads back `false` for a value
     * Dotenv never put there, "restores" that as absent, and permanently deletes the real
     * $_ENV/$_SERVER entry Dotenv had set — breaking every later test in the same process that
     * boots the kernel.
     */
    private bool $hadOriginalValue = false;
    private string $originalValue = '';

    protected function setUp(): void
    {
        $current = $_ENV['MAILER_DSN'] ?? $_SERVER['MAILER_DSN'] ?? null;
        $this->hadOriginalValue = $current !== null;
        $this->originalValue = (string) $current;
    }

    protected function tearDown(): void
    {
        if ($this->hadOriginalValue) {
            $_ENV['MAILER_DSN'] = $this->originalValue;
            $_SERVER['MAILER_DSN'] = $this->originalValue;
            putenv('MAILER_DSN=' . $this->originalValue);
        } else {
            unset($_ENV['MAILER_DSN'], $_SERVER['MAILER_DSN']);
            putenv('MAILER_DSN');
        }
    }

    private function setDsn(?string $value): void
    {
        if ($value === null) {
            unset($_ENV['MAILER_DSN'], $_SERVER['MAILER_DSN']);
            putenv('MAILER_DSN');

            return;
        }

        $_ENV['MAILER_DSN'] = $value;
        $_SERVER['MAILER_DSN'] = $value;
        putenv('MAILER_DSN=' . $value);
    }

    public function testUnsetEnvVarFails(): void
    {
        $this->setDsn(null);

        $result = (new SmtpDsnConfiguredCheck())->run();

        self::assertFalse($result->passed);
    }

    public function testExactDotEnvDefaultFails(): void
    {
        $this->setDsn('null://null');

        self::assertFalse((new SmtpDsnConfiguredCheck())->run()->passed);
    }

    public function testDefaultDetectionIsCaseInsensitive(): void
    {
        // An operator "fixing" the placeholder by only changing its case should still be caught.
        $this->setDsn('NULL://NULL');

        self::assertFalse((new SmtpDsnConfiguredCheck())->run()->passed);
    }

    public function testEmptyStringFails(): void
    {
        $this->setDsn('');

        self::assertFalse((new SmtpDsnConfiguredCheck())->run()->passed);
    }

    public function testWhitespaceOnlyValueFails(): void
    {
        $this->setDsn('   ');

        self::assertFalse((new SmtpDsnConfiguredCheck())->run()->passed);
    }

    public function testRealSmtpDsnPasses(): void
    {
        $this->setDsn('smtp://user:secret@smtp.acme.test:587');

        self::assertTrue((new SmtpDsnConfiguredCheck())->run()->passed);
    }

    public function testDsnThatMerelyContainsTheWordNullPasses(): void
    {
        // Only an EXACT match of the placeholder DSN may fail — a real DSN happening to embed
        // "null" somewhere (e.g. a sub-domain or path segment) must not be falsely flagged.
        $this->setDsn('smtp://user:pass@null-relay.acme.test:587');

        self::assertTrue((new SmtpDsnConfiguredCheck())->run()->passed);
    }

    public function testFailureMessageDoesNotEchoBackTheRawConfiguredValue(): void
    {
        // The check must report the generic canonical placeholder text, never interpolate the
        // admin's actual (possibly credential-bearing) MAILER_DSN value into the message shown
        // on a page every admin can see.
        $this->setDsn('NULL://NULL');

        $result = (new SmtpDsnConfiguredCheck())->run();

        self::assertFalse($result->passed);
        self::assertStringNotContainsString('NULL://NULL', $result->message);
    }
}
