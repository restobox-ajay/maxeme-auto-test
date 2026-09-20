<?php

declare(strict_types=1);

namespace App\Tests\Service\Onboarding\Checks;

use App\Service\Onboarding\Checks\MessengerCronRecentCheck;
use PHPUnit\Framework\TestCase;

/**
 * #426: "check messenger cron ran the past hour (if not, means cron wasn't setup)". Uses a
 * throwaway project directory so the log file's mtime can be manipulated precisely — including
 * adversarial filesystem shapes (a directory where a file is expected) that must fail cleanly
 * rather than throw.
 */
final class MessengerCronRecentCheckTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/onboarding-messenger-test-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var/log', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->projectDir);
    }

    private function removeRecursively(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeRecursively($path . '/' . $entry);
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    private function logPath(): string
    {
        return $this->projectDir . '/var/log/messenger.log';
    }

    public function testMissingLogFileFails(): void
    {
        $check = new MessengerCronRecentCheck($this->projectDir);

        $result = $check->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('never run', $result->message);
    }

    public function testFileWrittenMomentsAgoPasses(): void
    {
        file_put_contents($this->logPath(), 'ran');
        touch($this->logPath(), time());

        $result = (new MessengerCronRecentCheck($this->projectDir))->run();

        self::assertTrue($result->passed);
    }

    public function testFileWrittenTwoHoursAgoFails(): void
    {
        file_put_contents($this->logPath(), 'ran');
        touch($this->logPath(), time() - 7200);

        $result = (new MessengerCronRecentCheck($this->projectDir))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('not running', $result->message);
    }

    public function testFileWrittenExactlyOneHourAgoPasses(): void
    {
        // Boundary: "the past hour" is inclusive of exactly 3600 seconds ago.
        file_put_contents($this->logPath(), 'ran');
        touch($this->logPath(), time() - 3600);

        $result = (new MessengerCronRecentCheck($this->projectDir))->run();

        self::assertTrue($result->passed);
    }

    public function testFileWrittenOneSecondPastTheBoundaryFails(): void
    {
        file_put_contents($this->logPath(), 'ran');
        touch($this->logPath(), time() - 3601);

        $result = (new MessengerCronRecentCheck($this->projectDir))->run();

        self::assertFalse($result->passed);
    }

    public function testADirectoryInPlaceOfTheLogFileFailsWithoutThrowing(): void
    {
        // A misconfigured deploy could leave var/log/messenger.log as a directory (e.g. an
        // interrupted `mkdir -p` race). is_file() must reject it rather than filemtime()/read
        // throwing a warning-turned-exception under strict error handling.
        mkdir($this->logPath());

        $result = (new MessengerCronRecentCheck($this->projectDir))->run();

        self::assertFalse($result->passed);
    }
}
