<?php

declare(strict_types=1);

namespace App\Service\Email;

/**
 * The email templates this application ships, as code rather than as rows.
 *
 * Everything here used to live in the database, seeded by literal SQL inside the migration chain —
 * 420 of the 692 lines of the #367 baseline were Twig HTML embedded in single-quoted PHP strings.
 * That made every change to email copy a migration, and a migration that has to REPLACE inside
 * admin-editable text has no way to know it matched nothing: Version20260805020000 exists purely to
 * re-apply Version20260805010000 on the databases where its REPLACE silently did nothing (#507).
 *
 * The bodies are real .twig files under templates/emails/shipped/, so they lint, diff and grep like
 * any other template. Only the one-line metadata lives in this class.
 *
 * NOTHING HERE IS WRITTEN TO THE DATABASE. An email_template row exists only when an admin has
 * changed something, or authored a template of their own; everything else resolves from here.
 *
 * This file and the .twig files beside it were GENERATED from a fully migrated database rather than
 * hand-transcribed, and ShippedEmailTemplatesMatchTheChainTest replays the migration chain and
 * asserts byte equality against them. That test is the whole safety argument: a single altered
 * character here is a silent change to a customer-facing email, and nothing else in the suite would
 * notice. Once the chain is re-baselined without the seed, that test becomes the record of what the
 * chain used to produce, which is exactly what it is for.
 */
final class ShippedEmailTemplates
{
    private const DIRECTORY = __DIR__ . '/../../../templates/emails/shipped';

