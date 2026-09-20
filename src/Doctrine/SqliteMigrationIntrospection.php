<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * Ask the database what it has, without touching the `$schema` a migration is handed.
 *
 * ## The hazard, and the error it produces
 *
 * **A migration in this project may not touch the injected `Doctrine\DBAL\Schema\Schema`.** Not
 * `$schema->hasTable(...)`, not `$schema->getTable(...)->hasColumn(...)`, not in `up()`, `down()`,
 * or any of the `preUp`/`postUp`/`preDown`/`postDown` hooks. Doctrine hands that object over as an
 * uninitialised lazy proxy and the FIRST call on it introspects the WHOLE database — every table,
 * every index. Since `Version20260821160000` this schema carries `uniq_inventory_detail`, an
 * expression index:
 *
 *     CREATE UNIQUE INDEX uniq_inventory_detail ON inventory_detail
 *         (product_id, warehouse_id, COALESCE(location_id, 0), COALESCE(lot_id, 0), COALESCE(serial, ''), status)
 *
 * SQLite reports an expression's column name as NULL — `PRAGMA index_info(uniq_inventory_detail)`
 * gives `cid = -2, name = NULL` for each of the three COALESCE terms — and DBAL's
 * `Index::_addColumn()` is typed `string`. So the introspection dies, and the migration dies with it:
 *
 *     Migration DoctrineMigrations\VersionXXXXXXXXXXXXXX failed during Execution.
 *     Error: "Doctrine\DBAL\Schema\Index::_addColumn(): Argument #1 ($column) must be of type
 *     string, null given, called in .../doctrine/dbal/src/Schema/Index.php on line 118"
 *
 * That message names a DBAL internal and, indirectly, an inventory index — on a migration that may
 * only have wanted to know whether one column exists on `estimate`. Nothing in it points at the
 * cause. If you arrived here by searching that text: this is the cause, and the two paragraphs
 * below are the fix.
 *
 * The index is NOT the bug and is not to be changed. It is valid SQLite and it is the only thing
 * making the inventory uniqueness rule enforceable — a plain unique index would not collapse NULLs,
 * which SQLite treats as distinct, so it would permit exactly the duplicate detail rows it forbids.
 * `Version20260821160000`'s own docblock argues that at length. The expression form is also the
 * *sole* trigger: the partial indexes on the same table (`uniq_live_serial`, `idx_detail_available`,
 * both carrying a `WHERE`) introspect perfectly well. Only a column that is an expression has no
 * name to report.
 *
 * ## What to do instead
 *
 *     use App\Doctrine\SqliteMigrationIntrospection;
 *
 *     final class VersionXXXXXXXXXXXXXX extends AbstractMigration
 *     {
 *         use SqliteMigrationIntrospection;
 *
 *         public function up(Schema $schema): void
 *         {
 *             if (!$this->tableExists('estimate') || $this->columnExists('estimate', 'version')) {
 *                 return;
 *             }
 *
 *             $this->addSql('ALTER TABLE estimate ADD COLUMN version INTEGER DEFAULT 1 NOT NULL');
 *         }
 *     }
 *
 * `$schema` stays in the signature — Doctrine's `AbstractMigration` declares it and the parameter
 * must be there — but it is never read, so it is never initialised and nothing is introspected.
 * These three questions are asked of `sqlite_master` and `PRAGMA table_info` directly, which report
 * an expression index as a row like any other and have no opinion about its columns.
 *
 * ## Why this is a trait and not a base class
 *
 * Every migration in this chain already extends `Doctrine\Migrations\AbstractMigration`, and PHP has
 * single inheritance. A trait is one `use` line on a migration written any other way, including the
 * 19 older ones that would each need their `extends` rewritten otherwise. It reads `$this->connection`,
 * `AbstractMigration`'s own protected property, so it is only usable inside a migration — which is
 * the only place it should be.
 *
 * Nothing here is new work: `Version20260911090000` and `Version20260917090000` each wrote the first
 * two of these methods inline for exactly this reason. This is that pair, extracted so the third
 * migration to need them does not have to rediscover why. Placed beside {@see SqliteTableRebuild},
 * the other piece of shared migration machinery, for the same reason.
 *
 * Enforced rather than merely written down: `bin/ci-migration-replay` refuses any migration after
 * `Version20260821160000` that touches `$schema`, before it replays anything.
 */
trait SqliteMigrationIntrospection
{
    /**
     * Whether the table exists at this point in the chain.
     *
     * A table whose bundle was never installed simply has no row here. That is a real answer, not a
     * failure — `modules/ProcurementBundle` and `modules/WarehouseOpsBundle` are optional and
     * `bin/ci-bundles-off` runs with them off.
     */
    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
        );
    }

    /**
     * Whether the table has that column.
     *
     * `PRAGMA table_info` takes no parameters, hence the sprintf — the table name comes from the
     * migration's own source in every existing caller, never from input.
     */
    private function columnExists(string $table, string $column): bool
    {
        foreach ($this->connection->fetchAllAssociative(sprintf('PRAGMA table_info(%s)', $table)) as $row) {
            if ($row['name'] === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an index of that name exists, on any table.
     *
     * The third question `$schema` gets asked, and the one that would otherwise send the next author
     * to `$schema->getTable(...)->hasIndex(...)` — which is the same landmine in a different shape,
     * and on `inventory_detail` it is the landmine itself. Names are global in SQLite, so no table
     * argument is needed.
     */
    private function indexExists(string $index): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?",
            [$index],
        );
    }
}
