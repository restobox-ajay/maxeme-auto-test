<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #411 follow-up: Version20260805010000's REPLACE()-based fix silently no-ops on any
 * checkout where email_template rows for order_received/order_received_admin/
 * order_status_update were seeded with CRLF line endings. Version20260803150000 seeds
 * those rows from raw multi-line PHP string literals, so the seeded content inherits
 * whatever line ending the migration FILE ON DISK has (CRLF on a Windows checkout with
 * core.autocrlf=true, since there is no .gitattributes forcing normalization); meanwhile
 * Version20260805010000's search patterns use "\n" escape sequences inside double-quoted
 * strings, which always compile to a bare LF regardless of the file's own line endings.
 * SQL REPLACE() needs an exact byte match, so a CRLF-seeded row never matched the LF
 * search pattern, and the migration's "already at latest version" status gave no sign of
 * the no-op — confirmed by diffing the live row content against the search pattern byte
 * for byte.
 *
 * This normalizes each row's line endings to LF before re-attempting the exact same
 * content substitution Version20260805010000 defined, so it fixes any environment where
 * that migration silently failed (this repo's own Windows dev checkout included), and is
 * a no-op wherever the earlier migration already succeeded (str_contains guard below).
 */
final class Version20260805020000 extends AbstractMigration
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
        return 'Re-apply #411\'s order email_template fix with CRLF-normalized matching, for checkouts where Version20260805010000 silently no-opped.';
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
            $body = $this->connection->fetchOne('SELECT body FROM email_template WHERE code = ?', [$code]);
            if (!is_string($body)) {
                continue;
            }

            $normalized = str_replace("\r\n", "\n", $body);
            $changed = false;
            foreach ($subs as $search => $replace) {
                if (str_contains($normalized, $search)) {
                    $normalized = str_replace($search, $replace, $normalized);
                    $changed = true;
                }
            }

            if ($changed) {
                $this->connection->executeStatement(
                    'UPDATE email_template SET body = ? WHERE code = ?',
                    [$normalized, $code],
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
            $body = $this->connection->fetchOne('SELECT body FROM email_template WHERE code = ?', [$code]);
            if (!is_string($body)) {
                continue;
            }

            $normalized = str_replace("\r\n", "\n", $body);
            $changed = false;
            foreach ($subs as $search => $replace) {
                if (str_contains($normalized, $replace)) {
                    $normalized = str_replace($replace, $search, $normalized);
                    $changed = true;
                }
            }

            if ($changed) {
                $this->connection->executeStatement(
                    'UPDATE email_template SET body = ? WHERE code = ?',
                    [$normalized, $code],
                );
            }
        }
    }
}
