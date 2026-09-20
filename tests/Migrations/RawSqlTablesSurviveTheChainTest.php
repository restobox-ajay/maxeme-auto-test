<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tables used through raw SQL must still exist after the migration chain has run.
 *
 * This exists because of a specific mistake. Version20260730000000 dropped payment_term,
 * credit_memo_type and shipping_zone on the grounds that "no entity maps them" — but those three are
 * driven entirely by raw DBAL queries in Admin\ConfigController's CONFIG_TABLES registry and by
 * AbstractAdminController's payment-term dropdown, which is *why* they have no entity. Every
 * create/update/delete on those three admin screens then failed with "no such table".
 *
 * Nothing caught it, and each reason generalises to any repeat of the mistake:
 *
 *   - Both suites build their schema from the entity mappings (SchemaTool /
 *     doctrine:schema:create), so these tables are absent in tests whether or not a migration drops
 *     them. A functional test of those screens would have been red before and after.
 *   - doctrine:schema:validate is blind for the same reason: unmapped means uncompared.
 *   - The list pages kept returning 200, because configTablePage() and AbstractAdminController both
 *     guard with tablesExist(). Only writing a row surfaced it.
 *
 * It replays the real chain into a throwaway SQLite file rather than inspecting the migration source.
 * The first version of this test did the latter and passed while the bug was reinstated: the drop is
 * generated in a loop — sprintf('DROP TABLE %s', $table) over a const array — so the literal
 * "DROP TABLE payment_term" appears nowhere for a regex to find. Only the schema the chain actually
 * produces is authoritative.
 */
#[Group('migrations')]
final class RawSqlTablesSurviveTheChainTest extends TestCase
{
    /**
     * Tables the application reads or writes with raw SQL rather than through an entity.
     *
     * testTheListCoversEveryConfigTablesEntry below keeps this honest, so a new raw-SQL config table
     * cannot be added without being registered here.
     */
    private const RAW_SQL_TABLES = [
        'payment_term' => 'Admin\ConfigController CONFIG_TABLES + AbstractAdminController dropdown',
        'credit_memo_type' => 'Admin\ConfigController CONFIG_TABLES',
        'shipping_zone' => 'Admin\ConfigController CONFIG_TABLES + ORDERABLE_CONFIGS',
    ];

    private static ?string $databaseFile = null;

    /** @var list<string>|null */
    private static ?array $tables = null;

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$databaseFile, self::$databaseFile . '-wal', self::$databaseFile . '-shm'] as $path) {
            if (\is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }

        self::$databaseFile = null;
        self::$tables = null;
    }

    /**
     * Replays every migration into a fresh database and returns the tables that exist afterwards.
     *
     * Cached across the test methods in this class — one replay is about a second, and running it
     * three times would be three seconds for no extra coverage.
     *
     * @return list<string>
     */
    private function tablesAfterChain(): array
    {
        if (self::$tables !== null) {
            return self::$tables;
        }

        $projectDir = \dirname(__DIR__, 2);
        self::$databaseFile = sys_get_temp_dir() . '/raw-sql-tables-' . getmypid() . '.sqlite';
        @unlink(self::$databaseFile);

        // SQLite creates the file on first connect; doctrine:database:create is unsupported for it.
        $env = [
            'APP_ENV' => 'test',
            'DATABASE_URL' => 'sqlite:///' . self::$databaseFile,
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
        ];

        $command = 'php bin/console doctrine:migrations:migrate --no-interaction -q 2>&1';
        $prefix = '';
        foreach ($env as $key => $value) {
            $prefix .= sprintf('%s=%s ', $key, escapeshellarg($value));
        }

        $output = [];
        $status = 0;
        exec(sprintf('cd %s && %s%s', escapeshellarg($projectDir), $prefix, $command), $output, $status);

        self::assertSame(
            0,
            $status,
            "The migration chain does not replay from empty:\n" . implode("\n", $output),
        );

        $pdo = new \PDO('sqlite:' . self::$databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $names = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        )->fetchAll(\PDO::FETCH_COLUMN);

        return self::$tables = array_map('strval', $names);
    }

    public function testTheChainLeavesEveryRawSqlTableInPlace(): void
    {
        $tables = $this->tablesAfterChain();
        $missing = [];

        foreach (self::RAW_SQL_TABLES as $table => $usedBy) {
            if (!\in_array($table, $tables, true)) {
                $missing[] = sprintf('%s — used by %s', $table, $usedBy);
            }
        }

        self::assertSame([], $missing, sprintf(
            "A database built from the migration chain is missing tables the application queries with "
            . "raw SQL. Writing a row on those admin screens will fail with \"no such table\":\n  %s\n\n"
            . 'Neither suite can catch this on its own: both build their schema from the entity '
            . 'mappings, and these tables have no entity — which is exactly how they got dropped.',
            implode("\n  ", $missing),
        ));
    }

    /** Sanity: if the replay produced almost nothing, the test above would pass vacuously. */
    public function testTheReplayProducedAFullSchema(): void
    {
        self::assertGreaterThan(40, \count($this->tablesAfterChain()), 'The replayed schema looks truncated.');
    }

    /**
     * Keeps RAW_SQL_TABLES from going stale: a new raw-SQL-backed config table must be registered
     * here, or the test above would not know to protect it.
     */
    public function testTheListCoversEveryConfigTablesEntry(): void
    {
        $controller = (string) file_get_contents(
            \dirname(__DIR__, 2) . '/src/Controller/Admin/ConfigController.php',
        );

        preg_match_all("/'table'\s*=>\s*'([a-z_]+)'/", $controller, $matches);
        $registered = array_unique($matches[1]);

        self::assertNotEmpty($registered, 'Found no CONFIG_TABLES entries — has the registry moved?');

        foreach ($registered as $table) {
            // Entity-backed tables are already covered by schema:validate and both suites; only the
            // ones with no entity need this test's protection.
            if (is_file(\dirname(__DIR__, 2) . '/src/Entity/' . self::studly($table) . '.php')) {
                continue;
            }

            self::assertArrayHasKey(
                $table,
                self::RAW_SQL_TABLES,
                sprintf(
                    'ConfigController drives "%s" with raw SQL and it has no entity, so add it to '
                    . 'RAW_SQL_TABLES in this test — otherwise a migration can drop it unnoticed.',
                    $table,
                ),
            );
        }
    }

    private static function studly(string $snake): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $snake)));
    }
}
