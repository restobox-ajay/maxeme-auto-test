<?php

declare(strict_types=1);

namespace ProcurementBundle\Email;

use App\Contract\Email\ShippedEmailTemplateProviderInterface;

/**
 * Puts this bundle's emails into the shipped catalogue (#563).
 *
 * Before the provider seam existed, `purchase_order_vendor` shipped only as a fallback Twig view.
 * It sent correctly and an admin-authored row overrode it correctly, but it did not appear in
 * Settings → Email Templates at all until someone created that row by hand — so the one screen
 * built for finding and rewording emails could not see it.
 *
 * The reason it was left out is worth recording, because it was a real constraint rather than an
 * oversight: `ShippedEmailTemplates` is byte-pinned to the migration chain by
 * ShippedEmailTemplatesMatchTheChainTest, and while that test asserted set EQUALITY a 23rd entry
 * would have had to be seeded back INTO the chain — the exact thing #507 removed. That assertion is
 * now one-directional (`d942ad13`), so the catalogue can grow; but core still must not hardcode an
 * optional bundle's template, which is what this interface is for.
 *
 * The body is read from the same Twig file the fallback path renders, so there is exactly one copy
 * of it on disk and the catalogue entry cannot drift from what actually sends.
 */
final class ProcurementEmailTemplateProvider implements ShippedEmailTemplateProviderInterface
{
    public const PURCHASE_ORDER_VENDOR = 'purchase_order_vendor';

    /** @var array<string, array{module: string, sentTo: string, subject: string, description: ?string, status: string, body: string}>|null */
    private ?array $definitions = null;

    /** @return list<string> */
    public function codes(): array
    {
        return [self::PURCHASE_ORDER_VENDOR];
    }

    /**
     * @return array{module: string, sentTo: string, subject: string, description: ?string, status: string, body: string}|null
     */
    public function get(string $code): ?array
    {
        return $this->definitions()[$code] ?? null;
    }

    /** @return array<string, array{module: string, sentTo: string, subject: string, description: ?string, status: string, body: string}> */
    private function definitions(): array
    {
        return $this->definitions ??= [
            self::PURCHASE_ORDER_VENDOR => [
                'module' => 'Purchase Order (Vendor)',
                'sentTo' => 'Vendor',
                'subject' => 'Purchase order {{ purchase_order.poNumber|default(\'\') }}',
                'description' => 'Sent to a vendor when a purchase order is issued to them.',
                'status' => 'Active',
                'body' => $this->body(self::PURCHASE_ORDER_VENDOR),
            ],
        ];
    }

    /**
     * rtrim'd for the same reason ShippedEmailTemplates::body() does it: no stored body ends with a
     * newline, the file on disk carries the POSIX one, and without the trim every bundle template
     * would read as "customized" the moment an admin opened and saved it unchanged.
     */
    private function body(string $code): string
    {
        $path = \dirname(__DIR__, 2) . '/templates/emails/' . $code . '.html.twig';
        $source = is_file($path) ? file_get_contents($path) : false;

        return $source === false ? '' : rtrim($source, "\n");
    }
}
