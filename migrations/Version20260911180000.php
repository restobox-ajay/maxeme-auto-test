<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #16 (RFQ + vendor reply screens): `rfq` gains `delivery_address`. One column, nullable, no backfill.
 *
 * ## What it is for
 *
 * An RFQ names a requirement and a destination warehouse. `warehouse` in this application is a name
 * and a status — it carries no address — so until now the tender could NAME the site the goods
 * would go to and never STATE it. Every vendor asked to quote is being asked to quote delivery
 * somewhere, and freight is most of the difference between two quotes for identical goods.
 *
 * ## What it is NOT for
 *
 * It is not money. The RFQ deliberately carries no vendor, no currency, no tax and no total — it is
 * the argued exception in `EveryDocumentDeclaresItsContractTest::NOT_COMMERCIAL`, because a
 * requirement put to several vendors at once has no single counterparty to owe anything to. The
 * money lives on `rfq_vendor_reply`, one row per vendor, which already has every column it needs
 * (`subtotal`, `tax`, `total`) and gains none here. An address is where, not how much.
 *
 * ## ADD COLUMN, and no table rebuild
 *
 * `ALTER TABLE … ADD COLUMN`, which SQLite performs in place. Doctrine emulates anything else —
 * dropping a column especially — by rebuilding the table through a temp copy, and `rfq_line` and
 * `rfq_vendor_reply` are both `ON DELETE CASCADE` off `rfq`: a rebuild of this parent is exactly
 * the shape of the bug Version20260730150000 shipped once already, where `PRAGMA foreign_keys = OFF`
 * is silently ignored inside a transaction and the DROP TABLE cascades while the migration exits 0.
 * Hence `down()` below leaves the column in place.
 *
 * NOT ONE EXISTING VALUE IS WRITTEN. There is no UPDATE and no INSERT in this file. Every existing
 * `rfq` row reads NULL, which is what the screens already treat as "not stated" and fall back to
 * the company address from Settings → Company Information for.
 */
final class Version20260911180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RFQ screens: rfq gains a nullable delivery_address. No backfill, and no money column — '
            . 'an RFQ still states no total, currency or counterparty.';
    }

    public function up(Schema $schema): void
    {
        // CLOB is what Doctrine's `text` type maps to on SQLite, so a migrated database and one
        // built from entity metadata (both test suites, via SchemaTool) agree — which is the
        // comparison bin/ci-migration-replay makes.
        $this->addSql('ALTER TABLE rfq ADD COLUMN delivery_address CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Nothing. Dropping it means a table rebuild, a rebuild is a DROP TABLE, and `rfq_line` and
        // `rfq_vendor_reply` cascade off this table — so undoing one nullable column would risk
        // every requirement line and every vendor quote in the database. An orphan nullable column
        // costs nothing; the rows cost the tender its record of what was asked and what was quoted.
    }
}
