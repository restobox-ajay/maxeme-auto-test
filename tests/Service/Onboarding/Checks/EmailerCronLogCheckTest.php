<?php

declare(strict_types=1);

namespace App\Tests\Service\Onboarding\Checks;

use App\Service\Onboarding\Checks\EmailerCronLogCheck;
use PHPUnit\Framework\TestCase;

/**
 * #426: "check emailer cron is on and ran by checking messenger.log is exist and not empty" —
 * deliberately distinct from MessengerCronRecentCheck's freshness test (see that class's
 * docblock). Adversarial focus: a zero-byte file (created but never written to) must still fail.
 */
final class EmailerCronLogCheckTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/onboarding-emailer-test-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var/log', 0777, true);
    }

    protected function tearDown(): void
    {
        $path = $this->logPath();
        if (is_file($path)) {
            unlink($path);
        }
        @rmdir($this->projectDir . '/var/log');
        @rmdir($this->projectDir . '/var');
        @rmdir($this->projectDir);
    }

    private function logPath(): string
    {
        return $this->projectDir . '/var/log/messenger.log';
    }

    public function testMissingFileFails(): void
    {
        $result = (new EmailerCronLogCheck($this->projectDir))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('does not exist', $result->message);
    }

    public function testZeroByteFileFails(): void
    {
        touch($this->logPath());

        $result = (new EmailerCronLogCheck($this->projectDir))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('empty', $result->message);
    }

    public function testFileOldButNonEmptyStillPasses(): void
    {
        // This check does not care about recency — that's MessengerCronRecentCheck's job — so a
        // stale-but-non-empty log must still pass here.
        file_put_contents($this->logPath(), "line one\n");
        touch($this->logPath(), time() - 100000);

        $result = (new EmailerCronLogCheck($this->projectDir))->run();

        self::assertTrue($result->passed);
    }
}
