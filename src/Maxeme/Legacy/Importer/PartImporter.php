<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy\Importer;

use App\Maxeme\Enum\PartType;
use App\Maxeme\Legacy\LegacyImporterInterface;
use App\Maxeme\Legacy\LegacyTableCopier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Legacy `parts` → maxeme_part, ids kept. Prices were free-text strings (blank = none); a type
 * outside the three choices (legacy rows hold 'PADS', 'oil filter', blanks) becomes none, and a
 * missing quantity becomes 0.
 */
final class PartImporter implements LegacyImporterInterface
{
    public function __construct(
        private readonly LegacyTableCopier $copier,
    ) {
    }

    public static function name(): string
    {
        return 'parts';
    }

    public static function order(): int
    {
        return 40;
    }

    public function import(Connection $legacy, SymfonyStyle $io): int
    {
        $rows = $legacy->iterateAssociative(
            "SELECT id, vin, name, manufacturer, type, description, vendor,
                    NULLIF(unit_price, '') AS unit_price, NULLIF(sale_price, '') AS sale_price,
                    COALESCE(quantity, 0) AS quantity, notes, active = '1' AS active
               FROM parts ORDER BY id",
        );

        return $this->copier->upsert('maxeme_part', (static function () use ($rows): \Generator {
            foreach ($rows as $row) {
                $row['type'] = PartType::tryFrom((string) $row['type'])?->value;
                yield $row;
            }
        })());
    }
}
