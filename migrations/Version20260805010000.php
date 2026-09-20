<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #411: order_received, order_received_admin and order_status_update lacked the full order/
 * line-item table quote emails already show via {% include 'emails/_quote_summary.html.twig' %}.
 * templates/emails/order_received.html.twig, order_received_admin.html.twig and
 * order_status_update.html.twig now include the new templates/emails/_order_summary.html.twig
 * partial instead; this migration carries the same fix into the email_template.body override
 * rows those three codes were seeded with by Version20260803150000; see Version20260804200000's
 * docblock for why editing the .twig file alone never reaches what actually gets sent for a
 * code that has a DB row (App\Service\EmailNotifier::send() prefers the DB body outright).
 *
 * REPLACE()-scoped per code, same as Version20260804200000, so this is a no-op on any row an
 * admin has already hand-edited away from its seeded text, and never touches an unrelated code
 * whose body happens to share a substring.
 */
final class Version20260805010000 extends AbstractMigration
{
    private const REPLACEMENTS = [
        'order_received' => [
            "<p>We've received your order <span class=\"highlight\">#{{ order.orderNumber|default('') }}</span>. We'll notify you as it moves forward.</p>\n{% endblock %}"
                => "<p>We've received your order <span class=\"highlight\">#{{ order.orderNumber|default('') }}</span>. We'll notify you as it moves forward.</p>\n\n{% include 'emails/_order_summary.html.twig' %}\n\n<div style=\"text-align: center; margin-top: 20px;\">\n    <a href=\"{{ order_url|default('#') }}\" class=\"button\">View My Order</a>\n</div>\n{% endblock %}",
        ],
        'order_received_admin' => [
            "    <div class=\"order-summary-box\">\n        <div class=\"summary-row\">\n            <span>Order Total:</span>\n            <strong>\${{ order.total|default(0)|number_format(2) }}</strong>\n        </div>\n    </div>\n\n    <div style=\"text-align: center;\">\n        <a href=\"{{ admin_url|default('#') }}\" class=\"button\">Review Order</a>\n    </div>"
                => "    {% include 'emails/_order_summary.html.twig' %}\n\n    <div style=\"text-align: center; margin-top: 20px;\">\n        <a href=\"{{ admin_url|default('#') }}\" class=\"button\">Review Order</a>\n    </div>",
        ],
        'order_status_update' => [
            "    <div style=\"text-align: center;\">\n        <a href=\"{{ order_url|default('#') }}\" class=\"button\">View My Order</a>\n    </div>\n\n    <div class=\"order-summary\" style=\"background: transparent; border: none; border-top: 1px solid #e2e8f0; margin-top: 30px; padding: 20px 0 0 0;\">"
                => "    <div style=\"text-align: center;\">\n        <a href=\"{{ order_url|default('#') }}\" class=\"button\">View My Order</a>\n    </div>\n\n    {% include 'emails/_order_summary.html.twig' %}\n\n    <div class=\"order-summary\" style=\"background: transparent; border: none; border-top: 1px solid #e2e8f0; margin-top: 30px; padding: 20px 0 0 0;\">",
        ],
    ];

    public function getDescription(): string
    {
        return 'Add the full order/line-item table to the order_received, order_received_admin and order_status_update email_template overrides (#411).';
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
