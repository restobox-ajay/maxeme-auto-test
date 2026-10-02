<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Service lines: what a service is made of (labour, parts, sublet, government fees, a discount),
 * each with a quantity, a price per unit and a Charge through switch. Existing services have none.
 */
final class Version20261002120000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Services: service lines';
    }

    public function up(Schema $schema): void
    {
        if ($this->tableExists('maxeme_service_line')) {
            return;
        }

        $this->addSql('CREATE TABLE maxeme_service_line (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            service_id INTEGER NOT NULL,
            labour_id INTEGER DEFAULT NULL,
            product_id INTEGER DEFAULT NULL,
            govt_fee_id INTEGER DEFAULT NULL,
            type VARCHAR(16) NOT NULL,
            quantity NUMERIC(10, 2) NOT NULL,
            unit_price NUMERIC(10, 2) NOT NULL,
            charge_through BOOLEAN DEFAULT 0 NOT NULL,
            position INTEGER DEFAULT 0 NOT NULL,
            CONSTRAINT FK_MAXEME_SERVICE_LINE_SERVICE FOREIGN KEY (service_id) REFERENCES maxeme_service (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_MAXEME_SERVICE_LINE_LABOUR FOREIGN KEY (labour_id) REFERENCES maxeme_labour (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_MAXEME_SERVICE_LINE_PRODUCT FOREIGN KEY (product_id) REFERENCES product_core (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_MAXEME_SERVICE_LINE_GOVT_FEE FOREIGN KEY (govt_fee_id) REFERENCES maxeme_govt_fee (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IDX_MAXEME_SERVICE_LINE_SERVICE ON maxeme_service_line (service_id)');
        $this->addSql('CREATE INDEX IDX_MAXEME_SERVICE_LINE_LABOUR ON maxeme_service_line (labour_id)');
        $this->addSql('CREATE INDEX IDX_MAXEME_SERVICE_LINE_PRODUCT ON maxeme_service_line (product_id)');
        $this->addSql('CREATE INDEX IDX_MAXEME_SERVICE_LINE_GOVT_FEE ON maxeme_service_line (govt_fee_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE maxeme_service_line');
    }
}
