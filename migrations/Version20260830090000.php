<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tracking policy: lot, serial and expiry declared per product instead of inferred (#573).
 *
 * ## Purely additive
 *
 * One new table, two new columns, and one UPDATE that points every existing product at the default
 * policy. No table is rebuilt, no column is dropped, no existing value is overwritten. Replaying the
 * chain over real data therefore cannot lose any of it — the failure mode `bin/ci-migration-replay`
 * exists to catch, and the one that made Version20260730150000 and Version20260827100000 delicate.
 *
 * Nothing here relies on `PRAGMA foreign_keys = OFF`, which is silently ignored inside a
 * transaction, because there is nothing to cascade. The SQLite floor stays **3.26**: no
 * `DROP COLUMN` (3.35), no `ALTER COLUMN`, and both `ADD COLUMN`s take a NULL or literal default,
 * which is the form SQLite accepts on a table that already has rows.
 *
 * ## Four seeded policies
 *
 * `None`, `Lot`, `Lot + expiry`, `Serial` — the set the ticket names, and the smallest set that
 * demonstrates each axis. They are ordinary rows: an admin can edit them, rename them or add their
 * own on the Tracking Policies screen. Only `None` is special, and only in that
 * `TrackingPolicyRepository::ensureDefault()` recreates it by name if it is missing.
 *
 * The three tracked ones ship `track_in = 1` and `track_out = 0`, which is what Dynamics calls
 * Active-but-not-Physical and is the common case: capture the identity from the supplier, do not
 * make a picker scan every unit on the way out.
 *
 * ## Every existing product points at None
 *
 * Which is the same thing as pointing at nothing —`TrackingPolicyRepository::policyFor()` answers
 * with an inert policy for a NULL — but stated rather than implied, so the admin screens have
 * something to show and nobody has to know that a blank means "none" rather than "not yet decided".
 *
 * ## No migration of historical rows
 *
 * `inventory_detail.expect_resolution` lands as 0 on every existing row, including the `[PENDING]`
 * bins and lots the import has written since #565. Those rows are fine as they are: the worklist
 * finds them by their sentinel value rather than by a flag, so backfilling one would be inventing a
 * decision — whether anyone will come back and identify them — that only a person can make.
 *
 * ## Timestamp
 *
 * Above Version20260827100000, the previous end of the chain, with a deliberate gap for whatever is
 * being built in parallel. Two parallel stages picking the same number is a merge conflict that only
 * surfaces when they meet.
 */
final class Version20260830090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#573: declare lot/serial/expiry tracking per product via a named tracking policy.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE tracking_policy (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(80) NOT NULL,
            mode VARCHAR(16) DEFAULT 'none' NOT NULL,
            requires_expiry BOOLEAN DEFAULT 0 NOT NULL,
            track_in BOOLEAN DEFAULT 0 NOT NULL,
            track_out BOOLEAN DEFAULT 0 NOT NULL,
            sentinel_in VARCHAR(64) DEFAULT NULL,
            sentinel_out VARCHAR(64) DEFAULT NULL
        )");
        $this->addSql('CREATE UNIQUE INDEX uniq_tracking_policy_name ON tracking_policy (name)');

        // The default carries no sentinels at all: there is no dimension for one to stand in for.
        $this->addSql("INSERT INTO tracking_policy (name, mode, requires_expiry, track_in, track_out, sentinel_in, sentinel_out)
            VALUES ('None', 'none', 0, 0, 0, NULL, NULL)");
        $this->addSql("INSERT INTO tracking_policy (name, mode, requires_expiry, track_in, track_out, sentinel_in, sentinel_out)
            VALUES ('Lot', 'lot', 0, 1, 0, '[PENDING]', NULL)");
        $this->addSql("INSERT INTO tracking_policy (name, mode, requires_expiry, track_in, track_out, sentinel_in, sentinel_out)
            VALUES ('Lot + expiry', 'lot', 1, 1, 0, '[PENDING]', NULL)");
        // Seeded blank, which means an unidentified serialised unit lands on a row with a NULL
        // serial rather than a labelled one. That is the DEFAULT, not a limitation: an admin who
        // types a value into this policy's sentinel gets exactly the lot-mode behaviour — one row,
        // quantity N, wearing the label.
        $this->addSql("INSERT INTO tracking_policy (name, mode, requires_expiry, track_in, track_out, sentinel_in, sentinel_out)
            VALUES ('Serial', 'serial', 0, 1, 0, NULL, NULL)");

        $this->addSql('ALTER TABLE product_core ADD COLUMN tracking_policy_id INTEGER DEFAULT NULL REFERENCES tracking_policy (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_product_core_tracking_policy ON product_core (tracking_policy_id)');
        $this->addSql("UPDATE product_core SET tracking_policy_id = (SELECT id FROM tracking_policy WHERE name = 'None')");

        $this->addSql('ALTER TABLE inventory_detail ADD COLUMN expect_resolution BOOLEAN DEFAULT 0 NOT NULL');
    }

    /**
     * SQLite 3.26 has no `DROP COLUMN`, so undoing the two columns would mean rebuilding
     * `product_core` and `inventory_detail` — and rebuilding `inventory_detail` cascades the whole
     * movement ledger away unless it is done in the inverted order Version20260827100000 spells out.
     * That is a large, destructive operation to undo two columns that are inert when unused, so this
     * drops the policy rows and leaves the columns, which is the reversal that cannot lose anything.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE product_core SET tracking_policy_id = NULL');
        $this->addSql('DROP INDEX IF EXISTS uniq_tracking_policy_name');
        $this->addSql('DROP TABLE IF EXISTS tracking_policy');
    }
}
