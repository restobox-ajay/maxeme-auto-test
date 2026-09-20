<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * flock-based serialization: at most one import executes at a time, across every import type.
 * Deliberately not Symfony's LockComponent — a plain file + flock() is enough here and keeps the
 * lock file's PID/timestamp directly inspectable (`cat /tmp/wholesale-imports.lock`) without going
 * through a lock store abstraction, which matters for the admin kill-switch below.
 *
 * See docs/plans/2026-09-18-unified-import-framework.md, D6/D9.
 */
final class ImportLock
{
    private const LOCK_FILE = '/tmp/wholesale-imports.lock';

    /** @var resource|null held only for the lifetime of the process that acquired it */
    private $handle = null;

    /**
     * Non-blocking. Writes "pid|ISO8601 start time" into the lock file on success so
     * getHolderInfo() can read it back without needing the flock itself (a reader never blocks).
     */
    public function tryAcquire(): bool
    {
        $handle = fopen(self::LOCK_FILE, 'c+');
        if ($handle === false) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, getmypid() . '|' . (new \DateTimeImmutable())->format(DATE_ATOM));
        fflush($handle);

        $this->handle = $handle;

        return true;
    }

    /** Only meaningful for the process that called tryAcquire() and got true back. */
    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
            @unlink(self::LOCK_FILE);
        }
    }

    /**
     * True if some process (this one or another) currently holds the lock. Never blocks: tries a
     * non-blocking acquire on a throwaway handle and immediately releases it if it succeeds.
     */
    public function isLocked(): bool
    {
        if (!file_exists(self::LOCK_FILE)) {
            return false;
        }

        $handle = fopen(self::LOCK_FILE, 'r');
        if ($handle === false) {
            return false;
        }

        $couldLock = flock($handle, LOCK_EX | LOCK_NB);
        if ($couldLock) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return !$couldLock;
    }

    /**
     * @return array{pid: int, startedAt: \DateTimeImmutable}|null null if not locked or the lock
     *                                                              file is missing/unreadable
     */
    public function getHolderInfo(): ?array
    {
        if (!$this->isLocked() || !file_exists(self::LOCK_FILE)) {
            return null;
        }

        $raw = @file_get_contents(self::LOCK_FILE);
        if ($raw === false || !str_contains($raw, '|')) {
            return null;
        }

        [$pid, $startedAt] = explode('|', $raw, 2);
        $startedAtDate = \DateTimeImmutable::createFromFormat(DATE_ATOM, $startedAt);
        if (!ctype_digit($pid) || !$startedAtDate instanceof \DateTimeImmutable) {
            return null;
        }

        return ['pid' => (int) $pid, 'startedAt' => $startedAtDate];
    }

    /**
     * Admin-triggered force kill of whatever process holds the lock. Kills the PID, then removes
     * the lock file directly — this process never held the flock itself, so there is nothing to
     * flock(LOCK_UN) here; deleting the file is what makes the next tryAcquire() succeed.
     *
     * @throws \RuntimeException if the lock isn't currently held, or the PID isn't a live process
     */
    public function forceKillHolder(): void
    {
        $holder = $this->getHolderInfo();
        if ($holder === null) {
            throw new \RuntimeException('No import is currently running.');
        }

        if (posix_getpgid($holder['pid']) === false) {
            @unlink(self::LOCK_FILE);
            throw new \RuntimeException("Process {$holder['pid']} was not running; stale lock cleared.");
        }

        posix_kill($holder['pid'], SIGKILL);
        @unlink(self::LOCK_FILE);
    }
}
