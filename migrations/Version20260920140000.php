<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `Company.salesRep` becomes a real relation to `AdminUser`, scoped to sales-rep-eligible staff
 * (#718).
 *
 * `company.sales_rep` (the free-text column) is NOT touched, renamed or dropped — it keeps holding
 * exactly what it always has: whatever a prospect typed into the "Sales Rep (optional)" box at
 * registration. `App\Entity\Company` now maps it under a new property name, `$salesRepNote`, so the
 * column and its meaning are unchanged; only what the code calls it changes, and that needs no
 * migration. The real, staff-facing assignment lives entirely in the two new columns below.
 *
 * No backfill from the old text into the new relation. That text is self-reported by whoever
 * registered — a name they typed, in whatever form they typed it ("Jordan", "Jordan M.", a nickname,
 * a typo) — and matching it against real `admin_user` rows would be a guess dressed up as data
 * migration. #718's own scope allows either carrying values forward best-effort or leaving them for
 * admins to re-assign; guessing wrong here silently attributes a company to the wrong rep, which is
 * worse than leaving it visibly unassigned. `sales_rep_user_id` starts NULL on every company, and
 * the old note stays visible next to the new picker on the admin form specifically so a human can
 * make that call correctly, once, per company.
 *
 * `admin_user.sales_rep_eligible` defaults false for the same reason `company.api_enabled` and
 * `customer_user.api_enabled` did in Version20260810120000: a capability like this is granted
 * deliberately, never inherited by every existing account the day it ships.
 */
final class Version20260920140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#718: admin_user.sales_rep_eligible flag + company.sales_rep_user_id relation, replacing the free-text sales rep assignment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_user ADD COLUMN sales_rep_eligible BOOLEAN DEFAULT 0 NOT NULL');

        $this->addSql('ALTER TABLE company ADD COLUMN sales_rep_user_id INTEGER DEFAULT NULL REFERENCES admin_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_company_sales_rep_user ON company (sales_rep_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_company_sales_rep_user');
        $this->addSql('ALTER TABLE company DROP COLUMN sales_rep_user_id');
        $this->addSql('ALTER TABLE admin_user DROP COLUMN sales_rep_eligible');
    }
}
