<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make email_template's editable columns nullable, so a row can carry only what an admin changed
 * (#507).
 *
 * The 22 shipped templates now live in code — App\Service\Email\ShippedEmailTemplates and the .twig
 * files under templates/emails/shipped/ — and EmailTemplateResolver lays any row over the shipped
 * definition field by field. NULL is what "inherit" is spelled as. An admin who rewrites only the
 * subject leaves the rest null and keeps receiving shipped corrections to the body; while these
 * columns were NOT NULL that was not expressible, because a row had to assert every field whether
 * the admin meant to or not.
 *
 * `code` stays NOT NULL: it is the join key between a row and its shipped counterpart.
 *
 * NO DATA IS TOUCHED. Existing rows keep every value they have, which resolves to exactly the
 * behaviour they had before — the row asserts every field, so the row wins outright. Making them
 * inherit again is a decision only an admin can make, and it is a button in the panel ("Revert to
 * default"), not a REPLACE in a migration guessing which rows were edited. That distinction is the
 * lesson of Version20260805020000, which exists solely to re-apply Version20260805010000 on the
 * databases where its REPLACE silently matched nothing: a migration editing admin-editable text
 * cannot tell "unchanged" from "changed back" from "never matched", and acts on its guess anyway.
 *
 * Widening NOT NULL to NULL cannot fail on existing data — every current value is still valid — so
 * unlike the po_date rename (#498) this one has no value-preservation question to answer.
 */
final class Version20260807190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Make email_template's editable columns nullable so a row can hold only an admin's changes.";
    }

    public function up(Schema $schema): void
    {
        // SQLite has no ALTER COLUMN, and unlike #498's rename there is no RENAME COLUMN shortcut
        // for a nullability change, so the table is rebuilt. It is safe to restate the column list
        // here for the reason it was not safe on sales_order: nothing references email_template, it
        // has no foreign keys in either direction and no indexes, and the whole table is ten
        // columns taken verbatim from `doctrine:schema:create --dump-sql` rather than copied from
        // an older migration — which is precisely how Version20260806140000 lost sales_order.version.
        $this->addSql('CREATE TEMPORARY TABLE __temp__email_template AS SELECT id, code, module, sent_to, subject, body, description, status, created_at, updated_at FROM email_template');
        $this->addSql('DROP TABLE email_template');
        $this->addSql('CREATE TABLE email_template (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(80) NOT NULL, module VARCHAR(180) DEFAULT NULL, sent_to VARCHAR(32) DEFAULT NULL, subject VARCHAR(255) DEFAULT NULL, body CLOB DEFAULT NULL, description CLOB DEFAULT NULL, status VARCHAR(32) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('INSERT INTO email_template (id, code, module, sent_to, subject, body, description, status, created_at, updated_at) SELECT id, code, module, sent_to, subject, body, description, status, created_at, updated_at FROM __temp__email_template');
        $this->addSql('DROP TABLE __temp__email_template');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Narrowing these columns back to NOT NULL would fail on any row that has since been '
            . 'written as a partial override, and there is no correct value to invent for the '
            . 'fields it inherits — restore from a backup instead.'
        );
    }
}
