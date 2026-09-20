<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vendor master data (#605, #606): two new tables and fourteen new columns. Nothing else.
 *
 * The buy side gets the shape the sell side has had all along — people to speak to, notes with an
 * author and a date, addresses that know what they are for — MINUS every authentication column,
 * which is the one deliberate exclusion and a security boundary rather than a preference.
 *
 * ## NO BACKFILL. NOT ONE EXISTING ROW IS READ AND NOT ONE IS WRITTEN.
 *
 * Both new tables are created EMPTY. Every new column is nullable or defaults to 0/false, and every
 * vendor, address, receipt and bill that exists reads exactly as it read before:
 *
 *   - `vendor_contact` and `vendor_note` have no rows, so every vendor has no contacts and no notes,
 *     which is what every vendor had yesterday.
 *   - `vendor.payment_term_id` is NULL everywhere. In particular NOTHING matches the free text in
 *     `vendor.payment_term` against `payment_term.name` to fill it in. "Net 30 EOM" typed by hand is
 *     not automatically the "Net 30" row, "2/10 net 30" is not "Net 30", and a match that looks like
 *     somebody's decision and is nobody's would be applied to the whole vendor file at once. That
 *     mapping is a human judgement per instance; the SQL for it is in the issue's report, unrun.
 *   - `vendor_address`'s four purpose flags are all 0, which means no address claims a purpose,
 *     which is exactly the state `Vendor::getOrderToAddress()` and its three siblings fall back out
 *     of — every one of them returns `getDefaultAddress()`, the same address `PurchaseOrder::issue()`
 *     froze before purposes existed. Deciding that the Mississauga row is the remit-to is a
 *     per-vendor judgement and is not inferable: an address that receives goods looks identical in
 *     this database to one that receives cheques.
 *   - `purchase_receipt.ship_from_address` and `vendor_bill.remit_to_address` are NULL on every
 *     existing document, and stay NULL. Freezing today's address onto a two-year-old bill would
 *     assert where that bill was paid, which nobody recorded and nobody now knows. NULL means "we
 *     did not record it" and has to stay distinguishable from a recorded blank.
 *
 * A database that runs this migration and stops shows the same purchase orders with the same
 * addresses and the same terms.
 *
 * ## CREATE TABLE and ADD COLUMN only — no table is rebuilt
 *
 * Two `CREATE TABLE`s, which touch nothing that exists, and fourteen `ALTER TABLE ... ADD COLUMN`s,
 * which SQLite performs in place. Doctrine emulates anything else — dropping a column, adding a
 * constraint to an existing one — by rebuilding the table through a temp copy, and
 * Version20260730150000's history is why that is avoided unless unavoidable: a rebuild is a DROP
 * TABLE, `PRAGMA foreign_keys = OFF` is silently ignored inside a transaction, and the children
 * cascade. `purchase_order_line`, `purchase_receipt_line` and `vendor_bill_line` all cascade on
 * their parent, so rebuilding any of these tables to gain a nullable column would put every line in
 * the database at risk for nothing.
 *
 * Every ADD COLUMN default is a constant (NULL, or 0 for the booleans), which is the only kind
 * SQLite accepts on ADD COLUMN and the only kind that could be honest here anyway.
 *
 * ## `vendor_address.province` and `.country` are NOT widened
 *
 * #605 raised that these are VARCHAR(8)/VARCHAR(2) holding codes while `company_address` is
 * VARCHAR(120)/VARCHAR(100) holding names, and asked which is right rather than widening one to
 * match. The answer is codes, and it is already written down in `App\Service\Region`: "addresses
 * store codes, so the Context value objects compare 'BC' === 'BC' with no lookup; normalisation
 * happens once, at the write boundary." `app:seed-regions` maintains the reference data that
 * resolves a code to a name for display. So this migration widens nothing — a silent widening would
 * leave 'BC' and 'British Columbia' in one column with nothing able to tell them apart, which is the
 * sell side's problem and not one worth spreading. VendorController normalises through Region on
 * save and the screen shows the resolved name beside the code.
 *
 * ## What is NOT here, and why
 *
 * `custom_field_value_vendor` (#605 item 6). It cannot be built from a bundle: the object-type
 * registry is three private constants in core — `CustomFieldDefinition::OBJECT_TYPE_*`,
 * `CustomFieldValueRepository::MAP` and `CustomFieldController::OBJECT_TYPES` — with no extension
 * point, and a bundle cannot add an entry to any of them. Reported rather than forced.
 */
final class Version20260904090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#605/#606: add vendor_contact and vendor_note; contact fields and four purposes on vendor_address; vendor.payment_term_id; purchase_receipt.ship_from_address; vendor_bill.remit_to_address. No backfill.';
    }

    public function up(Schema $schema): void
    {
        // ---------------------------------------------------------------- vendor_contact (#605)
        //
        // `customer_user`'s shape MINUS every authentication column. There is no `password`, no
        // `roles`, no `reset_token`, no `reset_token_expires_at`, no `last_login_at` and no
        // `api_enabled`, and none of them is coming later: a supplier's staff have no account here,
        // so there is nothing for a credential to authenticate and nothing for a role to authorise.
        //
        // `email` carries NO unique index, where `customer_user.email` does. That constraint exists
        // there because the column IS the username the firewall looks a person up by. Nothing looks
        // anybody up by this one, and two people at a supplier really do share
        // orders@supplier.example — a uniqueness rule would refuse a true fact to protect an
        // identity that does not exist.
        $this->addSql(
            'CREATE TABLE vendor_contact ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'vendor_id INTEGER NOT NULL, '
            . 'first_name VARCHAR(120) DEFAULT NULL, '
            . 'last_name VARCHAR(120) DEFAULT NULL, '
            . 'email VARCHAR(180) DEFAULT NULL, '
            . 'phone VARCHAR(60) DEFAULT NULL, '
            . 'job_title VARCHAR(120) DEFAULT NULL, '
            . 'is_primary BOOLEAN DEFAULT 0 NOT NULL, '
            . "status VARCHAR(16) DEFAULT 'Active' NOT NULL, "
            . 'created_at DATETIME NOT NULL, '
            . 'CONSTRAINT FK_vendor_contact_vendor FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );
        $this->addSql('CREATE INDEX idx_vendor_contact_vendor ON vendor_contact (vendor_id)');

        // ------------------------------------------------------------------- vendor_note (#605)
        //
        // `company_note`'s shape: one row per note, with an author and a real timestamp. NOT a
        // packed string — CompanyNote's docblock lists the bugs that encoding produced on the sell
        // side, every one of them a consequence of the format rather than the logic.
        //
        // `vendor.notes` (the single CLOB) IS NOT READ, NOT SPLIT AND NOT DROPPED. It stays as the
        // record of what was written before there was anywhere better to put it, the way #601 keeps
        // `product_core.unit`. Splitting free text into rows means guessing where one note ends and
        // attributing each guess to an author and a date nobody recorded.
        $this->addSql(
            'CREATE TABLE vendor_note ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'vendor_id INTEGER NOT NULL, '
            . 'user_name VARCHAR(255) DEFAULT NULL, '
            . 'text CLOB NOT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'CONSTRAINT FK_vendor_note_vendor FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );
        $this->addSql('CREATE INDEX idx_vendor_note_vendor ON vendor_note (vendor_id)');

        // ------------------------------------------------------- vendor.payment_term_id (#605)
        //
        // The same shape `company.payment_term_id` has: a plain nullable INTEGER, no FOREIGN KEY,
        // because `payment_term` is admin-managed raw SQL outside the ORM (one of the tables
        // RawSqlTablesSurviveTheChainTest protects) and neither side declares a constraint against
        // it. `vendor.payment_term` stays exactly where it is and keeps its value — it is what every
        // purchase order snapshots, and dropping it would break documents to tidy a column.
        $this->addSql('ALTER TABLE vendor ADD COLUMN payment_term_id INTEGER DEFAULT NULL');

        // --------------------------------------------- vendor_address contact fields (#605)
        //
        // `company_address`'s contact block, mirrored. A purchase order's "Order From" could print
        // where a supplier is and not one word about who to ask for when the delivery is short.
        //
        // `contact_phone` rather than `phone` only because the shorter name reads as the vendor's
        // switchboard, which lives on `vendor.phone`; this is the number for THIS location.
        $this->addSql('ALTER TABLE vendor_address ADD COLUMN first_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor_address ADD COLUMN last_name VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor_address ADD COLUMN company_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor_address ADD COLUMN email_primary VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor_address ADD COLUMN email_secondary VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor_address ADD COLUMN contact_phone VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor_address ADD COLUMN fax VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor_address ADD COLUMN delivery_instructions CLOB DEFAULT NULL');

        // ------------------------------------------------- vendor_address purposes (#606)
        //
        // FOUR FLAGS, NOT ONE `purpose` ENUM, and the difference matters in both directions. A small
        // supplier is one location that is the orders desk, the shipping depot, the remittance
        // address and the returns address at once; an enum would force four duplicate rows for that
        // one address, which then drift apart the day somebody corrects one of them. Booleans let
        // one row wear several hats, which is the common case, and several rows split the hats,
        // which is the case that matters — a factoring company's lockbox is genuinely not where the
        // goods leave from.
        //
        // All 0. `is_default` KEEPS ITS VALUES and stays the fallback every purpose resolves
        // through, which is what makes this migration inert: nothing changes about which address a
        // document freezes until a human ticks a box.
        $this->addSql('ALTER TABLE vendor_address ADD COLUMN is_order_to BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE vendor_address ADD COLUMN is_ship_from BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE vendor_address ADD COLUMN is_remit_to BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE vendor_address ADD COLUMN is_return_to BOOLEAN DEFAULT 0 NOT NULL');

        // ------------------------------------------- document snapshots of a purpose (#606)
        //
        // Frozen copies taken at document time, beside `purchase_order.vendor_address` which has
        // always worked this way. NULL on every existing row and never filled in for one: today's
        // address is not evidence of where last year's delivery came from or where last year's bill
        // was paid.
        $this->addSql('ALTER TABLE purchase_receipt ADD COLUMN ship_from_address CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor_bill ADD COLUMN remit_to_address CLOB DEFAULT NULL');
    }

    /**
     * Reversible, with one honest asymmetry.
     *
     * The two tables are created by this migration, so dropping them loses only this feature's own
     * data — rolling the feature back is rolling those rows back.
     *
     * The fourteen columns are NOT dropped. Dropping a column under SQLite is a Doctrine-emulated
     * table rebuild — a DROP TABLE and a copy — on `vendor`, `vendor_address`, `purchase_receipt`
     * and `vendor_bill`, three of which have cascading children. Risking every purchase receipt line
     * and vendor bill line in the database to remove four nullable columns nothing reads is a far
     * worse trade than leaving them in place, where a rolled-back application ignores them exactly
     * as it ignored their absence. Version20260902090000 records the same decision for the same
     * reason.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE vendor_note');
        $this->addSql('DROP TABLE vendor_contact');
    }
}
