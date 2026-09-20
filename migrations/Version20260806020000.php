<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed sender_from_address, the store-wide override for the From: header on outbound email.
 *
 * support_email was doing two unrelated jobs. It is the From: header on fourteen sending sites
 * (everything AppSettings::supportFromAddress() reaches: invites, password links, registration,
 * the contact form, EmailNotifier's 'support' category), and it is also the plain string four
 * templates print to tell a human where to write *to*. Those are not the same address. What you
 * send from has to line up with the domain MAILER_DSN authenticates as, or SPF/DKIM/DMARC do not
 * align and the mail lands in spam; what you tell people to contact is a monitored inbox and is
 * routinely on some other domain entirely. With one setting doing both, improving deliverability
 * meant breaking the published contact address, and vice versa (#471).
 *
 * So the sender gets its own key, and it goes first in every chain:
 *
 *     sender_from_address -> <sales_email|support_email|tech_support_email> -> app_email
 *                         -> MAILER_FROM env -> throw
 *
 * Seeded empty on purpose. Empty means "not in the chain", so every existing installation keeps
 * exactly the resolution it has today and the MAILER_FROM env var we already ship remains the last
 * resort. This is an override for the operator who needs one, not a new thing to configure.
 *
 * Seeded as a real row rather than left to the code default for the same reason
 * invite_token_expiry_days was (#450): a key with no row is invisible at /admin/settings, and an
 * admin cannot fill in a box they cannot find. 'store' visibility — which domain this store's mail
 * comes from is the store owner's call, not the platform operator's (contrast tech_support_email,
 * #351).
 */
final class Version20260806020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed sender_from_address (empty) as the store-wide From: header override.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('app_setting')) {
            return;
        }

        // Same INSERT ... WHERE NOT EXISTS shape as the other settings migrations, so re-running
        // against a database that already has the key is a no-op rather than a duplicate row.
        $this->addSql(
            'INSERT INTO app_setting (setting_key, name, setting_value, description, created_at, updated_at, category, visibility)
             SELECT :key, :name, :value, :description, CURRENT_TIMESTAMP, NULL, :category, :visibility
             WHERE NOT EXISTS (SELECT 1 FROM app_setting WHERE setting_key = :key)',
            [
                'key' => AppSettings::SENDER_FROM_ADDRESS_KEY,
                'name' => 'Sender From Address',
                'value' => '',
                'description' => 'This is used as the envelope-visible sender. It should match the '
                    . 'sending email domain to ensure delivery. Leave blank to keep using Sales '
                    . 'Email, Support Email or Tech Support Email as the From: address for each '
                    . 'kind of email. Setting it overrides all of them; it does not change where '
                    . 'replies or contact-form messages are delivered.',
                'category' => 'General',
                'visibility' => AppSetting::VISIBILITY_STORE,
            ],
        );
    }

    public function down(Schema $schema): void
    {
        // No-op, in line with the other settings migrations: dropping a row an admin may have
        // filled in is not worth being able to undo.
        $this->addSql('SELECT 1');
    }
}
