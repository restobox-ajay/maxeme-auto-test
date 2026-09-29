<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy\Importer;

use App\Maxeme\Legacy\LegacyImporterInterface;
use App\Maxeme\Legacy\LegacyTableCopier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Legacy `inventoryhistory` → maxeme_inventory_history, ids kept (after parts). The rows are copied
 * as history only: the parts' quantities were imported as they stood, so nothing is replayed.
 * The legacy client_id column is never written by the legacy code and is not carried over.
 */
final class InventoryHistoryImporter implements LegacyImporterInterface
{
    public function __construct(
        private readonly LegacyTableCopier $copier,
    ) {
    }

    public static function name(): string
    {
        return 'inventory-history';
    }

    public static function order(): int
    {
        return 45;
    }

    public function import(Connection $legacy, SymfonyStyle $io): int
    {
        return $this->copier->upsert('maxeme_inventory_history', $legacy->iterateAssociative(
            "SELECT id, part_id, quantity, NULLIF(unit_price, '') AS unit_price, NULLIF(sale_price, '') AS sale_price,
                    created_on, note, invoice_id, po_number
               FROM inventoryhistory ORDER BY id",
        ), ['created_on']);
    }
}
