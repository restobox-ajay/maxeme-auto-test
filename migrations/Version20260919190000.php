<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add `recipient_notified` to `audit_log` — the one column the five document-timeline tables this
 * table is about to absorb (`sales_order_log`, `invoice_log`, `estimate_log`, `purchase_order_log`,
 * `vendor_bill_log`) did not all agree on: the sell side called it `customer_notified`,
 * `purchase_order_log` called the same idea `vendor_notified`, and `vendor_bill_log` carried no
 * such column at all. NOT NULL DEFAULT 0 — an absent column always meant "nobody was notified" in
 * practice, which is exactly what the default says for every row that predates this one.
 *
 * ADD only. The data migration (copying the five tables' rows in, dropping the five tables) is a
 * separate, later migration once every write path that used to target them writes here instead —
 * see AbstractSalesDocument::queueActivityLogEntry() / PendingActivityLogEntry.
 */
final class Version20260919190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit_log.recipient_notified, ahead of absorbing the five document-timeline tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE audit_log ADD COLUMN recipient_notified BOOLEAN NOT NULL DEFAULT 0");
    }

    public function down(Schema $schema): void
    {
        // SQLite 3.26 (this app's floor) has no DROP COLUMN — CLAUDE.md's own rule. Rebuilding the
        // table just to reverse an ADD-only column that nothing yet reads is not worth the risk
        // this early in the change; the column stays, harmlessly unused, on a rollback.
    }
}
