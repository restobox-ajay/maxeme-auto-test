<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #635: `vendor_address` adopts the customer side's names. Three columns renamed, nothing else.
 *
 * ```
 * vendor_address.address_1      ->  vendor_address.address_line1
 * vendor_address.address_2      ->  vendor_address.address_line2
 * vendor_address.contact_phone  ->  vendor_address.phone
 * ```
 *
 * ## NOT ONE VALUE IS WRITTEN
 *
 * There is no UPDATE in this file and no INSERT. `ALTER TABLE … RENAME COLUMN` changes the name a
 * column is addressed by and nothing about what is stored in it — SQLite rewrites the schema text
 * and leaves every page of data where it is. Row count, every cell, and the rowids are the same
 * before and after; the branch reports the `COUNT(*)` and a content checksum on both sides of it.
 *
 * ## Why a rename is allowed here when it is normally forbidden
 *
 * Migrations in this chain ADD. A rename is a breaking change for anything reading the old name, and
 * on a core table it would be unarguable. Two facts make this table the exception, and the owner
 * authorised it on both:
 *
 *   1. `vendor_address` was created five weeks ago by #605/#606 and has NEVER been deployed to
 *      production — production is frozen at sales-only and has no vendor side at all. The table
 *      exists on dev instances, where it holds six rows.
 *   2. The alternative — add-copy-drop across two releases — buys nothing when there is no live
 *      instance to keep readable in between, and costs a table rebuild on the way out.
 *
 * `company_address` is NOT touched here, and could not be: #635 made the shared superclass adopt
 * `company_address`'s existing column names precisely so that core's schema would not move. The
 * mapped-schema dump for `company_address`, `company_note` and `vendor_note` is byte-identical
 * before and after that refactor, which is why this file mentions only one table.
 *
 * ## Why RENAME COLUMN and not a rebuild
 *
 * `ALTER TABLE … RENAME COLUMN` (SQLite 3.25+) is not one of the statements SQLite emulates by
 * rebuilding the table through a temp copy. That matters: a rebuild is a DROP TABLE, and
 * Version20260730150000 records what that costs — `PRAGMA foreign_keys = OFF` is silently ignored
 * inside a transaction, so a rebuild cascades to children and deletes them while reporting success.
 * Renaming a column takes none of that path. `purchase_order.vendor_address` and
 * `purchase_receipt.ship_from_address` are frozen CLOB snapshots on other tables and are not
 * touched by any of this — no document already raised changes by a character.
 *
 * The three columns keep their exact definitions: `address_line1 VARCHAR(200) NOT NULL`,
 * `address_line2 VARCHAR(200) DEFAULT NULL`, `phone VARCHAR(40) DEFAULT NULL`. The buy side's
 * narrower widths and its NOT NULLs survive the move to the shared parent through
 * `#[ORM\AttributeOverrides]` on `VendorAddress`; sharing a vocabulary was the point, sharing
 * storage was not.
 */
final class Version20260908090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#635: rename vendor_address.address_1/address_2/contact_phone to address_line1/address_line2/phone, matching company_address. No data is written.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendor_address RENAME COLUMN address_1 TO address_line1');
        $this->addSql('ALTER TABLE vendor_address RENAME COLUMN address_2 TO address_line2');
        $this->addSql('ALTER TABLE vendor_address RENAME COLUMN contact_phone TO phone');
    }

    /**
     * Fully reversible, and losslessly so — a rename back is a rename, not a restore.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendor_address RENAME COLUMN phone TO contact_phone');
        $this->addSql('ALTER TABLE vendor_address RENAME COLUMN address_line2 TO address_2');
        $this->addSql('ALTER TABLE vendor_address RENAME COLUMN address_line1 TO address_1');
    }
}
