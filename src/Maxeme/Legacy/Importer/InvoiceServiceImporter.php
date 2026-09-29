<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy\Importer;

use App\Maxeme\Legacy\LegacyImporterInterface;
use App\Maxeme\Legacy\LegacyTableCopier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Legacy `invoicehasservices` → maxeme_invoice_service, ids kept (after invoices and services). */
final class InvoiceServiceImporter implements LegacyImporterInterface
{
    public function __construct(
        private readonly LegacyTableCopier $copier,
    ) {
    }

    public static function name(): string
    {
        return 'invoice-services';
    }

    public static function order(): int
    {
        return 71;
    }

    public function import(Connection $legacy, SymfonyStyle $io): int
    {
        return $this->copier->upsert('maxeme_invoice_service', $legacy->iterateAssociative(
            "SELECT l.id, l.invoice_id, s.id AS service_id, l.service_name AS name, COALESCE(l.quantity, 1) AS quantity,
                    NULLIF(l.current_sale_price, '') AS sale_price, COALESCE(l.created_on, i.created_on, NOW()) AS created_on
               FROM invoicehasservices l
               JOIN invoice i ON i.id = l.invoice_id
               LEFT JOIN service s ON s.id = l.service_id
              ORDER BY l.id",
        ), ['created_on']);
    }
}
