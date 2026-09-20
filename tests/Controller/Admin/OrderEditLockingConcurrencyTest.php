<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use PHPUnit\Framework\TestCase;

/**
 * #396: Admin\OrderController::edit() wraps its save in an explicit transaction and forces SQLite's
 * write lock immediately via `UPDATE sales_order SET id = id WHERE id = 0` — the identical idiom
 * CartController::add()/reorder() and CheckoutController::processCheckoutSubmission() already use
 * for the same reason (#301/#321): beginTransaction() alone is deferred and takes no lock until the
 * first write, so without a forced early write two concurrent saves against the same order could
 * still interleave at the SQL level instead of one waiting for the other.
 *
 * A single PHP process can't demonstrate that — everything it does is serialized by the
 * interpreter — so, following DocumentNumberAllocatorConcurrencyTest's approach for #304, this forks
 * a real OS process with its own SQLite connection to the same file and has it run the exact
 * statement edit() runs while holding the transaction open. If the forced write genuinely takes
 * SQLite's write lock, a second, real connection attempting the same statement while the first is
 * still open must block until the first commits — it cannot succeed immediately. That is what is
 * asserted here via elapsed wall-clock time, not by inspecting source code.
 *
 * Skipped where pcntl isn't available (the extension is optional; CI/dev here has it).
 */
final class OrderEditLockingConcurrencyTest extends TestCase
{
    /** Mirrors SqliteWalMiddleware::pragmas() — this is what every real connection to the app's SQLite file gets. */
    private const BUSY_TIMEOUT_MS = 500;

    /** How long the "first admin's save" holds the write lock before committing. */
    private const HOLD_MS = 250;

    private string $dbPath;

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension not available.');
        }

        $this->dbPath = sys_get_temp_dir() . '/order_edit_locking_concurrency_' . bin2hex(random_bytes(8)) . '.sqlite';

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        $pdo->exec('CREATE TABLE sales_order (id INTEGER PRIMARY KEY AUTOINCREMENT, order_number VARCHAR(80))');
        $pdo->exec("INSERT INTO sales_order (order_number) VALUES ('SO-1')");
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->dbPath . $suffix);
        }
    }

    /**
     * Proves the forced-write idiom, applied to sales_order the same way it is applied to cart,
     * actually serializes two connections that both try to take it at (as close as this gets to)
     * the same moment — one admin's save genuinely waits for the other's, rather than both writing
     * in an interleaved, torn fashion.
     */
    public function testForcedWriteOnSalesOrderBlocksASecondConcurrentSaveUntilTheFirstCommits(): void
    {
        $pipe = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertNotFalse($pipe, 'could not create a socket pair for child->parent signalling');
        [$parentSocket, $childSocket] = $pipe;

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // Child: plays "admin A", already mid-save. Opens its own connection, forces the write
            // lock exactly as OrderController::edit() does, holds it for HOLD_MS to simulate the
            // rest of the save (line rebuild, fee/tax recalculation, flush), then commits.
            fclose($parentSocket);

            $pdo = new \PDO('sqlite:' . $this->dbPath);
            $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
            $pdo->beginTransaction();
            $pdo->exec('UPDATE sales_order SET id = id WHERE id = 0');
            fwrite($childSocket, "locked\n");

            usleep(self::HOLD_MS * 1000);
            $pdo->commit();

            fclose($childSocket);
            exit(0);
        }

        // Parent: plays "admin B", submitting a concurrent edit of the same order. Waits for the
        // child to confirm it holds the lock first, then attempts the identical statement.
        fclose($childSocket);
        $signal = fgets($parentSocket);
        fclose($parentSocket);
        self::assertSame("locked\n", $signal, 'child did not report taking the lock');

        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);

        $start = microtime(true);
        $pdo->beginTransaction();
        $pdo->exec('UPDATE sales_order SET id = id WHERE id = 0');
        $elapsedMs = (microtime(true) - $start) * 1000;
        $pdo->commit();

        pcntl_waitpid($pid, $status);

        // Generous lower bound: the child holds the lock for HOLD_MS, so admin B's forced write can
        // only have succeeded by waiting SQLite's busy-timeout retry loop out until admin A
        // committed. If the two writes were NOT serialized, this would return almost immediately
        // (a few ms) instead.
        self::assertGreaterThanOrEqual(
            self::HOLD_MS * 0.6,
            $elapsedMs,
            sprintf(
                'the second connection\'s forced write returned after only %.1fms, well under the %dms the first held the lock — the two writes were not serialized',
                $elapsedMs,
                self::HOLD_MS,
            ),
        );
    }

    /**
     * The test above proves the SQLite mechanism works; this ties that proof to the actual code —
     * it would fail if a future refactor of Admin\OrderController::edit() dropped the transaction
     * wrap or the forced write while leaving everything else looking untouched, which is exactly
     * the failure mode the issue warns about for LockMode::PESSIMISTIC_WRITE: a change that looks
     * correct in review and does nothing at runtime.
     */
    public function testEditMethodSourceContainsTheSameTransactionAndForcedWriteIdiomAsCartAndCheckout(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Controller/Admin/OrderController.php');
        self::assertNotFalse($source);

        $editStart = strpos($source, 'public function edit(');
        self::assertNotFalse($editStart, 'edit() method not found');

        $updateStatusStart = strpos($source, 'public function updateStatus(', $editStart);
        self::assertNotFalse($updateStatusStart, 'updateStatus() method not found after edit()');

        // The body of edit() only, so a match below cannot be satisfied by the forced-write idiom
        // living in some other method instead — the issue scopes this fix to edit() alone.
        $editBody = substr($source, $editStart, $updateStatusStart - $editStart);

        self::assertStringContainsString(
            '$conn->beginTransaction();',
            $editBody,
            'edit() no longer opens an explicit transaction around its save',
        );
        self::assertStringContainsString(
            "UPDATE sales_order SET id = id WHERE id = 0",
            $editBody,
            'edit() no longer forces the write lock early the same way cart/checkout do — PESSIMISTIC_WRITE is a no-op on SQLite, so this exact idiom is required, not a Doctrine lock mode',
        );
        self::assertStringContainsString('$conn->commit();', $editBody, 'edit() no longer commits the explicit transaction');
        self::assertStringContainsString('$conn->rollBack();', $editBody, 'edit() no longer rolls back the explicit transaction on failure');

        // The forced write must run before the flush it is protecting, not after.
        $forcedWritePos = strpos($editBody, 'UPDATE sales_order SET id = id WHERE id = 0');
        $flushPos = strpos($editBody, '$entityManager->flush();');
        self::assertNotFalse($flushPos);
        self::assertLessThan($flushPos, $forcedWritePos, 'the forced write must happen before flush(), not after it');

        // Scoped narrowly to edit() only (#412 tracks extending this to updateStatus()/updateTime()):
        // neither sibling action should have picked up its own copy of this idiom as a side effect.
        $updateStatusAndTimeBody = substr($source, $updateStatusStart);
        self::assertStringNotContainsString(
            'UPDATE sales_order SET id = id WHERE id = 0',
            $updateStatusAndTimeBody,
            'the forced-write idiom leaked into another OrderController action — #396 scopes it to edit() only; extending it elsewhere is #412',
        );
    }
}
