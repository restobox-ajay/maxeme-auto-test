<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed password_reset_expiry_hours, and stop the three email_template rows saying "1 hour".
 *
 * #450 made the invite/admin-issued lifetime configurable and taught the FILE templates to print
 * whatever it granted. It did not touch the email_template DATABASE rows, and the row wins: every
 * one of these send paths renders the file first and then, if a row exists for the code, throws
 * that render away and re-renders the row instead. Production has all three rows, so production
 * kept mailing "this link will expire in 1 hour" for admin-issued resets that really lasted 30
 * days — while every test added in #450 passed, because they all asserted against the files.
 *
 * The rows cannot be fixed by writing a better literal into them. forgot_password is rendered by
 * two different flows with two different lifetimes (the self-service one below, and the
 * admin-issued reset in Admin\UserController, which uses the invite window), so any literal is
 * wrong for one of them. They have to print {{ expiry_description }} and let the caller say how
 * long the token it just minted actually lasts — which, after #475, every caller does.
 *
 * password_reset_expiry_hours is the other half: the self-service window was the last hardcoded
 * duration, a literal modify('+1 hour') in Admin\AuthController and Customer\AuthController. It
 * stays a separate setting from invite_token_expiry_days rather than being folded into it, because
 * it is the only link an anonymous visitor can request for an address they do not own, so it is
 * the one lifetime here that is a security parameter. Making it configurable does not change the
 * default; it changes whether the number can drift away from the copy describing it.
 *
 * The UPDATE is guarded on the row's body still being byte-for-byte what Version20260803150000
 * seeded (line endings normalized — see Version20260805020000 for why that matters). These rows
 * are editable at /admin/email-template, and silently overwriting an admin's rewritten invite copy
 * to fix one sentence of it is a worse bug than the one being fixed. A customised row keeps its
 * wording and is reported, not touched.
 */
