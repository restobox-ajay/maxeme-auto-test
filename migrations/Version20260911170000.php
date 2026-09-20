<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteTableRebuild;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vendor bill parity: payment rows, charges, per-line tax class, and the province tax is computed
 * for (#658, queue item 15).
 *
 * ```
 * vendor_bill_payment           NEW TABLE     one row per payment made against a bill
 * vendor_bill.amount_paid       DROPPED       replaced by the sum of those rows
 * vendor_bill.charge_lines      CLOB NULL     freight / brokerage / duty / one-offs, as JSON
 * vendor_bill.tax_lines         CLOB NULL     the itemised tax breakdown, as JSON
 * vendor_bill.tax_province      VARCHAR(8)    which province's rules apply
 * vendor_bill_line.tax_code     VARCHAR(8)    'E' / 'G' / 'S' — the SHARED tax vocabulary
 * ```
 *
 * ## No `purchase_order_line.quantity_billed`, deliberately
 *
 * #658 lists a missing `quantityBilled` column as its first gap and this migration does not add
 * one. The figure is derived instead — see `PurchaseOrderLine::getQuantityBilled()`, which sums the
 * bill lines that point at the row. A bill is editable while it is a draft, can be voided, and
 * whether it counts depends on its status, so a stored column would need writing at save, approve,
 * void and dispute, and the one place that forgot would leave the column claiming a purchase order
 * line was billed for something no bill says. The sell side settled the same question the same way
 * (`SalesOrder`'s invoiced-quantity block), and two places holding one fact is the shape behind
 * #589, #590 and #591 here.
 *
 * ## Why `amount_paid` is DROPPED rather than kept in step
 *
 * Keeping it would be the defect this change exists to remove: a stored total beside the rows that
 * make it up is a second answer to one question, and it drifts the first time a payment is
 * corrected or deleted. The owner confirmed on 2026-09-11 that nothing is deployed and no real rows
 * exist anywhere, so there is no production figure to preserve — and the standing "never write to
 * existing data" rule is not bent here in any case: nothing in this file writes a value into an
 * existing row. Rows that hold a paid figure lose it along with the column, which is expected of
 * test and demo data.
 *
 * ## SQLite 3.26
 *
 * Production SQLite is 3.26, so `DROP COLUMN` (3.35) is unavailable and the column goes by the
 * classic rebuild through `App\Doctrine\SqliteTableRebuild` — copy out, drop, recreate from the
 * table's own CREATE minus the column, copy back, recreate the indexes — between `PRAGMA
 * foreign_keys = OFF` and `ON`, in a non-transactional migration because the pragma is ignored
 * inside a transaction. Precedent: `Version20260820160000`.
 *
 * The rebuild runs FIRST and the `ADD COLUMN`s after it, which is the order it has to be: the
 * rebuild's statements are computed from `sqlite_master` at the time `up()` assembles them, so a
 * column added earlier in the same file would not be in the CREATE it copies and would be dropped
 * again by it.
 *
 * ## No backfill, and existing rows still read correctly
 *
 * Every added column arrives NULL and there is no `UPDATE` anywhere here, because NULL already says
 * the true thing:
 *
 *   - `charge_lines IS NULL` means "no charges", which every bill that exists truthfully has —
 *     there has never been a way to put one on;
 *   - `tax_lines IS NULL` means "no itemised breakdown". A bill entered before this change keeps
 *     whatever `tax` figure was typed onto it and keeps its stored `total`; nothing recomputes
 *     behind a reader's back, so a bill still reads as it always did. The figures are re-derived
 *     only when somebody saves the draft again, which is a person deciding to;
 *   - `tax_province IS NULL` means "nobody has said", which is the truth for every existing row;
 *   - `vendor_bill_line.tax_code IS NULL` maps to 'E', Exempt, via `TaxContext::mapTaxCode()` —
 *     both the only default that cannot overcharge and an accurate statement about lines nothing
 *     was ever taxing.
 *
 * ## Risk
 *
 * Low, and stated rather than assumed. `vendor*` and `purchase_*` have never been deployed anywhere
 * but dev — production is the sales-only app — so these tables hold zero production rows. No
 * deployed table (`company_*`, `order_*`, `invoice_*`, `product_*`) is touched.
 *
 * ## Reversibility
 *
 * `down()` restores `amount_paid` as an empty column and drops the new table; it cannot drop the
 * three added columns on SQLite 3.26 and does not pretend to. It does NOT reconstruct a paid figure
 * from the payment rows it is about to delete — that would be writing data during a migration, and
 * a sum of rows is exactly the thing the column could not be trusted to hold.
 */
final class Version20260911170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#658: vendor bill payment rows, charges, per-line tax class, tax province; drops vendor_bill.amount_paid.';
    }

    /** Rebuilds vendor_bill (production SQLite 3.26 has no DROP COLUMN); see SqliteTableRebuild. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN');
        foreach (SqliteTableRebuild::statements($this->connection, 'vendor_bill', ['amount_paid' => null]) as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');

        $this->addSql('ALTER TABLE vendor_bill ADD COLUMN charge_lines CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor_bill ADD COLUMN tax_lines CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor_bill ADD COLUMN tax_province VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor_bill_line ADD COLUMN tax_code VARCHAR(8) DEFAULT NULL');

        // Constraint and index names spelled out rather than left as Doctrine's hashes, matching
        // the other procurement tables. ON DELETE CASCADE on the bill: a payment is part of the
        // document that owes the money, unlike a receipt, which is evidence in its own right.
        $this->addSql(<<<'SQL'
            CREATE TABLE vendor_bill_payment (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                vendor_bill_id INTEGER NOT NULL,
                user_id INTEGER DEFAULT NULL,
                paid_at DATE NOT NULL,
                method VARCHAR(64) NOT NULL,
                amount NUMERIC(12, 2) NOT NULL,
                comment VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT fk_vendor_bill_payment_bill FOREIGN KEY (vendor_bill_id) REFERENCES vendor_bill (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT fk_vendor_bill_payment_user FOREIGN KEY (user_id) REFERENCES admin_user (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_bill_payment_bill ON vendor_bill_payment (vendor_bill_id)');
        $this->addSql('CREATE INDEX idx_bill_payment_user ON vendor_bill_payment (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE vendor_bill_payment');
        $this->addSql("ALTER TABLE vendor_bill ADD COLUMN amount_paid NUMERIC(12, 2) DEFAULT '0.00' NOT NULL");
    }
}
