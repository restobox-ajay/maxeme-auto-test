<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make the shipped invoice_customer / invoice_self email templates invoice-first, order-optional.
 *
 * Both bodies were written when every invoice had an order behind it and read `order.orderNumber`,
 * `order.company.name`, `order.documentDate` and `order.total` unconditionally — no `|default()`,
 * because there was nothing to default against. /admin/invoice/create has raised invoices with no
 * order on purpose since #539, and InvoiceController::send() used to refuse to email one of those
 * outright rather than crash on a null `order` — "Emailing needs an order behind the invoice.
 * Download the invoice and attach it." That refusal is removed in this same change (it was papering
 * over data these templates could not survive, not a real product rule), which makes this migration
 * load-bearing: without it, sending a standalone invoice would now reach
 * `Twig\Error\RuntimeError: Impossible to access an attribute ("orderNumber") on a null variable`
 * instead of a flashed refusal — worse, not better.
 *
 * Every field now reads off `invoice` (always present) instead of `order` (present only when the
 * invoice bills one) — the invoice's own number, company, date and total, which is what actually
 * shipped and what the customer is being billed for either way. The one place order data was truly
 * extra rather than a mislabelled substitute — the "View Order Details" button, which has nowhere to
 * point for a standalone invoice — is wrapped in `{% if order_url is defined %}` instead of reading
 * `order_url|default('#')` and linking to nothing.
 *
 * Targeted REPLACE() per exact fragment, scoped to its own `code`, the same shape
 * Version20260804200000 already used for these two rows: naturally idempotent (nothing to replace
 * once a fragment is gone), and an admin who has rewritten the surrounding prose keeps that edit —
 * only the `order.*` expressions this migration exists to fix are touched, not the wording around
 * them.
 */
final class Version20260919180000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    private const SUBJECT_REPLACEMENTS = [
        'invoice_customer' => [
            'Invoice for Order #{{ order.orderNumber|default(\'\') }}' => 'Invoice #{{ invoice.documentNumber }}',
        ],
        'invoice_self' => [
            'Internal: Invoice for Order #{{ order.orderNumber|default(\'\') }}' => 'Internal: Invoice #{{ invoice.documentNumber }}',
        ],
    ];

    private const BODY_REPLACEMENTS = [
        'invoice_customer' => [
            '{% block title %}Invoice for Order #{{ order.orderNumber }}{% endblock %}'
                => '{% block title %}Invoice #{{ invoice.documentNumber }}{% endblock %}',
            '<h2 style="margin-top: 0; color: #0f172a;">Order Confirmation</h2>'
                => '<h2 style="margin-top: 0; color: #0f172a;">Invoice</h2>',
            '<p>Hello <strong>{{ order.company.name }}</strong>,</p>'
                => '<p>Hello <strong>{{ invoice.company.name }}</strong>,</p>',
            '<p>Thank you for your order! We have processed your invoice for order <span class="highlight">#{{ order.orderNumber }}</span>. Please find the PDF invoice attached to this email for your records.</p>'
                => '<p>Thank you! We have processed your invoice <span class="highlight">#{{ invoice.documentNumber }}</span>. Please find the PDF invoice attached to this email for your records.</p>',
            "<span>Order Number:</span>\n            <strong>{{ order.orderNumber }}</strong>"
                => "<span>Invoice Number:</span>\n            <strong>{{ invoice.documentNumber }}</strong>",
            "<span>Order Date:</span>\n            <strong>{{ order.documentDate|date('F j, Y') }}</strong>"
                => "<span>Invoice Date:</span>\n            <strong>{{ invoice.documentDate|date('F j, Y') }}</strong>",
            '<strong>${{ order.total|default(0)|number_format(2) }}</strong>'
                => '<strong>${{ invoice.total|default(0)|number_format(2) }}</strong>',
            '<p>If you have any questions regarding this invoice or your order status, please don\'t hesitate to reach out to our billing department.</p>'
                => '<p>If you have any questions regarding this invoice, please don\'t hesitate to reach out to our billing department.</p>',
            "<div style=\"text-align: center;\">\n        <a href=\"{{ order_url|default('#') }}\" class=\"button\">View Order Details</a>\n    </div>"
                => "{% if order_url is defined %}\n    <div style=\"text-align: center;\">\n        <a href=\"{{ order_url }}\" class=\"button\">View Order Details</a>\n    </div>\n    {% endif %}",
        ],
        'invoice_self' => [
            '{% block title %}Internal: Invoice for Order #{{ order.orderNumber }}{% endblock %}'
                => '{% block title %}Internal: Invoice #{{ invoice.documentNumber }}{% endblock %}',
            '<p>This is an internal copy of the invoice generated for order <span class="highlight">#{{ order.orderNumber }}</span>.</p>'
                => '<p>This is an internal copy of invoice <span class="highlight">#{{ invoice.documentNumber }}</span>.</p>',
            "<span>Company:</span>\n            <strong>{{ order.company.name }}</strong>"
                => "<span>Company:</span>\n            <strong>{{ invoice.company.name }}</strong>",
            "<span>Order #:</span>\n            <strong>{{ order.orderNumber }}</strong>"
                => "<span>Invoice #:</span>\n            <strong>{{ invoice.documentNumber }}</strong>",
            '<strong>${{ order.total|default(0)|number_format(2) }}</strong>'
                => '<strong>${{ invoice.total|default(0)|number_format(2) }}</strong>',
            'Review Order</a>' => 'Review Invoice</a>',
        ],
    ];

    public function getDescription(): string
    {
        return 'Make the shipped invoice_customer / invoice_self email templates read invoice.* '
            . 'instead of order.*, so a standalone invoice (no sales order) can be emailed without crashing.';
    }

    public function up(Schema $schema): void
    {
        // Data-only change; the ALTER-free path (see Version20260803170000's postUp) applies here too.
    }

    public function postUp(Schema $schema): void
    {
        // Never $schema->hasTable(...) here — the injected Schema is a lazy proxy whose first call
        // introspects the whole database, and this app has carried an expression index since
        // Version20260821160000 that DBAL's own Index::_addColumn() cannot parse. See
        // SqliteMigrationIntrospection's docblock. PRAGMA table_info() via that trait instead.
        if (!$this->tableExists('email_template')) {
            return;
        }

        foreach (self::SUBJECT_REPLACEMENTS as $code => $subs) {
            foreach ($subs as $search => $replace) {
                $this->connection->executeStatement(
                    'UPDATE email_template SET subject = REPLACE(subject, ?, ?) WHERE code = ?',
                    [$search, $replace, $code],
                );
            }
        }

        foreach (self::BODY_REPLACEMENTS as $code => $subs) {
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
        if (!$this->tableExists('email_template')) {
            return;
        }

        foreach (self::SUBJECT_REPLACEMENTS as $code => $subs) {
            foreach ($subs as $search => $replace) {
                $this->connection->executeStatement(
                    'UPDATE email_template SET subject = REPLACE(subject, ?, ?) WHERE code = ?',
                    [$replace, $search, $code],
                );
            }
        }

        foreach (self::BODY_REPLACEMENTS as $code => $subs) {
            foreach ($subs as $search => $replace) {
                $this->connection->executeStatement(
                    'UPDATE email_template SET body = REPLACE(body, ?, ?) WHERE code = ?',
                    [$replace, $search, $code],
                );
            }
        }
    }
}
