<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed invite_token_expiry_days, the lifetime of invite and admin-issued reset links.
 *
 * Every link built off admin_user/customer_user.reset_token expired after exactly one hour,
 * because all seven call sites hardcoded `modify('+1 hour')`. That number was chosen for the
 * self-service "I forgot my password" flow, where the person reading the mail is the person who
 * just asked for it and is sitting at their inbox. It was then reused for invites, where none of
 * that holds: the recipient did not ask, may not read mail that day, and cannot mint a replacement
 * — admin_account_setup / customer_account_setup only validate a token that already exists. A
 * lapsed invite is a support ticket, and the admin has to notice before it can even be raised.
 *
 * Invites and admin-triggered resets now read this setting (default 30 days) via
 * AppSettings::inviteTokenExpiresAt(). The two genuinely self-service forgot-password paths —
 * Admin\AuthController and Customer\AuthController — deliberately keep their +1 hour, so the
 * short window still guards the flow that is actually reachable by an anonymous visitor.
 *
 * Seeded as a real row rather than left to the code default so it is editable at /admin/settings
 * without an admin first having to know the key exists. 'store' visibility: how long your staff
 * and customers have to accept an invitation is the store owner's call, not the platform
 * operator's (contrast tech_support_email, #351).
 */
final class Version20260806000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed invite_token_expiry_days (default 30) for invite and admin-issued reset links.';
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
                'key' => AppSettings::INVITE_EXPIRY_KEY,
                'name' => 'Invite Link Expiry (Days)',
                'value' => (string) AppSettings::INVITE_EXPIRY_DEFAULT_DAYS,
                'description' => 'How many days an invitation or admin-sent password link stays valid. '
                    . 'Applies to staff invites, customer invites and password links an admin sends on '
                    . "someone's behalf. Does NOT apply to self-service \"forgot password\" links, which "
                    . 'always expire after 1 hour. Blank or invalid falls back to '
                    . AppSettings::INVITE_EXPIRY_DEFAULT_DAYS . ' days.',
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
