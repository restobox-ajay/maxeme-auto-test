<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Follows Version20261114170000: a paid invoice from the old system that never recorded the amount
 * paid (payment_amount empty or 0) was paid in full, so it gets one payment of its total, with its own
 * method and date. Without it, the first change to its payments would turn it back to Unpaid.
 */
final class Version20261114171000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invoice payments: a paid invoice with no recorded amount gets one payment of its total';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO maxeme_invoice_payment (invoice_id, payment_type_id, amount, paid_at, note, created_at)
            SELECT i.id, i.payment_type_id, i.total_price, COALESCE(i.last_modified, i.created_on), 'Paid in full (no amount recorded in the old system)', CURRENT_TIMESTAMP
            FROM maxeme_invoice i
            WHERE i.status = 'paid' AND i.total_price > 0
              AND NOT EXISTS (SELECT 1 FROM maxeme_invoice_payment p WHERE p.invoice_id = i.id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM maxeme_invoice_payment WHERE note = 'Paid in full (no amount recorded in the old system)'");
    }
}