    /**
     * Metadata only — each body is the .twig file named for its code.
     *
     * @var array<string, array{module: string, sentTo: string, subject: string, description: ?string, status: string}>
     */
    private const TEMPLATES = [
        'company_registration' => [
            'module' => 'Company Registration',
            'sentTo' => 'Customer',
            'subject' => 'Company registration received - {{ site_name() }}',
            'description' => 'Sent to the company email after a successful registration.',
            'status' => 'Active',
        ],
        'company_registration_admin_alert' => [
            'module' => 'Company Registration Admin Alert',
            'sentTo' => 'Admin',
            'subject' => 'New company registration pending approval',
            'description' => 'Sent to admins when a new company registration requires approval.',
            'status' => 'Active',
        ],
        'email_changed' => [
            'module' => 'Email Change Notification',
            'sentTo' => 'Customer',
            'subject' => 'Your account\'s email has changed - {{ site_name() }}',
            'description' => 'Sent to a customer\'s previous email address when an admin changes their account email.',
            'status' => 'Active',
        ],
        'forgot_password' => [
            'module' => 'forgot_password',
            'sentTo' => 'Customer',
            'subject' => 'Reset your password',
            'description' => NULL,
            'status' => 'Active',
        ],
        'invite' => [
            'module' => 'Invite (Legacy)',
            'sentTo' => 'Customer',
            'subject' => 'Invitation to {{ site_name() }}',
            'description' => 'Legacy alias for invitation template.',
            'status' => 'Active',
        ],
        'invoice_customer' => [
            'module' => 'Invoice (Customer)',
            'sentTo' => 'Customer',
            'subject' => 'Invoice #{{ invoice.documentNumber }}',
            'description' => 'Email body used when sending invoice to customer/company.',
            'status' => 'Active',
        ],
        'invoice_self' => [
            'module' => 'Invoice (Internal)',
            'sentTo' => 'Admin',
            'subject' => 'Internal: Invoice #{{ invoice.documentNumber }}',
            'description' => 'Email body used when sending internal invoice copy.',
            'status' => 'Active',
        ],
        'login' => [
            'module' => 'login',
            'sentTo' => 'Customer',
            'subject' => 'Login notification',
            'description' => NULL,
            'status' => 'Active',
        ],
        'new_shipping_address' => [
            'module' => 'New shipping address',
            'sentTo' => 'Customer',
            'subject' => 'Shipping address updated',
            'description' => 'Shipping address update notification (optional).',
            'status' => 'Active',
        ],
        'new_user_invited' => [
            'module' => 'New User Invited',
            'sentTo' => 'Customer',
            'subject' => 'Invitation to {{ site_name() }}',
            'description' => 'Sent when an admin invites a new user to set a password.',
            'status' => 'Active',
        ],
        'order_approved' => [
            'module' => 'Order Approved',
            'sentTo' => 'Customer',
            'subject' => 'Order approved: {{ order.orderNumber|default(\'\') }}',
            'description' => 'Order approved notification (optional).',
            'status' => 'Active',
        ],
        'order_cancelled' => [
            'module' => 'Order Cancelled',
            'sentTo' => 'Customer',
            'subject' => 'Order cancelled: {{ order.orderNumber|default(\'\') }}',
            'description' => 'Order cancelled notification (optional).',
            'status' => 'Active',
        ],
        'order_message' => [
            'module' => 'Order Note For Customer',
            'sentTo' => 'Customer',
            'subject' => 'Message about order {{ order.orderNumber|default(\'\') }}',
            'description' => 'Sent to the company when an admin posts an order note/message.',
            'status' => 'Active',
        ],
        'order_received' => [
            'module' => 'Order Received',
            'sentTo' => 'Customer',
            'subject' => 'Order received: {{ order.orderNumber|default(\'\') }}',
            'description' => 'Order received confirmation (optional).',
            'status' => 'Active',
        ],
        'order_received_admin' => [
            'module' => 'Order Received (Admin)',
            'sentTo' => 'Admin',
            'subject' => 'New order received: {{ order.orderNumber|default(\'\') }}',
            'description' => 'Sent to active admins when a new order is placed (checkout or quote approval).',
            'status' => 'Active',
        ],
        'order_status_update' => [
            'module' => 'Order Status Update',
            'sentTo' => 'Customer',
            'subject' => 'Order Update: {{ order.orderNumber|default(\'\') }}',
            'description' => 'Sent to the company when an order status changes.',
            'status' => 'Active',
        ],
        'quote_approved_admin' => [
            'module' => 'Quote Approved (Admin)',
            'sentTo' => 'Admin',
            'subject' => 'Quote approved: {{ estimate.documentNumber|default(\'\') }}',
            'description' => 'Sent to active admins when a customer approves a priced quote.',
            'status' => 'Active',
        ],
        'quote_declined_admin' => [
            'module' => 'Quote Declined (Admin)',
            'sentTo' => 'Admin',
            'subject' => 'Quote declined: {{ estimate.documentNumber|default(\'\') }}',
            'description' => 'Sent to active admins when a customer declines/cancels a quote.',
            'status' => 'Active',
        ],
        'quote_provided' => [
            'module' => 'Quote Provided',
            'sentTo' => 'Customer',
            'subject' => 'Your quote is ready: {{ estimate.documentNumber|default(\'\') }}',
            'description' => 'Sent to the customer when admin finishes pricing a quote and sends it for approval.',
            'status' => 'Active',
        ],
        'quote_request_admin' => [
            'module' => 'Quote Request (Admin)',
            'sentTo' => 'Admin',
            'subject' => 'New quote request: {{ estimate.documentNumber|default(\'\') }}',
            'description' => 'Sent to active admins when a customer submits a quote request.',
            'status' => 'Active',
        ],
        'quote_request_received' => [
            'module' => 'Quote Request Received',
            'sentTo' => 'Customer',
            'subject' => 'Quote request received: {{ estimate.documentNumber|default(\'\') }}',
            'description' => 'Sent to the customer when a quote request (estimate) is submitted.',
            'status' => 'Active',
        ],
        'register' => [
            'module' => 'register',
            'sentTo' => 'Customer',
            'subject' => 'Your account is now active - {{ site_name() }}',
            'description' => NULL,
            'status' => 'Active',
        ],
    ];

    /** @return list<string> Every shipped code, in a stable order. */
    public static function codes(): array
    {
        return array_keys(self::TEMPLATES);
    }

    public static function has(string $code): bool
    {
        return isset(self::TEMPLATES[$code]);
    }

    /**
     * The shipped definition for a code, body included, or null if nothing ships under it — which
     * is the normal answer for a template an admin authored in the panel.
     *
     * @return array{module: string, sentTo: string, subject: string, description: ?string, status: string, body: string}|null
     */
    public static function get(string $code): ?array
    {
        if (!isset(self::TEMPLATES[$code])) {
            return null;
        }

        return self::TEMPLATES[$code] + ['body' => self::body($code)];
    }

    /**
     * The trailing newline is stripped rather than stored.
     *
     * Not one of the 22 shipped bodies ends with a newline, but a file that does not end with one
     * is a file every editor and half the tooling will silently "fix" — so the files carry the
     * newline POSIX expects and it comes back off here. Without this, byte equality starts failing
     * the first time somebody opens one of them.
     */
    private static function body(string $code): string
    {
        $path = self::DIRECTORY . '/' . $code . '.html.twig';
        $source = @file_get_contents($path);

        if ($source === false) {
            throw new \RuntimeException(sprintf('Shipped email template "%s" is missing its body at %s.', $code, $path));
        }

        return rtrim($source, "\n");
    }
}
