<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Re-derive sales_order.status once payments are authoritative on the invoice (#539).
 *
 * Stage 2 mapped the order's new status from the OLD sales_order.payment_status label: an order
 * that said 'Paid' became Closed, anything else Invoiced. That was the only source available at the
 * time, and it is wrong by the time the chain finishes, for two reasons.
 *
 * The label was never backed by rows. On the dev database 27 orders carried payment_status 'Paid'
 * with no sales_order_payment row behind them and invoice totals in the hundreds — somebody set a
 * dropdown. Stage 4 then drops that column precisely because nothing recomputed it.
 *
 * So after stage 4 the migrated status disagrees with what SalesOrderStatusDeriver computes, and
 * the disagreement is invisible until someone writes to the order — at which point the subscriber
 * recomputes and the status silently flips. A migration must not leave state that its own
 * application would immediately change.
 *
 * This re-derives from what is now the source of truth: an order is Closed when every invoice
 * counting toward it is covered by its payments, and Invoiced otherwise. Draft and Void are left
 * alone — neither is derived from payment, and Void is never automatic.
 *
 * Runs last deliberately: it needs invoice_payment to exist and be populated, which is stage 4.
 */
final class Version20260820170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#539: re-derive order status from invoice payments, now that payments are the invoice\'s.';
    }

    public function up(Schema $schema): void
    {
        // Mirrors SalesOrderStatusDeriver: Closed requires every counting invoice fully paid.
        // Draft invoices and cancelled ones count toward nothing, so they are excluded here for
        // the same reason Invoice::countsTowardInvoicedQuantity() excludes them.
        $this->addSql(<<<'SQL'
            UPDATE sales_order
            SET status = CASE
                WHEN NOT EXISTS (
                    SELECT 1 FROM invoice i
                    WHERE i.sales_order_id = sales_order.id
                      AND i.status NOT IN ('Draft', 'Cancelled')
                ) THEN 'Approved'
                WHEN EXISTS (
                    SELECT 1 FROM invoice i
                    WHERE i.sales_order_id = sales_order.id
                      AND i.status NOT IN ('Draft', 'Cancelled')
                      AND CAST(ROUND(COALESCE(i.total, 0) * 100) AS INTEGER) >
                          CAST(ROUND(COALESCE((
                              SELECT SUM(p.amount) FROM invoice_payment p WHERE p.invoice_id = i.id
                          ), 0) * 100) AS INTEGER)
                ) THEN 'Invoiced'
                ELSE 'Closed'
            END
            WHERE status NOT IN ('Draft', 'Void')
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to undo: this rewrites a derived value to what the application would compute
        // anyway, so there is no prior state worth restoring. Reversing it would mean putting back
        // a status the deriver disagrees with.
    }
}
