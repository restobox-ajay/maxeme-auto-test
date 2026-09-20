<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename po_date to document_date on cart, sales_order and estimate (#498).
 *
 * The column never had anything to do with a purchase order. It is the date the document itself
 * carries — "Order Date" on an order, "Quote Date" on a quote — and the name po_date read as a
 * partner to po_number, which IS the customer's purchase order reference and is a different field
 * sitting right beside it in the same table. Nothing about the values changes: same 'Y-m-d'
 * strings, same VARCHAR(10), same nullability. Only the name.
 *
 * ALTER TABLE ... RENAME COLUMN rather than the drop-and-rebuild every other schema change on these
 * tables has used. It needs SQLite 3.25+ and this application's floor is 3.26, so it is available —
 * and it is not merely shorter, it removes the failure mode the rebuild has. A rebuild has to
 * restate every column of the table, and a restated list is a list that can be wrong:
 * Version20260806140000 rebuilt sales_order from a hand-copied list that silently omitted `version`
 * (the optimistic-lock counter #417), which broke every INSERT and UPDATE of an order on any
 * database that replayed the chain and needed Version20260807010000 to repair. Three of these
 * tables would have needed that same list, one of them twice over. RENAME COLUMN names one column
 * and touches nothing else, so there is no list to get wrong.
 *
 * It is also why this migration is ordinary and transactional. Nothing is dropped, so no foreign
 * key cascades through the eleven tables that reference these three, and there is no PRAGMA
 * foreign_keys to be silently ignored inside a transaction — the trap that once let a migration in
 * this repo report success while deleting rows a previous migration had just backfilled.
 */
final class Version20260807120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename po_date to document_date on cart, sales_order and estimate.';
    }

    public function up(Schema $schema): void
    {
        foreach (['cart', 'sales_order', 'estimate'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN po_date TO document_date', $table));
        }

        // The one place the old name survives outside the schema: the shipped invoice_customer
        // email body, seeded as a row by the Version20260803150000 baseline and rendered FROM THE
        // ROW, not from templates/emails/invoice_customer.html.twig — so the file rename above it
        // does not reach it. Renaming the getter without this throws when an invoice email is sent,
        // which is a customer-facing failure. A targeted REPLACE rather than a rewrite of the whole
        // body, so an admin who has edited around this expression keeps their edit.
        $this->addSql("UPDATE email_template SET body = REPLACE(body, 'order.poDate', 'order.documentDate') WHERE body LIKE '%order.poDate%'");
    }

    public function down(Schema $schema): void
    {
        foreach (['cart', 'sales_order', 'estimate'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN document_date TO po_date', $table));
        }

        $this->addSql("UPDATE email_template SET body = REPLACE(body, 'order.documentDate', 'order.poDate') WHERE body LIKE '%order.documentDate%'");
    }
}
