<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Barcodes (#607, #608, #609): one new table. Nothing else.
 *
 * ## NO BACKFILL. NOT ONE PRODUCT IS GIVEN A BARCODE.
 *
 * `product_barcode` is created EMPTY and stays empty until a person attaches a code on
 * `/admin/bundles/warehouse-ops/barcodes` or presses Generate on one product. In particular:
 *
 *   - Nothing copies `product_core.sku` into it. A SKU is this business's own identifier; a barcode
 *     is what is printed on the box. Asserting that every SKU is also a scannable barcode would be
 *     false for every product that arrived with a real UPC and false again for every product that
 *     arrived with none.
 *   - Nothing mints an internal code for the products that have no barcode. That is the whole
 *     catalogue on day one, and minting 7,000 numbers nobody has printed a label for would put a
 *     barcode on every shelf tag that matches nothing physically in the building.
 *   - Nothing reads `purchase_order_line.vendor_sku`. #607 raises it as the obvious source for
 *     vendor part numbers and it is the right source — as a deliberate, reviewed import, by a
 *     person who can see what it would produce. It was NULL on all 51 rows of the old data anyway.
 *
 * A database that runs this migration and stops behaves identically to one that has not:
 * `LabelCatalog::forProduct()` finds no primary barcode and encodes the SKU exactly as before, and
 * `ScanResolver` finds no barcode rows and matches on SKU exactly as before. Every screen that
 * mentions barcodes shows an empty list.
 *
 * The SQL to seed vendor part numbers from purchase order lines, for an instance that wants it, is
 * in the issue's report. It is not run here.
 *
 * ## CREATE TABLE only — no existing table is touched or rebuilt
 *
 * One `CREATE TABLE` and three `CREATE INDEX`. No `ALTER TABLE`, so nothing SQLite would emulate by
 * rebuilding through a temp copy — the failure mode Version20260730150000 records, where a rebuild
 * is a DROP TABLE, `PRAGMA foreign_keys = OFF` is silently ignored inside a transaction, and the
 * children cascade. `product_core` in particular is a parent of a great many things and is not
 * altered here by so much as a comment.
 *
 * ## Why `product_id` cascades and nothing points the other way
 *
 * `ON DELETE CASCADE` on `product_id` means deleting a product takes its barcodes. That direction is
 * safe and correct: a barcode with no product is a row nothing can ever resolve. There is no column
 * anywhere on `product_core` pointing back here, so dropping this table removes barcodes and leaves
 * every product row byte-identical — which is what "removing the app must not take product data with
 * it" has to mean in a database rather than in a sentence.
 *
 * ## Why `code` is not unique on its own
 *
 * The unique index is `(product_id, code)`. The same string twice on ONE product is a typo. The same
 * string on TWO products is a fact about the world: two vendors legitimately use one part number for
 * two different things, and a global unique index would refuse to record that. What happens instead
 * is that scanning such a code resolves to nothing and names both products, which is the treatment
 * ScanResolver has always given a value that means two things.
 *
 * `idx_product_barcode_code` is what makes the scan lookup a single index seek rather than a table
 * scan on every scan at a receiving dock.
 */
final class Version20260905090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#607: add product_barcode — many scannable identifiers per product. Created empty; no product is given a barcode.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE product_barcode ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'product_id INTEGER NOT NULL, '
            // 64 characters: a GTIN-14 is fourteen, and the longest vendor part numbers anyone
            // prints on a carton are well inside this. Code 128 stops being printable on ordinary
            // label stock long before a value gets near it.
            . 'code VARCHAR(64) NOT NULL, '
            // upc | ean | gtin | vendor_part | customer_part | internal. Not a CHECK constraint:
            // adding a seventh kind would then be an ALTER, which under SQLite is a table rebuild,
            // and the entity is where the list is stated and enforced.
            . 'kind VARCHAR(24) NOT NULL, '
            // Whose code this is, as a person reads it off the carton. Deliberately NOT a foreign
            // key to `vendor` or `company`: a product's identifier must not depend on ProcurementBundle
            // being installed for the row to be readable.
            . 'party VARCHAR(120) DEFAULT NULL, '
            . 'is_primary BOOLEAN DEFAULT 0 NOT NULL, '
            . 'note VARCHAR(255) DEFAULT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'CONSTRAINT FK_product_barcode_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );

        $this->addSql('CREATE UNIQUE INDEX uniq_product_barcode_product_code ON product_barcode (product_id, code)');
        $this->addSql('CREATE INDEX idx_product_barcode_code ON product_barcode (code)');
        $this->addSql('CREATE INDEX idx_product_barcode_product ON product_barcode (product_id)');
    }

    /**
     * Fully reversible, and honestly so: this migration created the table, so dropping it loses only
     * the rows this feature put there and touches nothing that existed before it.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_barcode');
    }
}