final class Version20260806040000 extends AbstractMigration
{
    /**
     * code => [body as shipped by Version20260803150000, body with the literal replaced].
     *
     * Nowdoc, so what is written here is exactly what is compared: these are Twig sources full of
     * quotes and braces, and an escaping slip would turn the guard into a permanent no-op that
     * looks like success — the same silent-failure shape as the bug itself.
     */
    private const BODY_REWRITES = [
        'forgot_password' => [
            <<<'TWIG'
                {% extends 'emails/layout.html.twig' %}

                {% block title %}Password Reset Request{% endblock %}

                {% block body %}
                    <h2 style="margin-top: 0; color: #0f172a;">Password Reset</h2>
                    <p>Hello <strong>{{ user_email|default('User') }}</strong>,</p>
                    <p>We received a request to reset your password for {{ site_name() }}. If you didn't make this request, you can safely ignore this email.</p>

                    <div style="text-align: center; margin-top: 25px;">
                        <a href="{{ reset_url|default('#') }}" class="button" style="border-radius: 999px;">Reset My Password</a>
                    </div>

                    <p style="margin-top: 30px; font-size: 13px; color: #64748b;">
                        For security, this link will expire in 1 hour.
                    </p>
                {% endblock %}
                TWIG,
            <<<'TWIG'
                {% extends 'emails/layout.html.twig' %}

                {% block title %}Password Reset Request{% endblock %}

                {% block body %}
                    <h2 style="margin-top: 0; color: #0f172a;">Password Reset</h2>
                    <p>Hello <strong>{{ user_email|default('User') }}</strong>,</p>
                    <p>We received a request to reset your password for {{ site_name() }}. If you didn't make this request, you can safely ignore this email.</p>

                    <div style="text-align: center; margin-top: 25px;">
                        <a href="{{ reset_url|default('#') }}" class="button" style="border-radius: 999px;">Reset My Password</a>
                    </div>

                    <p style="margin-top: 30px; font-size: 13px; color: #64748b;">
                        For security, this link will expire in {{ expiry_description }}.
                    </p>
                {% endblock %}
                TWIG,
        ],
        'invite' => [
            <<<'TWIG'
                {% extends 'emails/layout.html.twig' %}
                {% block body %}
                <h2 style="margin-top: 0; color: #0f172a;">Welcome!</h2>
                <p>Hello <strong>{{ user_email|default('User') }}</strong>,</p>
                <p>You have been invited to join the {{ site_name() }} portal. To get started and set up your password, please click the button below:</p>
                <div style="text-align: center; margin-top: 25px;"><a href="{{ reset_url|default('#') }}" class="button" style="border-radius: 999px;">Set Up My Account</a></div>
                <p style="margin-top: 30px; font-size: 13px; color: #64748b;">This invitation will expire in 1 hour. If you didn't request this, you can ignore this email.</p>
                {% endblock %}
                TWIG,
            <<<'TWIG'
                {% extends 'emails/layout.html.twig' %}
                {% block body %}
                <h2 style="margin-top: 0; color: #0f172a;">Welcome!</h2>
                <p>Hello <strong>{{ user_email|default('User') }}</strong>,</p>
                <p>You have been invited to join the {{ site_name() }} portal. To get started and set up your password, please click the button below:</p>
                <div style="text-align: center; margin-top: 25px;"><a href="{{ reset_url|default('#') }}" class="button" style="border-radius: 999px;">Set Up My Account</a></div>
                <p style="margin-top: 30px; font-size: 13px; color: #64748b;">This invitation will expire in {{ expiry_description }}. If you didn't request this, you can ignore this email.</p>
                {% endblock %}
                TWIG,
        ],
        'new_user_invited' => [
            <<<'TWIG'
                {% extends 'emails/layout.html.twig' %}

                {% block title %}Welcome to {{ site_name() }}{% endblock %}

                {% block body %}
                    <h2 style="margin-top: 0; color: #0f172a;">Welcome!</h2>
                    <p>Hello <strong>{{ user_email|default('User') }}</strong>,</p>
                    <p>You have been invited to join the {{ site_name() }} portal. To get started and set up your password, please click the button below:</p>

                    <div style="text-align: center; margin-top: 25px;">
                        <a href="{{ reset_url|default('#') }}" class="button" style="border-radius: 999px;">Set Up My Account</a>
                    </div>

                    <p style="margin-top: 30px; font-size: 13px; color: #64748b;">
                        This invitation will expire in 1 hour. If you didn't request this, you can ignore this email.
                    </p>
                {% endblock %}
                TWIG,
            <<<'TWIG'
                {% extends 'emails/layout.html.twig' %}

                {% block title %}Welcome to {{ site_name() }}{% endblock %}

                {% block body %}
                    <h2 style="margin-top: 0; color: #0f172a;">Welcome!</h2>
                    <p>Hello <strong>{{ user_email|default('User') }}</strong>,</p>
                    <p>You have been invited to join the {{ site_name() }} portal. To get started and set up your password, please click the button below:</p>

                    <div style="text-align: center; margin-top: 25px;">
                        <a href="{{ reset_url|default('#') }}" class="button" style="border-radius: 999px;">Set Up My Account</a>
                    </div>

                    <p style="margin-top: 30px; font-size: 13px; color: #64748b;">
                        This invitation will expire in {{ expiry_description }}. If you didn't request this, you can ignore this email.
                    </p>
                {% endblock %}
                TWIG,
        ],
    ];

    /**
     * The description Version20260806000000 wrote for invite_token_expiry_days, which promised that
     * self-service links "always expire after 1 hour". That is no longer true of the software, so
     * the sentence has to go — guarded the same way the bodies are, since an admin can edit setting
     * descriptions too.
     */
    private const INVITE_DESCRIPTION_OLD = 'How many days an invitation or admin-sent password link stays valid. '
        . 'Applies to staff invites, customer invites and password links an admin sends on '
        . "someone's behalf. Does NOT apply to self-service \"forgot password\" links, which "
        . 'always expire after 1 hour. Blank or invalid falls back to 30 days.';

