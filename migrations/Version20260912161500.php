<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Queue item 32: a warehouse gets an address, and a vendor bill stops asking a human for one.
 *
 * ## What this closes
 *
 * `warehouse` held a name, a status and a created-at, so a building did not know where it was. The
 * cost was paid on `vendor_bill.tax_province`: a person typed a province onto every single bill
 * because there was nowhere to read it from, and an 'AB' typed onto a Surrey warehouse's bill
 * computed GST-only where it should have been GST+PST with nothing in the system able to contradict
 * it. Same fact, answered independently every time, with the writable copy being the wrong one —
 * the shape behind #589, #590 and #591.
 *
 * Two changes, in one migration because they are one fact:
 *
 *  1. six address columns on `warehouse`, the application's settled address vocabulary (#635) at the
 *     widths `vendor_address` already uses — `province VARCHAR(8)`, `country VARCHAR(2)` hold CODES;
 *  2. `vendor_bill.warehouse_id`, the receiving warehouse the province is derived FROM.
 *
 * `vendor_bill.tax_province` is deliberately NOT dropped. It stays as the FROZEN SNAPSHOT it always
 * should have been: what changes is that nobody types into it — `VendorBill` has no
 * `setTaxProvince()` any more, only `deriveTaxProvinceFrom(?Warehouse)`, which refuses off Draft.
 * Moving a warehouse next year must not restate last year's tax, which is the same rule
 * `CompanyIdentity` states and `PurchaseOrder::issue()` applies to `$vendorAddress`. Dropping the
 * column and joining to the warehouse at render time would reintroduce exactly that drift.
 *
 * ## ADD only, and no backfill
 *
 * Every column is nullable with a NULL default, which is the form SQLite 3.26 accepts on a table
 * that already has rows, and no `UPDATE` follows. There is nothing honest to backfill: nobody has
 * ever recorded where these buildings are, and inferring a province from a warehouse's NAME would
 * be inventing the very fact this migration exists to record. Existing bills keep whatever province
 * was typed onto them, which is right — a bill is a record of what was said at the time.
 *
 * NULL is a first-class state throughout: a warehouse with no address must not break anything, and
 * `VendorBill::approve()` refuses to authorise a TAXABLE bill with no province rather than letting
 * it compute $0 from nothing.
 *
 * ## The stamp
 *
 * `Version20260912161500` is above everything claimed on any ref, tag, working tree or reflog at the
 * time it was written — the highest found anywhere was `Version20260911235400` (#659 step 4), with
 * `...235000` and `...235200` below it and unmerged stamps on other branches at `...235400` and
 * lower. Deliberately off the daily `090000` pattern every other migration here uses, because two
 * agents independently reaching for "tomorrow at 09:00" is exactly how two branches collide on one
 * stamp and silently drop each other's columns with no failing test — neither suite runs the chain
 * (`tests/_bootstrap.php` builds the schema from Doctrine metadata), so only `bin/ci-migration-replay`
 * would ever have seen it.
 */
final class Version20260912161500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Queue item 32: warehouse gets one address; vendor_bill names its receiving warehouse and derives tax_province from it.';
    }

    public function up(Schema $schema): void
    {
        // One address, not an address book: columns on `warehouse` itself rather than a
        // `warehouse_address` table. A building is one place, so "which of its addresses is the one
        // it is at" has no answer worth modelling. Names and widths are AbstractPartyAddress's, as
        // narrowed by VendorAddress's overrides, so nothing here is a fifth spelling of "postal
        // code" and a value copied warehouse -> vendor_bill.tax_province cannot be truncated.
        $this->addSql('ALTER TABLE warehouse ADD COLUMN address_line1 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE warehouse ADD COLUMN address_line2 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE warehouse ADD COLUMN city VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE warehouse ADD COLUMN province VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE warehouse ADD COLUMN postal_code VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE warehouse ADD COLUMN country VARCHAR(2) DEFAULT NULL');

        // The receiving warehouse the bill's province is derived from. Nullable: a standalone bill
        // entered before anybody says where the goods went is a real state, and every bill that
        // exists today is in it. ON DELETE SET NULL costs the provenance and never the tax — the
        // province is already frozen in tax_province, so an unlinked bill still says what it was
        // taxed at, it just stops saying which building answered.
        $this->addSql('ALTER TABLE vendor_bill ADD COLUMN warehouse_id INTEGER DEFAULT NULL REFERENCES warehouse (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_vendor_bill_warehouse ON vendor_bill (warehouse_id)');
    }

    /**
     * Blanks what was added; does not rebuild either table to remove the columns.
     *
     * Production SQLite is 3.26 and `ALTER TABLE ... DROP COLUMN` arrived in 3.35, so a true
     * reversal would mean a destructive copy-drop-recreate of `warehouse` and `vendor_bill` — and
     * `vendor_bill` is the table `bin/ci-migration-replay` exists to watch, because
     * `vendor_bill_line` and `vendor_bill_log` reference it `ON DELETE CASCADE` and `PRAGMA
     * foreign_keys = OFF` is silently ignored inside a transaction. Rebuilding two tables to remove
     * seven columns that are inert when unused would risk exactly the cascade that destroyed every
     * address snapshot in `Version20260730150000`, to undo something that costs nothing left in
     * place. Same reasoning as `Version20260911235000`'s down().
     *
     * Note what this deliberately does NOT blank: `vendor_bill.tax_province`. That column predates
     * this migration and holds values people typed; reversing the derivation must not erase them.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE warehouse SET address_line1 = NULL, address_line2 = NULL, city = NULL, province = NULL, postal_code = NULL, country = NULL');
        $this->addSql('UPDATE vendor_bill SET warehouse_id = NULL');
        $this->addSql('DROP INDEX IF EXISTS IDX_vendor_bill_warehouse');
    }
}
