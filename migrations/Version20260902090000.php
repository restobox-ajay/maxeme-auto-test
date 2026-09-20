<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Voiding a receipt (#613): four new nullable columns on `purchase_receipt`. Nothing else.
 *
 * A receipt booked against the wrong purchase order or the wrong warehouse is the one receiving
 * mistake with permanent consequences — `purchase_order_line.quantity_received` stays credited to a
 * line nothing arrived against, the derived PO status is computed from those figures, and the
 * three-way match compares a bill to a delivery that did not happen. An inventory adjustment fixes
 * the stock and reaches none of that.
 *
 * The withdrawal is a SECOND FACT and not an unwrite: the receipt keeps its number, its lines and
 * its original `movement_group_id`, and gains a stamp saying when it was withdrawn, by whom, why,
 * and which movement group put the stock back out. `void_movement_group_id` sits BESIDE
 * `movement_group_id` and never replaces it, so the ledger holds both halves forever.
 *
 * ## NO BACKFILL. NOTHING EXISTING IS READ OR WRITTEN.
 *
 * Every column is NULL for every receipt that exists, and `voided_at IS NULL` is exactly what
 * PurchaseReceipt::isVoided() answers false to — which is what every one of those receipts already
 * behaved as. A database that runs this migration and stops computes precisely the numbers it
 * computed before.
 *
 * In particular, no receipt is inferred to have been voided. There is no signal in existing data
 * that could say so: a receipt whose stock was later adjusted away looks identical to one whose
 * goods were sold, and guessing between them would invent a decision nobody made and attribute it
 * to nobody.
 *
 * ## ADD COLUMN, four times, and no table rebuild
 *
 * `ALTER TABLE ... ADD COLUMN`, which SQLite performs in place. Doctrine emulates anything else —
 * dropping a column, adding a constraint to an existing one — by rebuilding the table through a temp
 * copy, and Version20260730150000's history is why that is avoided unless unavoidable: a rebuild is
 * a DROP TABLE, `PRAGMA foreign_keys = OFF` is silently ignored inside a transaction, and
 * `purchase_receipt_line` cascades on `purchase_receipt_id`. Rebuilding this table to gain one
 * FOREIGN KEY would risk every receipt line in the database to enforce a nullable reference that
 * only this application writes.
 *
 * So `void_movement_group_id` carries no FOREIGN KEY on a MIGRATED database, while one built from
 * entity metadata (both test suites, via SchemaTool) does have it — the same deliberate divergence
 * Version20260901090000 records for `credit_memo.sales_return_id`, and `bin/ci-migration-replay`
 * compares column NAMES rather than constraints for exactly this reason.
 */
final class Version20260902090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#613: purchase_receipt gains voided_at, voided_by, void_reason and void_movement_group_id. No backfill.';
    }

    public function up(Schema $schema): void
    {
        // The flag every reader goes by. NULL means "not voided", which is what every existing row
        // already is.
        $this->addSql('ALTER TABLE purchase_receipt ADD COLUMN voided_at DATETIME DEFAULT NULL');

        // Who decided. Free text and the same width as `received_by` beside it, because the person
        // who withdraws a receipt is identified the same way the person who booked it is.
        $this->addSql('ALTER TABLE purchase_receipt ADD COLUMN voided_by VARCHAR(160) DEFAULT NULL');

        // Why. Demanded by the action rather than by the column — PurchaseReceipt::markVoided()
        // refuses an empty one — for the reason PurchaseOrder::closeShort() demands a reason: a
        // decision with nothing on it is unauditable a fortnight later.
        $this->addSql('ALTER TABLE purchase_receipt ADD COLUMN void_reason CLOB DEFAULT NULL');

        // The group that put the stock back OUT. Beside movement_group_id, never in place of it.
        $this->addSql('ALTER TABLE purchase_receipt ADD COLUMN void_movement_group_id INTEGER DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_purchase_receipt_void_group ON purchase_receipt (void_movement_group_id)');
    }

    public function down(Schema $schema): void
    {
        // The index goes; the columns stay. SQLite emulates DROP COLUMN by rebuilding the table
        // through a temp copy, which is a DROP TABLE that cascades to purchase_receipt_line — the
        // exact failure mode Version20260730150000 shipped once already, reporting success while
        // deleting everything. Four nullable orphan columns cost nothing; every receipt line in the
        // database costs the warehouse its record of what arrived.
        $this->addSql('DROP INDEX IF EXISTS IDX_purchase_receipt_void_group');
    }
}
