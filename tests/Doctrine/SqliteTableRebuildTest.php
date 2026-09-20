<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\SqliteTableRebuild;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * DROP COLUMN for SQLite 3.26, against a table shaped like the ones this chain actually holds.
 *
 * ## The defect that made this file exist (#659)
 *
 * SQLite stores a CREATE statement byte for byte, comments included, and several tables in this
 * chain were created with explanatory `--` lines between their columns. `invoice_line` carries
 *
 * ```
 * -- Deliberately NO foreign key to product_core, matching sales_order_line.
 * ```
 *
 * and the comma in it split the definition in the wrong place: the rebuild came out with a column
 * called `matching`, and the very next statement — `SELECT ... FROM __temp__invoice_line` — failed
 * with "no such column: matching". It surfaced the first time a migration dropped a column from a
 * commented table, which is #659 step 4, and it would have surfaced for whoever did that next.
 *
 * It is worth stating what the failure mode was NOT. It threw, loudly, mid-migration — it did not
 * quietly rebuild the table without a column. That is the difference between this and
 * `Version20260730150000`, which reported success while `DROP TABLE` cascaded; here the chain stops.
 * Both are only ever found by `bin/ci-migration-replay`, because no test suite runs migrations.
 *
 * Uses a real in-memory SQLite connection rather than a mock: the input this class parses is
 * whatever `sqlite_master` hands back, so a fixture written by hand would be testing the fixture.
 */
final class SqliteTableRebuildTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    /** The regression: a comma inside a `--` comment is not a column boundary. */
    public function testAColumnIsDroppedFromATableWhoseDefinitionCarriesCommentedCommas(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE invoice_line (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                invoice_id INTEGER NOT NULL,
                -- Deliberately NO foreign key to product_core, matching sales_order_line.
                -- A document line snapshots a product; it must outlive that product's deletion.
                product_id INTEGER DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                packaging_unit_id INTEGER DEFAULT NULL
            )
        SQL);
        $this->connection->executeStatement('CREATE INDEX idx_invoice_line_invoice ON invoice_line (invoice_id)');
        $this->connection->executeStatement('CREATE INDEX idx_invoice_line_packaging_unit ON invoice_line (packaging_unit_id)');
        $this->connection->executeStatement(
            "INSERT INTO invoice_line (invoice_id, product_id, name, packaging_unit_id) VALUES (7, 9, 'Widget', 42)",
        );

        $this->rebuild('invoice_line', ['packaging_unit_id' => null]);

        $columns = $this->columnsOf('invoice_line');
        self::assertSame(['id', 'invoice_id', 'product_id', 'name'], $columns, 'and no column called "matching"');

        $row = $this->connection->fetchAssociative('SELECT * FROM invoice_line');
        self::assertIsArray($row);
        self::assertSame(['id' => 1, 'invoice_id' => 7, 'product_id' => 9, 'name' => 'Widget'], $row, 'the row survives the rebuild');

        // The index on the dropped column goes with it; every other index is recreated.
        self::assertSame(['idx_invoice_line_invoice'], $this->indexesOf('invoice_line'));
    }

    /**
     * The positive control for the comment handling: a `--` inside a string literal is not a comment.
     *
     * A DEFAULT value is the one place a string literal appears in these CREATE statements, and a
     * stripper that did not track quotes would eat the rest of the line and produce a definition
     * SQLite rejects — trading one mis-parse for another.
     */
    public function testADoubleDashInsideAStringDefaultIsNotTreatedAsAComment(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE note (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                body VARCHAR(80) DEFAULT '-- not a comment, really' NOT NULL,
                doomed INTEGER DEFAULT NULL
            )
        SQL);
        $this->connection->executeStatement('INSERT INTO note (doomed) VALUES (1)');

        $this->rebuild('note', ['doomed' => null]);

        self::assertSame(['id', 'body'], $this->columnsOf('note'));
        self::assertSame('-- not a comment, really', $this->connection->fetchOne('SELECT body FROM note'));

        // And the default is still on the rebuilt column, which is the half a lost literal would break.
        $this->connection->executeStatement('INSERT INTO note DEFAULT VALUES');
        self::assertSame(
            ['-- not a comment, really', '-- not a comment, really'],
            $this->connection->fetchFirstColumn('SELECT body FROM note ORDER BY id'),
        );
    }

    /** Foreign keys and the rest of the definition are carried through verbatim. */
    public function testTheRestOfTheDefinitionSurvivesTheRebuild(): void
    {
        $this->connection->executeStatement('CREATE TABLE parent (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE child (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                parent_id INTEGER NOT NULL,
                doomed INTEGER DEFAULT NULL,
                CONSTRAINT fk_child_parent FOREIGN KEY (parent_id) REFERENCES parent (id) ON DELETE CASCADE
            )
        SQL);

        $this->rebuild('child', ['doomed' => null]);

        $create = (string) $this->connection->fetchOne("SELECT sql FROM sqlite_master WHERE name = 'child'");
        self::assertStringContainsString('FOREIGN KEY (parent_id) REFERENCES parent (id) ON DELETE CASCADE', $create);
        self::assertStringNotContainsString('doomed', $create);
    }

    public function testDroppingAColumnThatIsNotThereIsRefusedRatherThanIgnored(): void
    {
        $this->connection->executeStatement('CREATE TABLE tiny (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        SqliteTableRebuild::statements($this->connection, 'tiny', ['ghost' => 'ghost INTEGER']);
    }

    /** @param array<string, string|null> $columns */
    private function rebuild(string $table, array $columns): void
    {
        // The pragma is set outside a transaction, exactly as the migrations that call this do:
        // inside one it is silently ignored and DROP TABLE cascades.
        $this->connection->executeStatement('PRAGMA foreign_keys = OFF');
        foreach (SqliteTableRebuild::statements($this->connection, $table, $columns) as $sql) {
            $this->connection->executeStatement($sql);
        }
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->connection->fetchAllAssociative(sprintf('PRAGMA table_info(%s)', $table)),
        );
    }

    /** @return list<string> */
    private function indexesOf(string $table): array
    {
        return $this->connection->fetchFirstColumn(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL ORDER BY name",
            [$table],
        );
    }
}
