<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy\Importer;

use App\Maxeme\Legacy\LegacyImporterInterface;
use App\Maxeme\Legacy\LegacyTableCopier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Legacy `vehicles` → maxeme_vehicle, ids kept (after clients: every vehicle has an owner). The
 * legacy `active` is a '1'/'0' string, and a year of 0 meant "not entered".
 */
final class VehicleImporter implements LegacyImporterInterface
{
    public function __construct(
        private readonly LegacyTableCopier $copier,
    ) {
    }

    public static function name(): string
    {
        return 'vehicles';
    }

    public static function order(): int
    {
        return 30;
    }

    public function import(Connection $legacy, SymfonyStyle $io): int
    {
        return $this->copier->upsert('maxeme_vehicle', $legacy->iterateAssociative(
            "SELECT id, owner_id AS client_id, manufacturer, model, NULLIF(year, 0) AS year, vin, license_plate,
                    mileage, color, note, active = '1' AS active, last_updated
               FROM vehicles ORDER BY id",
        ), ['last_updated']);
    }
}
