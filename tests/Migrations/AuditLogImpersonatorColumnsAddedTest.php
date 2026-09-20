<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Version20260806010000 must bring audit_log.impersonator_admin_id and impersonator_name out of the
 * chain, nullable, with no NOT NULL and no default that would make them look populated.
 *
 * Replays the real chain into a throwaway SQLite file rather than reading the migration's source,
 * for the same reason OrderEmailSummaryTableAddedTest does: only the schema the chain actually
 * produces is authoritative. A guarded ALTER that silently skipped, or a later table rebuild that
 * dropped the columns again, would both leave the source looking perfectly correct.
 *
 * Nullability is the assertion that matters most here. These columns exist ahead of the feature that
 * fills them, so every row written between now and then leaves them NULL; a NOT NULL that crept in
 * would break every audit insert in the system, and audit inserts sit inside other people's flushes.
 */
#[Group('migrations')]
final class AuditLogImpersonatorColumnsAddedTest extends TestCase
{
    private static ?string $databaseFile = null;

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$databaseFile, self::$databaseFile . '-wal', self::$databaseFile . '-shm'] as $path) {
            if (\is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }

        self::$databaseFile = null;
    }

    private function databaseAfterChain(): \PDO
    {
        if (self::$databaseFile === null) {
            $projectDir = \dirname(__DIR__, 2);
            self::$databaseFile = sys_get_temp_dir() . '/audit-log-impersonator-' . getmypid() . '.sqlite';
            @unlink(self::$databaseFile);

            $env = [
                'APP_ENV' => 'test',
                'DATABASE_URL' => 'sqlite:///' . self::$databaseFile,
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'HOME' => getenv('HOME') ?: '/tmp',
            ];

            $prefix = '';
            foreach ($env as $key => $value) {
                $prefix .= sprintf('%s=%s ', $key, escapeshellarg($value));
            }

            $output = [];
            $status = 0;
            exec(sprintf(
                'cd %s && %sphp bin/console doctrine:migrations:migrate --no-interaction -q 2>&1',
                escapeshellarg($projectDir),
                $prefix,
            ), $output, $status);

            self::assertSame(
                0,
                $status,
                "The migration chain does not replay from empty:\n" . implode("\n", $output),
            );
        }

        return new \PDO('sqlite:' . self::$databaseFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    /** @return array<string, array{notnull: bool, type: string, default: mixed}> */
    private function auditLogColumns(): array
    {
        $columns = [];
        foreach ($this->databaseAfterChain()->query('PRAGMA table_info(audit_log)')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $columns[$row['name']] = [
                'notnull' => (bool) $row['notnull'],
                'type' => strtoupper((string) $row['type']),
                'default' => $row['dflt_value'],
            ];
        }

        return $columns;
    }

    public function testBothImpersonatorColumnsExistAndAreNullable(): void
    {
        $columns = $this->auditLogColumns();

        foreach (['impersonator_admin_id' => 'INTEGER', 'impersonator_name' => 'VARCHAR(255)'] as $name => $type) {
            self::assertArrayHasKey($name, $columns, sprintf(
                'audit_log has no %s column — Version20260806010000 did not apply, or a later table rebuild dropped it.',
                $name,
            ));
            self::assertSame($type, $columns[$name]['type'], sprintf('audit_log.%s has the wrong column type.', $name));
            self::assertFalse($columns[$name]['notnull'], sprintf(
                'audit_log.%s is NOT NULL. It must be nullable: nothing populates it yet, so every audit insert '
                . 'in the system would fail.',
                $name,
            ));
            // SQLite echoes the DEFAULT clause back verbatim, so an explicit `DEFAULT NULL` reads as
            // the *string* 'NULL' here rather than as null — which is also what actor_id, written
            // the same way in Version20260803150000, reports. Either spelling means "no value";
            // anything else would be a real default.
            self::assertContains($columns[$name]['default'], [null, 'NULL'], sprintf(
                'audit_log.%s defaults to %s. NULL is the correct value for a row that was not impersonated; a '
                . 'default would make unimpersonated rows look like they carry a value.',
                $name,
                var_export($columns[$name]['default'], true),
            ));
        }
    }

    /**
     * No foreign key to admin_user, on purpose: an audit row has to outlive the admin it names, and
     * an FK would either cascade it away with them or block the deletion outright.
     */
    public function testTheImpersonatorIdIsNotAForeignKey(): void
    {
        $keys = $this->databaseAfterChain()->query('PRAGMA foreign_key_list(audit_log)')->fetchAll(\PDO::FETCH_ASSOC);

        $offending = array_values(array_filter(
            $keys,
            static fn (array $key): bool => ($key['from'] ?? '') === 'impersonator_admin_id',
        ));

        self::assertSame([], $offending, 'audit_log.impersonator_admin_id must not have a foreign key.');
    }

    /**
     * And no index. Nothing queries these columns yet, so the access pattern is still unknown and
     * any index chosen now would be a guess.
     */
    public function testNeitherColumnIsIndexed(): void
    {
        $pdo = $this->databaseAfterChain();

        $indexed = [];
        foreach ($pdo->query('PRAGMA index_list(audit_log)')->fetchAll(\PDO::FETCH_ASSOC) as $index) {
            $statement = $pdo->prepare('SELECT name FROM pragma_index_info(:index)');
            $statement->execute(['index' => $index['name']]);
            foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $column) {
                if (\in_array($column, ['impersonator_admin_id', 'impersonator_name'], true)) {
                    $indexed[] = $index['name'] . '(' . $column . ')';
                }
            }
        }

        self::assertSame([], $indexed, 'The impersonator columns must not be indexed until a query justifies one.');
    }

    /**
     * The point of the whole exercise: a row written by code that knows nothing about impersonation
     * — which is all of it today — inserts cleanly and reads back NULL.
     */
    public function testAnAuditRowRoundTripsWithBothColumnsNull(): void
    {
        $pdo = $this->databaseAfterChain();

        $pdo->prepare(
            'INSERT INTO audit_log (occurred_at, actor_type, actor_id, actor_name, area, entity_type, entity_id, "action", summary)
             VALUES (:occurred_at, :actor_type, NULL, :actor_name, :area, :entity_type, NULL, :action, :summary)',
        )->execute([
            'occurred_at' => '2026-08-06 00:00:00',
            'actor_type' => 'admin',
            'actor_name' => 'Round Trip',
            'area' => 'app',
            'entity_type' => 'Product',
            'action' => 'update',
            'summary' => 'Written without any knowledge of the impersonator columns.',
        ]);

        $row = $pdo->query(
            'SELECT actor_name, impersonator_admin_id, impersonator_name FROM audit_log ORDER BY id DESC LIMIT 1',
        )->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('Round Trip', $row['actor_name']);
        self::assertNull($row['impersonator_admin_id'], 'An unimpersonated row must read back NULL, not 0.');
        self::assertNull($row['impersonator_name'], 'An unimpersonated row must read back NULL, not an empty string.');
    }

    /**
     * The existing actor columns are untouched. This migration adds a second identity beside them;
     * it does not reshape the first one.
     */
    public function testTheExistingActorColumnsAreUnchanged(): void
    {
        $columns = $this->auditLogColumns();

        foreach (['actor_type', 'actor_name'] as $name) {
            self::assertArrayHasKey($name, $columns);
            self::assertTrue($columns[$name]['notnull'], sprintf('audit_log.%s should still be NOT NULL.', $name));
        }

        self::assertArrayHasKey('actor_id', $columns);
        self::assertFalse($columns['actor_id']['notnull'], 'audit_log.actor_id should still be nullable.');
    }
}
