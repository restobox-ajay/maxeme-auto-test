<?php

declare(strict_types=1);

namespace App\Service\Import;

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
 */
final class ImportQueueSpawner
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
        private readonly bool $debug,
    ) {
    }

    public function spawn(): void
    {
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';

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
        $process->start();
    }
}
