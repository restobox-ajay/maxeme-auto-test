<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A product's sales tax code as stored: the short codes E (exempt), G (GST) and S (PST/GST). Older
 * data and import files may still carry the legacy values (0/EXEMPT, 5/GST, L/PST), which map here.
 *
 * Shared by the product form and the product import; the import's copy used not to trim, so a
 * " GST" cell stayed " GST" instead of becoming G.
 */
final class SalesTaxCode
{
    /** The canonical code for $code ('' stays ''); an unknown code is returned trimmed, as typed. */
    public static function normalize(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }

        return match (strtoupper($code)) {
            '0', 'EXEMPT', 'E' => 'E',
            '5', 'GST', 'G' => 'G',
            'L', 'PST', 'S' => 'S',
            default => $code,
        };
    }
}
