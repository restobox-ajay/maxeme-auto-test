<?php

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class SqliteWalMiddleware implements Middleware
{
    /**
     * Durability level for `PRAGMA synchronous`, used everywhere real data is at stake.
     *
     * WAL defaults to NORMAL, which can lose recently committed transactions on a power loss.
     * FULL fsyncs each commit, trading write throughput for durability.
     */
    public const SYNCHRONOUS_FULL = 'FULL';

    /**
     * Durability level for throwaway databases — the test suites, whose SQLite file is deleted
     * and rebuilt from entity metadata on every run.
     *
     * There is nothing in that file worth surviving a crash, and the fsync FULL performs on each
     * statement is not free: DoctrineIntegrationTestCase rebuilds the whole schema per test
     * method, and under FULL those 128 DDL statements take ~163ms instead of ~10ms. Across the
     * ~200 tests that extend it, that is ~30s of a ~51s run spent entirely in fsync.
     */
    public const SYNCHRONOUS_OFF = 'OFF';

    /**
     * Values `PRAGMA synchronous` accepts. The pragma takes no bound parameters — the level is
     * interpolated straight into SQL — so anything not on this list is rejected outright rather
     * than concatenated into a statement.
     */
    private const ALLOWED_SYNCHRONOUS = ['OFF', 'NORMAL', 'FULL', 'EXTRA'];

    private readonly string $synchronous;

    public function __construct(string $synchronous = self::SYNCHRONOUS_FULL)
    {
        $normalized = strtoupper($synchronous);

        if (!in_array($normalized, self::ALLOWED_SYNCHRONOUS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid PRAGMA synchronous level "%s"; expected one of: %s.',
                $synchronous,
                implode(', ', self::ALLOWED_SYNCHRONOUS),
            ));
        }

        $this->synchronous = $normalized;
    }

    /**
     * PRAGMAs applied to every SQLite connection, in order.
     *
     * Most of these are per-connection state that SQLite resets on each connect, so they have to be
     * re-issued here rather than set once against the database file:
     *
     * - journal_mode = WAL   persists in the file header, but is re-asserted so a fresh database
     *                        (a new test run, a restored backup) never runs in rollback-journal mode.
     * - busy_timeout = 500   makes a blocked writer wait up to 500ms for the lock instead of failing
     *                        immediately with "database is locked". Defaults to 0.
     * - foreign_keys = ON    defaults to OFF in SQLite; without this, FK constraints are not enforced.
     * - locking_mode = NORMAL  already the default, and required for WAL to allow multiple connections.
     *                        Stated explicitly so an inherited EXCLUSIVE setting can't lock others out.
     * - synchronous          see the class constants above; FULL everywhere but the test env.
     *
     * @return list<string>
     */
    public function pragmas(): array
    {
        return [
            'PRAGMA journal_mode = WAL',
            'PRAGMA busy_timeout = 500',
            'PRAGMA foreign_keys = ON',
            'PRAGMA locking_mode = NORMAL',
            'PRAGMA synchronous = ' . $this->synchronous,
        ];
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver, $this->pragmas()) extends AbstractDriverMiddleware {
            /** @param list<string> $pragmas */
            public function __construct(Driver $driver, private readonly array $pragmas)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): Connection
            {
                $connection = parent::connect($params);

                if (($params['driver'] ?? null) === 'pdo_sqlite') {
                    foreach ($this->pragmas as $pragma) {
                        $connection->exec($pragma);
                    }
                }

                return $connection;
            }
        };
    }
}
