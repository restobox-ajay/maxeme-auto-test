<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Purchase order parity: charges, per-line tax class, and the province tax is computed for (#655).
 *
 * Four `ADD COLUMN`s across two tables. **Nothing is renamed, nothing is rebuilt, nothing is
 * written.**
 *
 * ```
 * purchase_order.charge_lines    CLOB NULL       freight / brokerage / duty / one-offs, as JSON
 * purchase_order.tax_lines       CLOB NULL       the itemised tax breakdown, as JSON
 * purchase_order.tax_province    VARCHAR(8) NULL which province's rules apply
 * purchase_order_line.tax_code   VARCHAR(8) NULL 'E' / 'G' / 'S' — the SHARED tax vocabulary
 * ```
 *
 * ## Why columns and not a `purchase_order_charge_line` table
 *
 * Because the sell side has no such table and deliberately got rid of the one it had.
 * `App\Service\SalesDocumentChargeLines` says so outright: *"there is no charge_lines column any
 * more, and this class no longer has one to be named after (issue #165 step 8)"*. What a sales
 * document actually stores is two CLOB snapshots on the document itself — `fee_lines` and
 * `tax_lines` — and `AbstractSalesDocument` states that as the established shape. Mirroring the
 * shape therefore means snapshot columns; a new table would be re-introducing exactly what the
 * sell side threw away, to buy a queryability nothing asks for (cross-document charge reporting is
 * `#599`, which is parked).
 *
 * It is also the smaller, safer migration: four `ADD COLUMN`s, no indexes, no cascade, no rebuild.
 *
 * ## SQLite 3.26
 *
 * Production SQLite is 3.26 and local is 3.45, so anything newer passes here and is rejected there.
 * `ADD COLUMN` has been available since forever; what this migration deliberately does NOT use is
 * `DROP COLUMN` (3.35), `RETURNING` (3.35), generated columns (3.31) or `IIF()` (3.32).
 *
 * That constraint is also why the now-derived `purchase_order.tax` column STAYS. It used to hold a
 * number an admin typed into the form and now holds the sum of `tax_lines`, which is precisely what
 * `sales_order.tax` has always held. Dropping it would need a 3.35 feature or a full table rebuild
 * — and the rebuild is the operation that cascaded away every address snapshot in
 * `Version20260730150000` and reported success. A column whose meaning narrowed is not worth that.
 *
 * ## No backfill, and existing rows still read correctly
 *
 * All four columns arrive NULL on every row, and there is no `UPDATE` anywhere in this file,
 * because NULL already says the true thing:
 *
 *   - `charge_lines IS NULL` means "no charges", which every purchase order that exists truthfully
 *     has: there has never been a way to put one on.
 *   - `tax_lines IS NULL` means "no itemised breakdown". A purchase order raised before this change
 *     keeps whatever `tax` figure was typed onto it and keeps its stored `total`; nothing
 *     recomputes behind a reader's back, so a printed PO still prints what it always printed. The
 *     figures are only re-derived when somebody saves the draft again, which is a person deciding
 *     to, not a migration doing it.
 *   - `tax_province IS NULL` means "nobody has said", which is the truth for every existing row.
 *   - `purchase_order_line.tax_code IS NULL` maps to 'E', Exempt, via
 *     `App\Contract\Tax\TaxContext::mapTaxCode()`. That is both the only default that cannot
 *     overcharge and an accurate statement about lines that nothing was ever taxing.
 *
 * Writing a backfill would also be forbidden: this repository's standing rule is that migrations
 * add columns and tables and never write to existing data.
 *
 * ## Risk
 *
 * Low, and stated rather than assumed. `purchase_*` has never been deployed anywhere but dev — the
 * production application is sales-only — so both tables hold zero production rows. The deployed
 * tables (`company_*`, `order_*`, `invoice_*`, `product_*`) are not touched by this migration at
 * all.
 *
 * ## Reversibility
 *
 * `down()` cannot drop these columns on SQLite 3.26 and does not pretend to. It throws with the
 * reason, which is what a migration that cannot honestly reverse itself should do rather than
 * silently succeeding. Rolling this back means restoring the database, and on a table with no
 * production rows that is not a real scenario.
 */
final class Version20260911200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#655: add purchase_order.charge_lines / tax_lines / tax_province and '
            . 'purchase_order_line.tax_code. ADD COLUMN only — no backfill, no rebuild, no rename.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_order ADD COLUMN charge_lines CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_order ADD COLUMN tax_lines CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_order ADD COLUMN tax_province VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_order_line ADD COLUMN tax_code VARCHAR(8) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Production SQLite is 3.26; DROP COLUMN needs 3.35. The alternative is
        // App\Doctrine\SqliteTableRebuild, which is a create-copy-drop-recreate — the operation
        // that silently cascaded away every address snapshot in Version20260730150000. Refusing
        // loudly beats either a syntax error on the server or a quiet data loss.
        $this->throwIrreversibleMigrationException(
            'Version20260911200000 adds columns only. SQLite 3.26 (the production floor) has no '
            . 'DROP COLUMN, and rebuilding purchase_order to remove four nullable columns risks far '
            . 'more than it reverses. Restore from backup if this genuinely has to be undone.',
        );
    }
}
