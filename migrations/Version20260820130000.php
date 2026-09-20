<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2 of #539: sales_order.status is re-anchored onto the rewritten SalesOrderStatus.
 *
 * The cases the order used to carry — Pending, On Hold, Processing, Completed, Cancelled — were
 * always really the invoice's, and stage 1 already wrote them onto the invoice row it raised for
 * every order. What is left on the order is one question: how much of it has been invoiced. So the
 * six old strings become Draft / Approved / Partially Invoiced / Invoiced / Closed / Void.
 *
 * ## Why this writes data at all
 *
 * Confirmed with the client before stage 1 and re-checked here: no production instance holds any
 * order data — every instance is a dev one. That is what makes an outright re-anchoring cheap
 * enough to do rather than carrying a compatibility layer, and it has a shelf life. If real order
 * data exists anywhere by the time this runs, the mapping below needs re-confirming first.
 *
 * ## The mapping
 *
 * Stage 1's invariant is that every order has exactly one invoice, so an order's new status can be
 * computed from what stage 1 wrote — which is the same computation SalesOrderStatusDeriver performs
 * from here on, expressed in SQL:
 *
 * | Was                                    | Becomes                                            |
 * |----------------------------------------|----------------------------------------------------|
 * | `Draft`                                | `Draft` — never accepted, nothing owed              |
 * | `Cancelled`                            | `Void` — the soft delete, out of reporting totals   |
 * | `Pending`/`On Hold`/`Processing`/`Completed`, paid     | `Closed` — fully invoiced, fully paid |
 * | `Pending`/`On Hold`/`Processing`/`Completed`, not paid | `Invoiced` — fully invoiced, owed     |
 * | anything else                          | `Draft`                                            |
 *
 * Every one of those orders is fully invoiced by construction: its single invoice carries every one
 * of its lines at full quantity. So `Partially Invoiced` and `Approved` are correctly absent — no
 * existing row can be either, and the deriver will produce them as soon as someone raises a second
 * invoice or cancels the only one.
 *
 * "Anything else" is the quote-era strings (`Waiting for Quote`, `Accepted Quotes`) and the pre-enum
 * `Approved`/`APPROVED` strays, exactly as stage 1's invoice backfill treated them: Draft is the one
 * status that asserts nothing about goods or money. Note this deliberately does NOT map a literal
 * `'Approved'` row onto the new `Approved` case — those rows predate any enum, mean something nobody
 * can now reconstruct, and stage 1 already gave them a Draft invoice, which `Approved` would then
 * contradict.
 *
 * ## The column
 *
 * Untouched: sales_order.status stays a plain VARCHAR(32), not enum-typed at the database level, so
 * a row carrying a value the enum no longer knows still hydrates rather than failing on load. That
 * is the same decision the column has always carried, and it is what makes this migration a data
 * change and not a schema change.
 */
final class Version20260820130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#539 stage 2: map sales_order.status onto the invoicing statuses.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE sales_order SET status = CASE
                WHEN LOWER(TRIM(status)) = 'draft' THEN 'Draft'
                WHEN LOWER(TRIM(status)) IN ('cancelled', 'canceled') THEN 'Void'
                WHEN LOWER(TRIM(status)) IN ('pending', 'on hold', 'processing', 'completed') THEN
                    CASE WHEN LOWER(TRIM(COALESCE(payment_status, ''))) = 'paid' THEN 'Closed' ELSE 'Invoiced' END
                ELSE 'Draft'
            END
        SQL);
    }

    /**
     * Lossy, and unavoidably so: four old statuses collapse into two new ones, and the fulfilment
     * distinction between them now lives on the invoice. So down() reads it back off the invoice
     * rather than guessing — which is exact for every row up() touched, because stage 1's 1:1
     * invariant guarantees the invoice is there.
     *
     * An order with no invoice at all (nothing this pair of migrations can produce, but a hand-made
     * row could be) falls back to Draft rather than inventing a fulfilment state for it.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE sales_order SET status = COALESCE((
                SELECT CASE i.status
                    WHEN 'Draft' THEN 'Draft'
                    WHEN 'Pending' THEN 'Pending'
                    WHEN 'On Hold' THEN 'On Hold'
                    WHEN 'Processing' THEN 'Processing'
                    WHEN 'Completed' THEN 'Completed'
                    WHEN 'Cancelled' THEN 'Cancelled'
                    ELSE 'Draft'
                END
                FROM invoice i
                WHERE i.sales_order_id = sales_order.id
                ORDER BY i.id
                LIMIT 1
            ), 'Draft')
        SQL);
    }
}
