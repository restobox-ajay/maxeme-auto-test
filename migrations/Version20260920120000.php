<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `company.credit_limit` — the ceiling on a company's AR exposure (#724).
 *
 * NULL on every existing company, meaning unlimited: nothing in this codebase modeled a credit
 * ceiling before this, so there is no prior figure to carry forward, and defaulting every company
 * to some computed starting limit would be inventing a number nobody set. An admin sets it per
 * company from the Customer edit form, where `App\Service\CompanyCreditExposureCalculator` shows
 * the company's current outstanding balance right beside the box.
 */
final class Version20260920120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#724: company.credit_limit, the ceiling on a company\'s AR exposure.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD COLUMN credit_limit NUMERIC(14, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP COLUMN credit_limit');
    }
}
