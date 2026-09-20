<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Service\QuantityScale;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DecimalType;

/**
 * A quantity of goods: `NUMERIC(14, 4)` in the database and a decimal STRING in PHP (#601, #645, #659).
 *
 * 14 and 4 are the COLUMN's scale, fixed here rather than per mapping, and are not the configured
 * scale. QuantityScale may be set to three places or none; that rounds what goes in, it does not
 * narrow the storage.
 */
final class QuantityType extends DecimalType
{
    public const NAME = 'quantity';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getDecimalTypeDeclarationSQL(['precision' => 14, 'scale' => 4]);
    }

    /**
     * SQLite's NUMERIC affinity returns the SHORTEST form — `5` for a column holding `5.0000` — so
     * without canonicalising, a refreshed entity answers `'5'` where it answered `'5.0000'` before
     * the flush and every `===` over a quantity depends on whether it was reloaded.
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $value === null ? null : QuantityScale::canonical((string) $value);
    }
}
