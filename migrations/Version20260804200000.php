<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove the grey card styling (background/border/padding) from order-related email_template
 * overrides, matching the fix already applied to the .twig files it once shared.
 *
 * email_template.body is a per-code, admin-editable override: EmailNotifier and OrderController's
 * "send invoice" action use it *instead of* the corresponding .twig file whenever a row for that
 * code exists (App\Service\EmailNotifier::send()). The four rows touched here were seeded from an
 * older, boxed copy of their .twig markup and had since diverged (their own wording/colors), so
 * editing the .twig files alone never reached what actually gets sent for these codes. The five
 * quote_* overrides are untouched: they render via {% include 'emails/_quote_summary.html.twig' %}
 * rather than a frozen copy, so the .twig-file fix already reaches them live.
 *
 * Each REPLACE() is scoped to its own code so a literal match in some unrelated template (e.g.
 * company_registration) is never touched, and is naturally idempotent: once a row no longer
 * contains the old markup, re-running this migration's SQL is a no-op.
 */
final class Version20260804200000 extends AbstractMigration
{
    private const REPLACEMENTS = [
        'invoice_customer' => [
            '<div class="order-summary">' => '<div class="order-summary-box">',
        ],
        'invoice_self' => [
            '<div class="order-summary">' => '<div class="order-summary-box">',
        ],
        'order_received_admin' => [
            '<div class="order-summary">' => '<div class="order-summary-box">',
        ],
        'order_message' => [
            '<div class="order-summary" style="font-style: italic; border-left: 4px solid #0f766e;">'
                => '<div class="message-quote-box" style="font-style: italic; border-left: 4px solid #0f766e;">',
        ],
    ];

    public function getDescription(): string
    {
        return 'De-box order-related email_template overrides (invoice_customer, invoice_self, order_received_admin, order_message).';
    }

    public function up(Schema $schema): void
    {
        // Data-only change; the ALTER-free path (see Version20260803170000's postUp) applies here too.
    }

    public function postUp(Schema $schema): void
    {
        if (!$schema->hasTable('email_template')) {
            return;
        }

        foreach (self::REPLACEMENTS as $code => $subs) {
            foreach ($subs as $search => $replace) {
                $this->connection->executeStatement(
                    'UPDATE email_template SET body = REPLACE(body, ?, ?) WHERE code = ?',
                    [$search, $replace, $code],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('email_template')) {
            return;
        }

        foreach (self::REPLACEMENTS as $code => $subs) {
            foreach ($subs as $search => $replace) {
                $this->connection->executeStatement(
                    'UPDATE email_template SET body = REPLACE(body, ?, ?) WHERE code = ?',
                    [$replace, $search, $code],
                );
            }
        }
    }
}
