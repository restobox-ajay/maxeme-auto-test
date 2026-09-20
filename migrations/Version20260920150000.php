<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `product_note`: the Product Inventory Hub's own notes log, shaped exactly like `company_note` and
 * `vendor_note` (see AbstractPartyNote) — one row per note, with an author and a real timestamp,
 * instead of another packed string.
 *
 * `product_core.remarks` (the existing CLOB the Product Details grid sorts and filters by, and the
 * product form still edits) is NOT read, split, or dropped. Same reasoning Version20260904090000
 * gives for `vendor.notes`: splitting free text into rows means guessing where one note ends and
 * attributing each guess to an author and a date nobody recorded. This is a second, independent
 * place to record something about a product, not a replacement for the first.
 *
 * One CREATE TABLE, which touches nothing that exists — no ALTER TABLE, no rebuild.
 */
final class Version20260920150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product_note (per-product notes log, shaped after company_note/vendor_note). No backfill from product_core.remarks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE product_note ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'product_id INTEGER NOT NULL, '
            . 'user_name VARCHAR(255) DEFAULT NULL, '
            . 'text CLOB NOT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'CONSTRAINT FK_product_note_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );
        $this->addSql('CREATE INDEX idx_product_note_product ON product_note (product_id)');
    }

    /**
     * Reversible outright: this migration creates one table and nothing else, so dropping it loses
     * only this feature's own data.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_note');
    }
}
