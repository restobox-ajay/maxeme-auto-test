<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `reference_data_seed_mark` — the record that a seeder has run, so it never runs again.
 *
 * One new table, nothing else touched. See {@see \App\Entity\ReferenceDataSeedMark} for what the
 * row means and {@see \App\Service\ReferenceData\ReferenceDataSeeder} for who writes it.
 *
 * ## Why a table and not a `reference_data_seeded` app setting
 *
 * Because the answer is per seeder, not per system, and because it has to be enforceable. A setting
 * holding a list of keys is one row that every seeder would have to READ-MODIFY-WRITE, which is
 * both a write to existing data (`docs/QUEUE.md` forbids it) and a lost-update race the moment two
 * admins log in together. One row per key with a UNIQUE index makes the database the referee: the
 * second writer's INSERT is refused and its whole transaction — the seeded rows included — rolls
 * back. There is no check-then-insert anywhere in the path.
 *
 * ## ADD only
 *
 * `docs/QUEUE.md`'s ADD-only rule is in force here; the waiver in that file covers `#645`'s column
 * widening and nothing else. This migration creates a table that did not exist and writes not one
 * byte to any table that did. In particular it does NOT pre-mark anything: an installation upgrading
 * to this code has a completely empty marks table, so every seeder runs once on the next admin
 * login, finds the rows the config screens lazily created months ago, creates none of them, and
 * marks itself. Pre-marking would have been a guess about which lists had ever been visited, and a
 * wrong guess would leave a list permanently unseedable.
 *
 * ## `seeded_at`
 *
 * A plain `DATETIME` string, matching how every other timestamp in this schema is stored under
 * SQLite. NOT NULL with no default — the writer always supplies it, and a mark with no date would
 * be a record of an event that does not say when it happened.
 *
 * ## The stamp
 *
 * `Version20260914113000` is above every stamp claimed on any ref, tag, working tree or reflog at
 * the time of writing — the highest found anywhere was `Version20260913120000`, with
 * `Version20260913090000` and `Version20260912161500` below it on main. Deliberately off the daily
 * `090000` pattern, for the reason `Version20260912161500` gives: two agents independently reaching
 * for "tomorrow at 09:00" is how two branches collide on one stamp and silently drop each other's
 * tables, and neither test suite would see it — `tests/_bootstrap.php` builds the schema from
 * Doctrine metadata and never runs the chain, so only `bin/ci-migration-replay` ever would.
 */
final class Version20260914113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Central reference data seeder: the per-seeder "already seeded" mark.';
    }

    public function up(Schema $schema): void
    {
        // seeder_key is the natural key and is declared by the seeder itself
        // (ReferenceDataSeederInterface::getKey()), namespaced by owning bundle. 120 bytes is
        // generous for 'inventory_depth.adjustment_reason' and is enforced in PHP as well, so a
        // longer key is refused rather than truncated into a collision with another key.
        $this->addSql(<<<'SQL'
            CREATE TABLE reference_data_seed_mark (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                seeder_key VARCHAR(120) NOT NULL,
                rows_created INTEGER DEFAULT 0 NOT NULL,
                seeded_at DATETIME NOT NULL
            )
            SQL);

        // THE guard. Not a nicety and not an optimisation: two admins logging in at the same instant
        // both reach the seeder with an empty marks table, and this index is the only thing that
        // stops both of them writing the rows. A UNIQUE INDEX rather than a UNIQUE column constraint
        // purely so it carries a name that says what it is in an error message.
        $this->addSql('CREATE UNIQUE INDEX uniq_reference_data_seed_mark_key ON reference_data_seed_mark (seeder_key)');
    }

    /**
     * Drops the table, which is the honest reversal here.
     *
     * Unlike most `down()`s in this chain there is nothing to preserve: every row in it is a record
     * this migration's own feature created, and no other table references it. Note the consequence
     * rather than hiding it — migrating down and back up leaves an installation with no marks, so
     * every seeder runs once more. It will find its rows present and create none, which is the
     * behaviour the interface requires of every implementation for exactly this reason.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_reference_data_seed_mark_key');
        $this->addSql('DROP TABLE reference_data_seed_mark');
    }
}
