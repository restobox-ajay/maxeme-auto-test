<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use App\Doctrine\SqliteTableRebuild;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The four stock-override quantity columns join every other quantity in the application (#601).
 *
 * ```
 * sales_order_line_stock_override.requested_quantity   INTEGER  ->  NUMERIC(14, 4)
 * sales_order_line_stock_override.available_quantity   INTEGER  ->  NUMERIC(14, 4)
 * invoice_line_stock_override.requested_quantity       INTEGER  ->  NUMERIC(14, 4)
 * invoice_line_stock_override.available_quantity       INTEGER  ->  NUMERIC(14, 4)
 * ```
 *
 * ## Why this is a defect and not a tidy-up
 *
 * `Version20260910090000` widened 69 quantity columns across 26 tables to `NUMERIC(14, 4)`, because
 * **fractional quantities are supported** — a third of a case is 0.3333 and two decimal places lose
 * it. These two tables were written a week later, by a change that had no reason to think about
 * units, and they were written `INTEGER`.
 *
 * An INTEGER column does not refuse 2.5. It takes it, stores 2, and hands 2 back afterwards as
 * though 2 were the figure somebody decided on — no error, no warning, a wrong number written to
 * the database and then read as authoritative. This row exists to say what an operator was looking
 * at when they chose to sell past the shelf; a column that silently rewrites that figure makes the
 * record worse than no record, because it looks like one.
 *
 * `backorder_capacity` is deliberately NOT in the list. It is the inventory layer's own figure —
 * `ProductInventory::remainingBackorderCapacity()`, an int until #646 widens that layer — and
 * widening the column here without widening what fills it would be a promise nothing keeps. It is
 * named in the report of the change that brought this one, for a person to rule on.
 *
 * ## Not one value is written
 *
 * A widening changes what a column may hold, never what it holds. Every existing figure comes back
 * exactly as it went in: an override recorded against 3 units reads 3 afterwards — the same number,
 * now in a column that can also hold 2.5 — never NULL and never 0. That is PROVEN rather than
 * asserted: {@see preUp()} snapshots both tables by id, and {@see postUp()} refuses to let the
 * migration stand unless every row is still there with the same figures on it.
 *
 * Note what SQLite's NUMERIC affinity does and does not do here. `3` stays the integer 3 in the
 * file — a NUMERIC column stores the narrowest lossless representation — so a re-read gives `3`
 * and not the text `3.0000`. Nothing is lost by that: the value is identical, the column can now
 * hold `2.5`, and the entity puts a figure at the column's scale on the way in
 * ({@see \App\Entity\SalesOrderLineStockOverride}) while `App\Service\DisplayNumber` decides what it
 * looks like on the way out.
 *
 * ## The rebuild, and the pragma
 *
 * SQLite has no `ALTER COLUMN`, and production runs 3.26, so a type change is the documented
 * create-copy-drop-recreate — `App\Doctrine\SqliteTableRebuild`, which lifts each table's own
 * `CREATE` out of `sqlite_master`, swaps the two type tokens and re-issues the indexes that were
 * actually there. Reading the live definition rather than retyping it is what keeps the unique and
 * date indexes, the foreign keys and the column ORDER exactly as the chain left them.
 *
 * `isTransactional(): false` with `PRAGMA foreign_keys` bracketing the work, which is the shape
 * `Version20260730150000` got wrong and `Version20260910090000` and `Version20260911170000` get
 * right: the pragma is **silently ignored inside a transaction**. Neither of these two tables is a
 * parent of anything, so nothing can cascade off the `DROP` — but both are CHILDREN of document
 * lines, and re-inserting a child while the keys are enforced is exactly the step that would fail
 * on a database where a line has since gone. The rebuild itself is wrapped in an explicit
 * `BEGIN`/`COMMIT` so a failure half way through cannot leave a dropped table behind, and
 * {@see postUp()} runs `PRAGMA foreign_key_check` afterwards because with the keys off an orphan is
 * not refused at the moment it is made — it simply sits there and the migration reports success.
 *
 * ## `$schema` is never touched
 *
 * Not in `up()`, not in the hooks. Since `Version20260821160000` the injected `Schema` is a lazy
 * proxy whose first call introspects the whole database and dies on `uniq_inventory_detail`, an
 * expression index SQLite reports with a NULL column name — see
 * {@see \App\Doctrine\SqliteMigrationIntrospection}, whose `tableExists()` is what the guards below
 * use instead. `bin/ci-migration-replay` refuses the migration outright if it does.
 *
 * ## The stamp
 *
 * `Version20260919164500` is above every stamp on any ref, tag, working tree or reflog at the time
 * of writing — the highest found anywhere was `Version20260919090000`. Off the daily `090000`
 * pattern for the reason `Version20260912161500` gives: two agents both reaching for "tomorrow at
 * 09:00" is how two branches collide on one stamp and silently drop each other's work, and neither
 * test suite would see it, because both build their schema from Doctrine metadata and never run the
 * chain. Only `bin/ci-migration-replay` ever does.
 */
final class Version20260919164500 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    /** The two tables, and the two columns on each. One shape, one recorder, one widening. */
    private const TABLES = ['sales_order_line_stock_override', 'invoice_line_stock_override'];

    private const COLUMNS = ['requested_quantity', 'available_quantity'];

    /**
     * Every row's figures as they were before the rebuild, keyed `table` => `id` => column => value.
     *
     * Filled by {@see preUp()} and read by {@see postUp()}. A property rather than a temp table
     * because the comparison is PHP's to make: a SQL check could only say "the counts match", and
     * the failure this guards against — a rebuild that copies rows but loses or zeroes a column —
     * keeps the counts matching perfectly.
     *
     * @var array<string, array<int, array<string, string>>>
     */
    private array $before = [];

    /** See the class docblock: the pragma that keeps the keys off is a no-op inside a transaction. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return '#601: the four stock-override quantity columns become NUMERIC(14, 4). No value is written.';
    }

    public function preUp(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $rows = [];
            foreach ($this->connection->fetchAllAssociative(sprintf(
                'SELECT id, %s FROM %s',
                implode(', ', self::COLUMNS),
                $table,
            )) as $row) {
                $rows[(int) $row['id']] = self::figures($row);
            }

            $this->before[$table] = $rows;
        }
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN');

        // DROP TABLE takes the AUTOINCREMENT high-water mark with it, and re-inserting the rows only
        // restores it as far as MAX(id) — so a table whose newest override had been deleted would
        // come back ready to hand out an id it had already used.
        $this->addSql(sprintf(
            "CREATE TEMPORARY TABLE __seq__601 AS SELECT name, seq FROM sqlite_sequence WHERE name IN ('%s')",
            implode("', '", self::TABLES),
        ));

        foreach (self::TABLES as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            // The replacement definitions, spelled exactly as Version20260910090000 spells the 69 it
            // widened, so one grep for `NUMERIC(14, 4)` finds every quantity column in the chain.
            $statements = SqliteTableRebuild::statements($this->connection, $table, [
                'requested_quantity' => 'requested_quantity NUMERIC(14, 4) NOT NULL',
                'available_quantity' => 'available_quantity NUMERIC(14, 4) NOT NULL',
            ]);

            foreach ($statements as $sql) {
                $this->addSql($sql);
            }
        }

        // Both are no-ops for a table whose counter the re-insert already put back where it was.
        $this->addSql('INSERT INTO sqlite_sequence (name, seq) SELECT name, seq FROM __seq__601 WHERE name NOT IN (SELECT name FROM sqlite_sequence)');
        $this->addSql('UPDATE sqlite_sequence SET seq = (SELECT s.seq FROM __seq__601 s WHERE s.name = sqlite_sequence.name) WHERE EXISTS (SELECT 1 FROM __seq__601 s WHERE s.name = sqlite_sequence.name AND s.seq > sqlite_sequence.seq)');
        $this->addSql('DROP TABLE __seq__601');

        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    /**
     * The three things this migration could plausibly get wrong, each turned into a failure.
     *
     * A rebuild that orphaned a child, a rebuild that lost or rewrote a figure, and a rebuild that
     * recreated the table with the old type after all. None of them fails on its own: with the keys
     * off an orphan is simply written, a lost value looks exactly like a value that was always that,
     * and the wrong type is invisible until somebody stores 2.5 in production.
     */
    public function postUp(Schema $schema): void
    {
        $violations = $this->connection->executeQuery('PRAGMA foreign_key_check')->fetchAllAssociative();
        if ($violations !== []) {
            throw new \RuntimeException(sprintf(
                'The rebuild orphaned %d row(s) — the first is in %s. The database is NOT in a state to '
                . 'keep: restore it and work out which parent was dropped while a child pointed at it.',
                \count($violations),
                (string) ($violations[0]['table'] ?? 'an unknown table'),
            ));
        }

        foreach ($this->before as $table => $before) {
            $after = [];
            foreach ($this->connection->fetchAllAssociative(sprintf(
                'SELECT id, %s FROM %s',
                implode(', ', self::COLUMNS),
                $table,
            )) as $row) {
                $after[(int) $row['id']] = self::figures($row);
            }

            if (array_keys($before) !== array_keys($after)) {
                throw new \RuntimeException(sprintf(
                    '%s went in with %d row(s) and came out with %d. A widening writes nothing and loses '
                    . 'nothing; this database is not in a state to keep.',
                    $table,
                    \count($before),
                    \count($after),
                ));
            }

            foreach ($before as $id => $figures) {
                foreach ($figures as $column => $value) {
                    if (($after[$id][$column] ?? null) !== $value) {
                        throw new \RuntimeException(sprintf(
                            '%s.%s on row %d was %s before the rebuild and is %s after it. A widening changes '
                            . 'what a column may hold, never what it holds.',
                            $table,
                            $column,
                            $id,
                            $value,
                            $after[$id][$column] ?? 'gone',
                        ));
                    }
                }
            }
        }

        foreach (self::TABLES as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            foreach ($this->connection->fetchAllAssociative(sprintf('PRAGMA table_info(%s)', $table)) as $column) {
                if (!\in_array((string) $column['name'], self::COLUMNS, true)) {
                    continue;
                }

                if (strtoupper((string) $column['type']) !== 'NUMERIC(14, 4)') {
                    throw new \RuntimeException(sprintf(
                        '%s.%s came back declared %s, not NUMERIC(14, 4) — the rebuild did not take.',
                        $table,
                        (string) $column['name'],
                        (string) $column['type'],
                    ));
                }
            }
        }
    }

    /**
     * One row's figures, each at the column's four places, so "3" and "3.0" compare as one value.
     *
     * SQLite's NUMERIC affinity decides for itself whether a figure is stored as an integer or a
     * real, and it is entitled to: both are the same number. What must not move is the NUMBER, so
     * that is what is compared, rather than whichever representation the file happened to use on
     * either side of the rebuild.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private static function figures(array $row): array
    {
        $figures = [];
        foreach (self::COLUMNS as $column) {
            $figures[$column] = $row[$column] === null
                ? 'NULL'
                : number_format((float) $row[$column], 4, '.', '');
        }

        return $figures;
    }

    /**
     * Irreversible, for the reason Version20260910090000 gives about the same 69 columns.
     *
     * A narrower column cannot promise to hold what a wider one was allowed to accept: any override
     * recorded at 2.5 units since this ran would be rounded on the way back through, which is the
     * exact defect the widening exists to end, performed deliberately. Restore from a backup.
     */
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Going back means re-declaring four quantity columns INTEGER, and an INTEGER column cannot '
            . 'hold the fractional overrides recorded since this ran — every one of them would be '
            . 'silently rounded on the way through. Restore from a backup instead.'
        );
    }
}
