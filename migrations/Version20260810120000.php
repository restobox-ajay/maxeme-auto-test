<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Move the API from the Number1ProductAPIManager bundle into core, and make its credential belong
 * to a user rather than to a whole company (#521).
 *
 * The old credential was shared by everyone at a company, so it could not represent anybody in
 * particular — it authenticated as a synthetic non-customer principal, which made every customer
 * controller unreachable from an API request and forced the bundle to reimplement pricing,
 * visibility and category scoping. A per-user credential authenticates as that user, so the API can
 * forward straight into the controllers that already serve the website.
 *
 * The two old tables are dropped rather than migrated. There is no production instance using the
 * API, confirmed before writing this, so there is no key anyone would lose and nothing to carry
 * over — and a per-company row has no single user to attribute it to anyway, so any conversion
 * would be a guess. If that ever stops being true, this migration must not be run as-is.
 *
 * Both new flags default to false: API access is granted deliberately, never inherited by existing
 * companies or users when this lands.
 */
final class Version20260810120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move API credentials into core, keyed per customer user, with company/user enable flags.';
    }

    public function up(Schema $schema): void
    {
        // Old bundle tables. api_credential is recreated below with a different shape, so it has to
        // go first rather than being altered — the column it was keyed by is the one being replaced.
        $this->addSql('DROP TABLE IF EXISTS api_credential_category');
        $this->addSql('DROP TABLE IF EXISTS api_credential');

        $this->addSql(<<<'SQL'
            CREATE TABLE api_credential (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                customer_user_id INTEGER NOT NULL,
                api_key VARCHAR(100) NOT NULL,
                status VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL,
                last_used_at DATETIME DEFAULT NULL,
                CONSTRAINT fk_api_credential_customer_user
                    FOREIGN KEY (customer_user_id) REFERENCES customer_user (id) ON DELETE CASCADE
            )
        SQL);

        // One key per user, and a key can only ever name one user — both directions matter, since
        // the credential is the identity the request authenticates as.
        $this->addSql('CREATE UNIQUE INDEX uniq_api_credential_customer_user ON api_credential (customer_user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_api_credential_api_key ON api_credential (api_key)');

        $this->addSql('ALTER TABLE company ADD COLUMN api_enabled BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE customer_user ADD COLUMN api_enabled BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS api_credential');
        $this->addSql('ALTER TABLE company DROP COLUMN api_enabled');
        $this->addSql('ALTER TABLE customer_user DROP COLUMN api_enabled');

        // The bundle's tables are deliberately NOT recreated here. Reversing this migration returns
        // the schema to not having an API; it cannot return a bundle that no longer exists in the
        // codebase, and an empty pair of tables shaped for it would only be misleading.
    }
}
