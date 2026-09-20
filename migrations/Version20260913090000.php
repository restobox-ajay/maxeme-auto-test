<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteTableRebuild;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Item 43: `vendor.notes` becomes a `vendor_note` row, and only then goes.
 *
 * The vendor record carried TWO notes sections — a box labelled "Notes (legacy field)" bound to a
 * single `vendor.notes` CLOB, above a threaded list of `vendor_note` rows with an author and a
 * timestamp each. The customer record carries one, because `company` has no plain notes column at
 * all: the sell side has only threaded `company_note` rows. The owner ruled that vendor notes stay
 * and stay "just like customer", which means ONE threaded list.
 *
 * ## Why the column is not simply dropped
 *
 * Because of what it was being kept for. `Vendor`'s own docblock said the CLOB "stays as the record
 * of what was written before there was anywhere better to put it" — so it may hold text a real
 * person typed, in the only place they had to type it. Dropping that destroys the very thing the
 * column was preserved for, which is a worse outcome than the two boxes.
 *
 * ## What this migration does and does not guess
 *
 * One CLOB becomes exactly ONE note. It does not split on blank lines, on dates, or on anything
 * else. The reason is the one `AbstractPartyNote` gives for never having done this: where one note
 * ended and the next began was never recorded, so every boundary would be invented, and each
 * invented note would then need an author and a date nobody wrote down either. One row keeps the
 * text byte for byte and invents nothing about its structure.
 *
 * The author is stated as what it is rather than attributed to a person: MIGRATED_AUTHOR below
 * reads "Migrated from the old notes field", so the Notes panel says where the text came from
 * instead of implying somebody signed it. The date is the day this migration ran — also the honest
 * answer, because the CLOB never carried one.
 *
 * Whitespace-only and NULL CLOBs produce NOTHING. A vendor that never had a note does not gain an
 * empty one, which would be a note that never existed appearing in a list of notes that did.
 *
 * ## Then the rebuild
 *
 * Production SQLite is 3.26 and has no `ALTER TABLE DROP COLUMN`, so the column goes by rebuilding
 * the table — see `App\Doctrine\SqliteTableRebuild`. That is a `DROP TABLE vendor`, and nine tables
 * reference `vendor` (`vendor_note` itself among them, `ON DELETE CASCADE`), so this migration is
 * NON-TRANSACTIONAL and brackets the rebuild with `PRAGMA foreign_keys = OFF`: the pragma is
 * silently ignored inside a transaction, and that exact mistake once deleted every row a migration
 * had just written while reporting success (`Version20260730150000`). The notes inserted above are
 * the rows most at risk from it, so they are inserted BEFORE the pragma block and asserted after the
 * whole chain by `bin/ci-migration-replay`.
 *
 * Authorised on the standing ground for this side of the application: `vendor*` has never been
 * deployed anywhere but dev and holds no production rows. The INSERT is a MOVE of a value into the
 * table that now owns it, not a backfill of a column somebody else will also write.
 */
final class Version20260913090000 extends AbstractMigration
{
    /** Stated in the note itself, so the Notes panel says where the text came from. */
    private const MIGRATED_AUTHOR = 'Migrated from the old notes field';

    public function getDescription(): string
    {
        return 'Item 43: move vendor.notes into a vendor_note row, then drop the column.';
    }

    /** Rebuilds `vendor`; see the class docblock for why the pragma cannot be inside a transaction. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // The move. Ordered by id so the notes go in in a stable order, and dated now — the CLOB
        // never carried a date of its own, and inventing one would be the same mistake as inventing
        // where one note ended.
        $this->addSql(
            'INSERT INTO vendor_note (vendor_id, user_name, text, created_at) '
            . 'SELECT id, ?, notes, ? FROM vendor '
            . "WHERE notes IS NOT NULL AND TRIM(notes) <> '' ORDER BY id",
            [self::MIGRATED_AUTHOR, (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );

        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN');
        foreach (SqliteTableRebuild::statements($this->connection, 'vendor', ['notes' => null]) as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    /**
     * Puts the column back and the text with it, then removes the rows this migration created.
     *
     * Only rows carrying MIGRATED_AUTHOR are touched: a note somebody has typed since is a note, and
     * rolling a schema change back is not a reason to delete one. A vendor that has since been given
     * several notes gets its migrated one back into the column and keeps the rest as rows, which is
     * the state it would have been in had this never run.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendor ADD COLUMN notes CLOB DEFAULT NULL');
        $this->addSql(
            'UPDATE vendor SET notes = ('
            . 'SELECT n.text FROM vendor_note n WHERE n.vendor_id = vendor.id AND n.user_name = ? '
            . 'ORDER BY n.id ASC LIMIT 1)',
            [self::MIGRATED_AUTHOR],
        );
        $this->addSql('DELETE FROM vendor_note WHERE user_name = ?', [self::MIGRATED_AUTHOR]);
    }
}
