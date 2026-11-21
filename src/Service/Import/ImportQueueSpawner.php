<?php

declare(strict_types=1);

namespace App\Service\Import;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Spawns `bin/console import:process` as a detached, fire-and-forget child process — the same
 * shape App\Controller\Admin\ProductImportController::spawnImportProcess() already uses for its
 * own import, factored out here so every import (and the admin "Process Queue"/"Retry Queue"
 * buttons) shares one implementation instead of each hand-rolling Process/PhpExecutableFinder.
 *
 * ImportLock's flock is what actually prevents duplicate workers — this class does not check the
 * lock itself; calling it while a drain is already running is harmless, since import:process's own
 * tryAcquire() will just exit immediately (see D6/D9).
 *
 * Which PHP runs it: `IMPORT_PHP_BINARY` when set, else whatever PhpExecutableFinder finds. Under
 * a web server the finder cannot use the running PHP (that is the server's own binary) and takes
 * the first `php` on the PATH, which can be another version than the site runs on (WAMP's PHP 8.1
 * beside the site's 8.4; a cPanel default `php`). Such a child dies at once on the app's platform
 * check and the run stays queued with nothing said, so the binary's version is checked first and
 * a mismatch goes to the Error Log instead.
 */
final class ImportQueueSpawner
{
    /** The oldest PHP the app runs on (composer.json "php": ">=8.2"). */
    private const MINIMUM_PHP_VERSION_ID = 80200;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
        private readonly bool $debug,
        private readonly string $phpBinary = '',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function spawn(): void
    {
        $phpBinary = trim($this->phpBinary) !== '' ? trim($this->phpBinary) : ((new PhpExecutableFinder())->find() ?: 'php');

        $version = $this->versionOf($phpBinary);
        if ($version < self::MINIMUM_PHP_VERSION_ID) {
            $this->logger?->error(sprintf(
                'Import queue not started: %s %s. Set IMPORT_PHP_BINARY to the PHP the site runs on (8.2 or newer), or run "php bin/console import:process" from cron.',
                $phpBinary,
                $version === 0 ? 'could not be run' : sprintf('is PHP %d.%d', intdiv($version, 10000), intdiv($version % 10000, 100)),
            ));

            return;
        }

        $process = new Process(
            [$phpBinary, $this->projectDir . '/bin/console', 'import:process'],
            $this->projectDir,
            [
                'APP_ENV' => $this->environment,
                'APP_DEBUG' => $this->debug ? '1' : '0',
            ],
            null,
            null,
        );

        // create_new_console is the one setOptions() flag Process documents as letting a child
        // "continue to run after the main process exited" — without it, Process::__destruct() calls
        // stop(0) (SIGTERM then immediate SIGKILL) the instant $process's refcount hits zero, which
        // happens right here at the end of this method with nothing else holding a reference. That
        // is not a hypothetical: an adversarial test spawning this from a long-lived PHP process
        // (unlike a short-lived FPM worker, where the request tears down before GC gets to it)
        // caught it outright — the queued run never budged past 'queued'. See
        // ImportProcessCommandTest::testProcessQueueButtonActuallyDrainsAQueuedRun.
        $process->setOptions(['create_new_console' => true]);

        // Fire-and-forget: the caller (a web request, or another console command) must not block
        // on how long the whole queue takes to drain.
        $process->disableOutput();

        try {
            $process->start();
        } catch (\Throwable $e) {
            $this->logger?->error(sprintf('Import queue not started: %s could not be run (%s).', $phpBinary, $e->getMessage()));
        }
    }

    /** PHP_VERSION_ID of $phpBinary, or 0 when it cannot be run. */
    private function versionOf(string $phpBinary): int
    {
        try {
            $probe = new Process([$phpBinary, '-r', 'echo PHP_VERSION_ID;'], $this->projectDir, null, null, 10);
            $probe->run();

            return $probe->isSuccessful() ? (int) trim($probe->getOutput()) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
