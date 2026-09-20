<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteTableRebuild;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 6 of #539: `sales_order.invoice_date` is dropped.
 *
 * It is an invoice fact by name and by content, and stage 4 said so while leaving it in place —
 * "stage 6 re-homes what is left of them". This is that.
 *
 * The reason it cannot stay is the same one that took `payment_status` off the order in stage 4,
 * and it is not tidiness. Once an order can be billed across several invoices there are several
 * invoice dates, and one column on the order can only name one of them. Nothing recomputes it, so
 * it would be correct on the day an order was first invoiced and silently wrong from the second
 * invoice onward — including in the Orders grid's Invoice Date column and its filter, which read it
 * as truth. Dropping it makes "an invoice date belongs to an invoice" a fact the schema enforces
 * rather than a convention a reviewer has to police.
 *
 * ## Nothing is lost
 *
 * Stage 1's backfill already carried every order's invoice date onto its invoice, as
 * COALESCE(o.invoice_date, o.document_date), so `invoice.invoice_date` holds it for every order that
 * existed. Orders invoiced since then get theirs from OrderInvoicingService. This migration reads
 * nothing and copies nothing; it removes a column whose content is already held, correctly and
 * per-invoice, somewhere else.
 *
 * ## The usual caveat, restated
 *
 * Authorised on the same grounds as every other #539 migration and confirmed with the client before
 * stage 1: no production instance holds any order data. Every instance is a dev one. If that ever
 * stops being true, down() below restores the column and refills it from each order's earliest
 * counting invoice — which is the best answer available, and is not the same answer as the one the
 * column held, because a column that could be typed in was free to say something no invoice ever
 * said.
 */
final class Version20260820160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#539 stage 6: drop sales_order.invoice_date — an invoice date belongs to an invoice.';
    }

    /** Rebuilds sales_order (production SQLite 3.26 has no DROP COLUMN); see SqliteTableRebuild. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN');
        foreach (SqliteTableRebuild::statements($this->connection, 'sales_order', ['invoice_date' => null]) as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order ADD COLUMN invoice_date VARCHAR(10) DEFAULT NULL');

        // The earliest date any non-cancelled invoice on the order carries. An order with no invoice
        // — or only cancelled ones — is left null, which is exactly what the column meant before it
        // was ever invoiced. MIN() over a 'Y-m-d' string is a calendar minimum, because that format
        // sorts lexicographically in calendar order; it is the reason the column is a string.
        $this->addSql(<<<'SQL'
            UPDATE sales_order SET invoice_date = (
                SELECT MIN(i.invoice_date)
                FROM invoice i
                WHERE i.sales_order_id = sales_order.id
                  AND i.status <> 'Cancelled'
                  AND i.invoice_date IS NOT NULL
            )
        SQL);
    }
}
