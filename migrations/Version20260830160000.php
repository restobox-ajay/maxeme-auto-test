<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reason-first stock adjustments: the reason becomes a row, and a reversal points at what it undoes
 * (#585).
 *
 * ## Purely additive
 *
 * One new table, eight rows in it, and two nullable columns on tables that already exist. No table
 * is rebuilt, no column is dropped, **no existing row is read or written**. Replaying the chain over
 * real data therefore cannot lose any of it — the failure mode `bin/ci-migration-replay` exists to
 * catch, and the one that made Version20260730150000 and Version20260827100000 delicate.
 *
 * The SQLite floor stays 3.26: no `DROP COLUMN` (3.35), no `ALTER COLUMN`, and both `ADD COLUMN`s
 * take a NULL default, which is the form SQLite accepts on a table that already has rows.
 *
 * ## What each piece is for
 *
 * `inventory_adjustment_reason` — WHAT HAPPENED, as a configurable row. `inventory_movement_group
 * .reason` stays exactly what it is, free text, and keeps every value it holds; the code sits beside
 * it in `adjustment_reason_id`. See InventoryAdjustmentReason for why a reason had to stop being a
 * sentence: nothing can be reported on free text and nothing can map it to a G/L account, which is
 * what `gl_account` is reserved for and why it is here from the start rather than added later over
 * whatever text had accumulated in the meantime.
 *
 * `inventory_movement.reverses_movement_id` — the entry a reversal undoes. Per MOVEMENT and not per
 * group, because a group carries many lines and a write-off of 300 units across four bins is one
 * group and four movements; somebody finding one of those pallets intact reverses one of them.
 * Dynamics ties a reversal to the original Item Ledger Entry for the same reason. It is also the
 * whole guard on the operation:
 *
 *     remaining = original.quantity − SUM(quantity WHERE reverses_movement_id = original.id)
 *
 * which refuses both "reverse 500 when 12 were written off" and "reverse the same 12 twice" without
 * either being validated separately.
 *
 * ## Eight seeded reasons, and why seeding is not a backfill
 *
 * `INSERT` into a table created three statements earlier touches no data anybody has. These are the
 * eight the screen ships with; an admin can relabel them, deactivate them, set a G/L account on
 * them or add their own. `InventoryAdjustmentReasonRepository::ensureCatalogue()` recreates a
 * MISSING code lazily — which on a migrated database never fires, and on a schema built from entity
 * metadata (both test suites, neither of which runs a migration) fires once — and it deliberately
 * leaves an existing row exactly as it stands, so a relabelled reason is not silently put back.
 *
 * The values here and the values in InventoryAdjustmentReason::defaults() are the same eight
 * because the entity is the one source; EveryAdjustmentReasonIsDerivableTest asserts the partition
 * over that list, and MigrationSeedsTheSameReasonsAsTheCodeTest asserts that this file has not
 * drifted from it.
 *
 * ## NOTHING IS CLASSIFIED
 *
 * `inventory_movement_group.adjustment_reason_id` lands as NULL on every existing group and stays
 * NULL. Dev holds "Opening balance on switching to dimensional inventory" (84), "Received without a
 * purchase order" (45) and `E2E <img src=x onerror=alert(1)>` (2), and a rule mapping those onto the
 * new codes is guesswork about somebody else's warehouse: an opening balance is not any of the eight
 * (it is a switch, not a physical event), and "received without a purchase order" is a receipt, which
 * is a document this screen deliberately does not author. The SQL that WOULD classify them, for an
 * owner who knows what their own free text meant, is in the pull request rather than here —
 * precisely so it is run by somebody who has checked.
 *
 * A fresh instance is unaffected: eight reasons, no groups pointing at any of them yet, and every
 * later adjustment carrying its code from the moment it is written.
 *
 * ## Timestamp
 *
 * Above Version20260830140000, the previous end of the chain, with a deliberate gap for #582 which
 * is being rebased in parallel and adds one of its own. Two parallel stages picking the same number
 * is a merge conflict that only surfaces when they meet.
 */
final class Version20260830160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#585: reason-first adjustments — inventory_adjustment_reason, and a reversal link on inventory_movement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inventory_adjustment_reason (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            code VARCHAR(48) NOT NULL,
            label VARCHAR(120) NOT NULL,
            from_status VARCHAR(16) DEFAULT NULL,
            to_status VARCHAR(16) DEFAULT NULL,
            reversal BOOLEAN DEFAULT 0 NOT NULL,
            gl_account VARCHAR(64) DEFAULT NULL,
            help VARCHAR(255) DEFAULT NULL,
            sort_key INTEGER DEFAULT 0 NOT NULL,
            active BOOLEAN DEFAULT 1 NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_adjustment_reason_code ON inventory_adjustment_reason (code)');

        // The two that put stock BACK come first on purpose. They share a physical trigger — a box
        // turned up — and have opposite effects on the books, so the screen has to put that choice
        // in front of somebody rather than bury it between "Lost" and "Scrapped".
        //
        // Literal SQL rather than a call into InventoryAdjustmentReason::defaults(). A migration has
        // to produce the same rows in five years as it did the day it ran, and a migration that
        // reads a class the application keeps editing does not — replaying the chain would then
        // depend on the current shape of the code rather than on this file. The cost is that the
        // eight rows are written down twice, and MigrationSeedsTheSameReasonsAsTheCodeTest is what
        // stops the two copies drifting.
        foreach ([
            ['stock_found', 'Stock found', 'NULL', "'available'", 0, 10,
                'Units on the shelf the system has never counted. They enter the ledger here, so use this only when nothing was previously written off — otherwise the loss stays on the books and the same goods are invented a second time.'],
            ['reverse_write_off', 'Reverse a write-off', 'NULL', "'available'", 1, 20,
                'Stock that was written off and has turned up. Pick the entry that wrote it off; the bin, lot and serial come back from that entry, so nothing is retyped and the loss comes off the books.'],
            ['damaged', 'Damaged', "'available'", "'damaged'", 0, 30,
                'Physically broken and not sellable. Still on the premises and still countable.'],
            ['spoiled', 'Spoiled / expired', "'available'", "'expired'", 0, 40,
                'Past its date, found by a person rather than by the nightly lot sweep. The lot list defaults to batches at or past expiry, which is what this reason is for.'],
            ['scrapped', 'Scrapped', "'available'", "'scrapped'", 0, 50,
                'Deliberately destroyed or disposed of. Keeps its warehouse and drops its bin, so which site scrapped it stays answerable.'],
            ['lost', 'Lost / shrinkage', "'available'", "'lost'", 0, 60,
                'Missing and not explained. If it turns up later, reverse this entry rather than recording it as stock found.'],
            ['hold', 'Put on hold', "'available'", "'quarantine'", 0, 70,
                'Held back pending somebody\'s decision. Physically present and countable, and it may well come back.'],
            ['release_hold', 'Release from hold', "'quarantine'", "'available'", 0, 80,
                'Ruled fit to sell again. Pick the held rows it comes off, because a hold is always against particular goods.'],
        ] as [$code, $label, $from, $to, $reversal, $sort, $help]) {
            $this->addSql(sprintf(
                "INSERT INTO inventory_adjustment_reason (code, label, from_status, to_status, reversal, gl_account, help, sort_key, active)"
                . " VALUES ('%s', '%s', %s, %s, %d, NULL, '%s', %d, 1)",
                $code,
                str_replace("'", "''", $label),
                $from,
                $to,
                $reversal,
                str_replace("'", "''", $help),
                $sort,
            ));
        }

        $this->addSql('ALTER TABLE inventory_movement ADD COLUMN reverses_movement_id INTEGER DEFAULT NULL REFERENCES inventory_movement (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_movement_reverses ON inventory_movement (reverses_movement_id)');

        // ON DELETE SET NULL rather than a cascade: deleting a reason must never delete history.
        // Retiring one is `active = 0`, and the repository's lookup refuses an inactive code on the
        // way in, so a deactivated reason stops being recordable without any group losing its label.
        $this->addSql('ALTER TABLE inventory_movement_group ADD COLUMN adjustment_reason_id INTEGER DEFAULT NULL REFERENCES inventory_adjustment_reason (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_movement_group_reason ON inventory_movement_group (adjustment_reason_id)');
    }

    public function down(Schema $schema): void
    {
        // The two columns stay. This SQLite predates DROP COLUMN, and nothing reads them once the
        // code is rolled back; no existing row was touched on the way in, so there is none to put
        // back. The table can go, because it did not exist before this ran and nothing outside this
        // feature points at it.
        $this->addSql('DROP INDEX IF EXISTS idx_movement_group_reason');
        $this->addSql('DROP INDEX IF EXISTS idx_movement_reverses');
        $this->addSql('DROP INDEX IF EXISTS uniq_adjustment_reason_code');
        $this->addSql('DROP TABLE IF EXISTS inventory_adjustment_reason');
    }
}
