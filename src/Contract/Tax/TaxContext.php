<?php

declare(strict_types=1);

namespace App\Contract\Tax;

use App\Service\RegionSeedData;

final class TaxContext
{
    private const TAX_CLASS_PRIORITY = ['E' => 0, 'G' => 1, 'S' => 2];


    public readonly string $province;

    public function __construct(
        string $province,
        public readonly float $subtotal,
        public readonly string $taxClass = '',
        public readonly ?int $companyId = null,
    ) {
        $p = trim($province);
        // One shared resolver instead of a copy of the province map in each context. Addresses now
        // store codes, so this is normally a pass-through; it still resolves a legacy display name so
        // an unconverted caller cannot silently stop matching a calculator and produce a $0-tax order.
        $this->province = RegionSeedData::resolveProvinceAnyCountry($p);
    }

    /**
     * Normalizes a nullable tax code: null/empty counts as 'E' (exempt).
     * Stored codes are short ('E'/'G'/'S') since the short-code data migration.
     */
    public static function mapTaxCode(?string $code): string
    {
        if ($code === null || $code === '') {
            return 'E';
        }

        return $code;
    }

    /**
     * Resolves the highest tax class from a set of tax codes (priority S > G > E).
     *
     * @param iterable<string|null> $rawCodes
     */
    public static function resolveHighestTaxClass(iterable $rawCodes): string
    {
        $highest = 'E';
        foreach ($rawCodes as $raw) {
            $code = self::mapTaxCode($raw);
            if ((self::TAX_CLASS_PRIORITY[$code] ?? 0) > (self::TAX_CLASS_PRIORITY[$highest] ?? 0)) {
                $highest = $code;
            }
        }

        return $highest;
    }
}
