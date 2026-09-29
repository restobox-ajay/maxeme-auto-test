<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy\Importer;

use App\Maxeme\Legacy\LegacyImporterInterface;
use App\Maxeme\Legacy\LegacyTableCopier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Legacy `invoicehasparts` → maxeme_invoice_part, ids kept (after invoice services: a service's
 * parts point at their service line through invoice_has_service_id).
 */
final class InvoicePartImporter implements LegacyImporterInterface
{
    public function __construct(
        private readonly LegacyTableCopier $copier,
    ) {
    }

    public static function name(): string
    {
        return 'invoice-parts';
    }

    public static function order(): int
    {
        return 72;
    }

    public function import(Connection $legacy, SymfonyStyle $io): int
    {
        return $this->copier->upsert('maxeme_invoice_part', $legacy->iterateAssociative(
            "SELECT l.id, l.invoice_id, p.id AS part_id, s.id AS service_line_id, l.parts_name AS name,
                    COALESCE(l.quantity, 1) AS quantity, NULLIF(l.current_unit_price, '') AS unit_price,
                    NULLIF(l.current_sale_price, '') AS sale_price, COALESCE(l.created_on, i.created_on, NOW()) AS created_on
               FROM invoicehasparts l
               JOIN invoice i ON i.id = l.invoice_id
               LEFT JOIN parts p ON p.id = l.parts_id
               LEFT JOIN invoicehasservices s ON s.id = l.invoice_has_service_id
              ORDER BY l.id",
        ), ['created_on']);
    }
}
