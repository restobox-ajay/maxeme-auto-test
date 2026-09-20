<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed sender_replyto_address, and re-word the settings that stopped being senders (#474).
 *
 * #471 gave the From: header its own key but left it as an opt-in override in front of the old
 * chain, so a blank value still fell through to sales_email / support_email / tech_support_email /
 * app_email. That was the wrong shape. Those four are contact addresses — what a customer is told
 * to write to, and where some mail is delivered — and they sit on whatever domain the store's
 * inbox happens to be on. Production had support_email on gmail.com while the SMTP session
 * authenticated as no-reply@app.number1tirecentre.ca, which is a DMARC alignment failure on a
 * domain publishing a strict policy: the mail was liable to be junked no matter how well the
 * sending host was configured. As long as a contact address can reach the From: header, editing
 * "Support Email" can quietly break deliverability, and nothing on that screen would tell an admin
 * that is what they just did.
 *
 * So the sender chain is now, in full:
 *
 *     sender_from_address -> MAILER_FROM env -> the user portion of MAILER_DSN
 *
 * with the four contact keys out of it entirely, and no exception at the end — see
 * AppSettings::fromAddress() for why refusing to send was never the right answer.
 *
 * This migration is the part of that change an operator can see:
 *
 *  1. sender_replyto_address, seeded empty. Once the From: is a no-reply@ on the sending domain,
 *     replies go nowhere, and the obvious repair — quietly routing them to support_email — is the
 *     same conflation this issue exists to undo. It gets its own key, doing one job, and blank
 *     means no Reply-To header at all rather than a guess on the operator's behalf.
 *  2. sender_from_address's description, which still told admins that blank means "keep using
 *     Sales Email, Support Email or Tech Support Email". That is no longer true, and a description
 *     that describes the old behaviour is worse than none.
 *  3. The four contact addresses' descriptions. None of them is a sender any more, so each now says
 *     where the address actually appears or where its mail goes, and — for the three the store
 *     owner can see — that the From: address is Sender From Address. That last sentence is the one
 *     that stops someone editing Support Email expecting the sender to change.
 *
 * Descriptions only for those four: no key, name, category, visibility or value is touched, and
 * nothing about how they behave changes here. The same strings live in
 * Admin\ConfigController::coreSettingDefaults() and COMPANY_INFO_FIELDS, which re-assert them when
 * a row is missing or the Company Information form is saved; both are updated to match, or saving
 * that form would silently put the old copy back.
 *
 * UPDATE ... WHERE setting_key = ? touches nothing on an installation that does not have the row,
 * which is the same "safe to replay, safe to skip" property the INSERT below gets from
 * WHERE NOT EXISTS.
 */
final class Version20260806030000 extends AbstractMigration
{
    private const SENDER_FROM_DESCRIPTION = 'This is used as the From: address on every email this '
        . 'system sends. It should match the domain the mail is actually sent from, or messages may '
        . 'be junked or rejected. Leave blank to use the address from the MAILER_FROM environment '
        . 'variable, and failing that the account MAILER_DSN signs in as. It does not change where '
        . 'replies, contact-form messages or notifications are delivered.';

    private const SENDER_REPLYTO_DESCRIPTION = 'This is used only as the Reply-To address on emails '
        . 'this system sends. It does not affect who the email is from, and it does not change where '
        . 'contact-form messages or notifications are delivered. Leave blank to send no Reply-To '
        . 'header at all.';

    private const SALES_EMAIL_DESCRIPTION = 'Shown as the seller\'s contact address on invoices and '
        . 'quotes (on screen and in the PDFs), in the storefront header, and on the contact page. '
        . 'Display only: no mail is delivered here, and it is not the From: address on any email — '
        . 'that is Sender From Address.';

    private const SUPPORT_EMAIL_DESCRIPTION = 'Shown to customers as the address to write to: the '
        . 'contact page, the "your account email was changed" warning, and the registration email. '
        . 'It is also the last fallback for where contact-form messages are delivered. It is not the '
        . 'From: address on any email — that is Sender From Address — and not the Reply-To either — '
        . 'that is Sender Reply-To Address.';

    private const TECH_SUPPORT_EMAIL_DESCRIPTION = 'Where system notifications about this '
        . 'installation are delivered — currently the "database console was opened" security alert, '
        . 'and nothing else. A recipient address rather than a displayed one: it is never shown to '
        . 'customers, and it is not the From: address on any email (that is Sender From Address). '
        . 'This is the team that runs the software, not the store: customer-facing support is '
        . 'Support Email.';

    private const APP_EMAIL_DESCRIPTION = 'General contact address, used where a more specific one '
        . 'is not set: the seller contact on invoices and quotes when Sales Email is blank, and one '
        . 'of the addresses contact-form messages are delivered to when nothing more specific is '
        . 'configured. It is not the From: address on any email — that is Sender From Address.';

    public function getDescription(): string
    {
        return 'Seed sender_replyto_address (empty) and re-word the settings that are no longer senders.';
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
                'key' => AppSettings::SENDER_REPLYTO_ADDRESS_KEY,
                'name' => 'Sender Reply-To Address',
                'value' => '',
                'description' => self::SENDER_REPLYTO_DESCRIPTION,
                'category' => 'General',
                'visibility' => AppSetting::VISIBILITY_STORE,
            ],
        );

        $descriptions = [
            AppSettings::SENDER_FROM_ADDRESS_KEY => self::SENDER_FROM_DESCRIPTION,
            'sales_email' => self::SALES_EMAIL_DESCRIPTION,
            'support_email' => self::SUPPORT_EMAIL_DESCRIPTION,
            'tech_support_email' => self::TECH_SUPPORT_EMAIL_DESCRIPTION,
            'app_email' => self::APP_EMAIL_DESCRIPTION,
        ];

        foreach ($descriptions as $key => $description) {
            $this->addSql(
                'UPDATE app_setting SET description = :description WHERE setting_key = :key',
                ['key' => $key, 'description' => $description],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // No-op, in line with the other settings migrations: dropping a row an admin may have
        // filled in is not worth being able to undo, and restoring copy that describes behaviour
        // the code no longer has would be worse than leaving the accurate copy in place.
        $this->addSql('SELECT 1');
    }
}
