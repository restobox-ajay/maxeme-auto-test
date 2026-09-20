<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Atomic per-(kind, prefix) counter behind OrderNumberGenerator and EstimateNumberGenerator
 * (issue #304). See migrations/Version20260802110000.php for why a counter table replaces the old
 * `SELECT MAX(...)` read, and why one row per prefix rather than one row overall.
 *
 * `kind` keeps orders and quotes in separate sequences under the same table ('order' / 'estimate');
 * neither generator's prefix setting is shared with the other, so their counters must not be either.
 *
 * ## Why BEGIN IMMEDIATE, and only when nothing else has a transaction open
 *
 * PDO's SQLite driver starts a transaction with a plain deferred `BEGIN`, which takes no lock at
 * all until the first write statement runs — and empirically, PDO does not reliably honour
 * `busy_timeout` for *that* lock upgrade under real contention (confirmed with a 6-process fork
 * test: `PDO::beginTransaction()` + `UPDATE` failed instantly under load that an explicit
 * `BEGIN IMMEDIATE` sailed through). `BEGIN IMMEDIATE` takes the write lock up front, where
 * `busy_timeout` does apply, so this issues it as raw SQL on the EntityManager's own connection —
 * exactly the connection a real concurrent request already has to itself, so no second connection
 * is needed to get the same guarantee a genuinely separate process would have.
 *
 * `isTransactionActive()` guards that: SQLite allows only one transaction per connection, so a
 * second `BEGIN` on a connection that already has one open — the functional test suite wraps every
 * test in exactly such a transaction (see Functional.suite.yml), and EstimateController::accept()
 * wraps the whole conversion in one — would error outright. When that is the case, this simply runs
 * its statements as part of the caller's already-open transaction instead of managing its own; by
 * the time this method is reached inside an ambient transaction, that transaction has invariably
 * already written something (Codeception's fixture setup, claimForConversion()'s own write), which
 * has already upgraded it past the deferred-lock stage this exists to skip.
 */
final class DocumentNumberAllocator
{
    public function next(EntityManagerInterface $entityManager, string $kind, string $prefix, string $table, string $column): int
    {
        $conn = $entityManager->getConnection();
        $ownsTransaction = !$conn->isTransactionActive();

        if ($ownsTransaction) {
            $conn->executeStatement('BEGIN IMMEDIATE');
        }

        try {
            $conn->executeStatement(
                'INSERT OR IGNORE INTO document_number_counter (kind, prefix, last_value) VALUES (?, ?, ?)',
                [$kind, $prefix, $this->seed($conn, $prefix, $table, $column)],
            );

            $conn->executeStatement(
                'UPDATE document_number_counter SET last_value = last_value + 1 WHERE kind = ? AND prefix = ?',
                [$kind, $prefix],
            );

            $next = (int) $conn->fetchOne(
                'SELECT last_value FROM document_number_counter WHERE kind = ? AND prefix = ?',
                [$kind, $prefix],
            );

            if ($ownsTransaction) {
                $conn->executeStatement('COMMIT');
            }

            return $next;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $conn->executeStatement('ROLLBACK');
            }

            throw $e;
        }
    }

    /** The old generators' exact MAX(...) computation — run once, only when a (kind, prefix) row does not exist yet. */
    private function seed(Connection $conn, string $prefix, string $table, string $column): int
    {
        $result = $conn->fetchOne(
            sprintf("SELECT MAX(CAST(SUBSTR(%s, ?) AS INTEGER)) FROM %s WHERE %s LIKE ? ESCAPE '\\'", $column, $table, $column),
            [\strlen($prefix) + 1, $this->likeEscape($prefix) . '%'],
        );

        return ($result !== null && $result !== false) ? (int) $result : 0;
    }

    private function likeEscape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