    private const INVITE_DESCRIPTION_NEW = 'How many days an invitation or admin-sent password link stays valid. '
        . 'Applies to staff invites, customer invites and password links an admin sends on '
        . "someone's behalf. Does NOT apply to self-service \"forgot password\" links, which have "
        . 'their own much shorter setting (Password Reset Link Expiry (Hours)). Blank or invalid '
        . 'falls back to 30 days.';

    public function getDescription(): string
    {
        return 'Seed password_reset_expiry_hours (default 1) and replace the "1 hour" literal in the forgot_password, invite and new_user_invited email_template rows with {{ expiry_description }}.';
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
                'key' => AppSettings::PASSWORD_RESET_EXPIRY_KEY,
                'name' => 'Password Reset Link Expiry (Hours)',
                'value' => (string) AppSettings::PASSWORD_RESET_EXPIRY_DEFAULT_HOURS,
                'description' => 'How long a self-service "forgot password" link stays valid, in hours. '
                    . 'This is the only link an anonymous visitor can request for any email address, so it '
                    . 'is deliberately much shorter than the invite window. Invites and admin-sent password '
                    . 'links use Invite Link Expiry (Days) instead. Blank or invalid falls back to '
                    . AppSettings::PASSWORD_RESET_EXPIRY_DEFAULT_HOURS . ' hour.',
                'category' => 'General',
                'visibility' => AppSetting::VISIBILITY_STORE,
            ],
        );
    }

    /**
     * The row rewrites read before they write, so they run after up()'s SQL has been applied —
     * same reason Version20260805020000 does its content substitution here.
     */
    public function postUp(Schema $schema): void
    {
        if ($schema->hasTable('app_setting')) {
            $this->connection->executeStatement(
                'UPDATE app_setting SET description = ? WHERE setting_key = ? AND description = ?',
                [self::INVITE_DESCRIPTION_NEW, AppSettings::INVITE_EXPIRY_KEY, self::INVITE_DESCRIPTION_OLD],
            );
        }

        if (!$schema->hasTable('email_template')) {
            return;
        }

        $updated = [];
        $skipped = [];

        foreach (self::BODY_REWRITES as $code => [$shipped, $rewritten]) {
            $body = $this->connection->fetchOne('SELECT body FROM email_template WHERE code = ?', [$code]);
            if (!is_string($body)) {
                $skipped[] = $code . ' (no such row)';
                continue;
            }

            if ($this->normalize($body) !== $this->normalize($shipped)) {
                $skipped[] = $code . ' (customised)';
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE email_template SET body = ? WHERE code = ?',
                [$rewritten, $code],
            );
            $updated[] = $code;
        }

        // Written out rather than merely done: which rows were left alone is the whole point of the
        // guard, and an operator upgrading production needs to know that a customised invite still
        // says "1 hour" and needs one sentence edited by hand.
        $this->write(sprintf(
            '    email_template rows rewritten to {{ expiry_description }}: %d (%s); left unchanged: %d (%s)',
            count($updated),
            $updated === [] ? 'none' : implode(', ', $updated),
            count($skipped),
            $skipped === [] ? 'none' : implode(', ', $skipped),
        ));
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('email_template')) {
            return;
        }

        // Only reverses rows this migration itself wrote, by the same exact-match rule.
        foreach (self::BODY_REWRITES as $code => [$shipped, $rewritten]) {
            $body = $this->connection->fetchOne('SELECT body FROM email_template WHERE code = ?', [$code]);
            if (is_string($body) && $this->normalize($body) === $this->normalize($rewritten)) {
                $this->connection->executeStatement('UPDATE email_template SET body = ? WHERE code = ?', [$shipped, $code]);
            }
        }

        // The app_setting row is deliberately not dropped, in line with the other settings
        // migrations: removing a value an admin may have edited is not worth being able to undo.
    }

    /**
     * Line endings only. A row seeded on a checkout with core.autocrlf=true carries CRLF (the
     * seeded content inherits the migration file's own line endings), and comparing that against
     * this file's LF literals would report every row as "customised" and quietly fix nothing —
     * exactly how Version20260805010000 no-opped. See Version20260805020000.
     */
    private function normalize(string $body): string
    {
        return str_replace("\r\n", "\n", $body);
    }
}
