<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RegionSeedData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The database-free resolvers, used by both the normalisation migration and Region's fallback. They
 * have to agree on what a stored value means, so they are tested once here rather than twice.
 */
final class RegionSeedDataTest extends TestCase
{
    public function testCoversExactlyTheListsTheAddressFormOffered(): void
    {
        // 13 Canadian provinces/territories and 50 US states — the same set the dropdown already
        // had. Anything added or dropped here silently changes what an address may contain.
        self::assertCount(2, RegionSeedData::COUNTRIES);
        self::assertCount(13, RegionSeedData::PROVINCES['CA']);
        self::assertCount(50, RegionSeedData::PROVINCES['US']);
    }

    public function testProvinceCodesAreUniqueWithinACountry(): void
    {
        foreach (RegionSeedData::PROVINCES as $country => $provinces) {
            self::assertSame(
                \count($provinces),
                \count(array_unique(array_keys($provinces))),
                "Duplicate province code in {$country}",
            );
            self::assertSame(
                \count($provinces),
                \count(array_unique($provinces)),
                "Duplicate province name in {$country}",
            );
        }
    }

    public function testTheSameCodeCanMeanDifferentThingsInDifferentCountries(): void
    {
        // 'CA' is Canada the country and California the state. Codes are only unique per country,
        // which is why geo_province's unique constraint is (country_id, code).
        self::assertSame('California', RegionSeedData::PROVINCES['US']['CA']);
        self::assertSame('Canada', RegionSeedData::COUNTRIES['CA']);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function countryProvider(): iterable
    {
        yield 'code'                  => ['CA', 'CA'];
        yield 'lowercase code'        => ['ca', 'CA'];
        yield 'display name'          => ['Canada', 'CA'];
        yield 'name wrong case'       => ['CANADA', 'CA'];
        yield 'padded'                => ['  Canada  ', 'CA'];
        yield 'us display name'       => ['United States', 'US'];
        yield 'usa alias'             => ['USA', 'US'];
        yield 'long form alias'       => ['United States of America', 'US'];
        yield 'unknown'               => ['Atlantis', null];
        yield 'empty'                 => ['', null];
    }

    #[DataProvider('countryProvider')]
    public function testResolveCountry(string $raw, ?string $expected): void
    {
        self::assertSame($expected, RegionSeedData::resolveCountry($raw));
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function provinceProvider(): iterable
    {
        yield 'code'                    => ['CA', 'BC', 'BC'];
        yield 'lowercase code'          => ['CA', 'bc', 'BC'];
        yield 'display name'            => ['CA', 'British Columbia', 'BC'];
        yield 'padded name'             => ['CA', '  Ontario ', 'ON'];
        // Accepted by the old TaxContext::PROVINCE_MAP, so still has to resolve.
        yield 'accented legacy alias'   => ['CA', 'Québec', 'QC'];
        yield 'canonical quebec'        => ['CA', 'Quebec', 'QC'];
        yield 'short legacy alias'      => ['CA', 'Newfoundland', 'NL'];
        yield 'us state name'           => ['US', 'Texas', 'TX'];
        yield 'us state code'           => ['US', 'wa', 'WA'];
        // The bug this whole change exists to kill: an unrecognised spelling must NOT silently
        // resolve to something, because that is what produced $0-tax orders.
        yield 'abbreviated with dots'   => ['CA', 'B.C.', null];
        yield 'wrong country for state' => ['CA', 'Texas', null];
        yield 'unknown'                 => ['CA', 'Nowhere', null];
        yield 'empty'                   => ['CA', '', null];
        yield 'unknown country'         => ['XX', 'BC', null];
    }

    #[DataProvider('provinceProvider')]
    public function testResolveProvince(string $country, string $raw, ?string $expected): void
    {
        self::assertSame($expected, RegionSeedData::resolveProvince($country, $raw));
    }

    /**
     * resolveProvinceAnyCountry() is only safe while the countries share no province code, name or
     * alias — the three Context value objects rely on it to normalise without knowing a country.
     * If a future country introduces an overlap this fails, which is the point.
     */
    public function testProvinceIdentifiersAreUnambiguousAcrossCountries(): void
    {
        $seenCodes = [];
        $seenNames = [];

        foreach (RegionSeedData::PROVINCES as $country => $provinces) {
            foreach ($provinces as $code => $name) {
                self::assertArrayNotHasKey(
                    $code,
                    $seenCodes,
                    "Province code {$code} appears in both {$seenCodes[$code]} and {$country}",
                );
                $lower = strtolower($name);
                self::assertArrayNotHasKey(
                    $lower,
                    $seenNames,
                    "Province name {$name} appears in both " . ($seenNames[$lower] ?? '?') . " and {$country}",
                );
                $seenCodes[$code] = $country;
                $seenNames[$lower] = $country;
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function anyCountryProvider(): iterable
    {
        yield 'canadian code'   => ['BC', 'BC'];
        yield 'canadian name'   => ['British Columbia', 'BC'];
        yield 'us code'         => ['TX', 'TX'];
        yield 'us name'         => ['Texas', 'TX'];
        yield 'legacy alias'    => ['Québec', 'QC'];
        // Unknown values come back uppercased rather than null, so they still compare unequal to
        // every real code instead of turning into an empty province.
        yield 'unknown'         => ['B.C.', 'B.C.'];
        yield 'empty'           => ['', ''];
    }

    #[DataProvider('anyCountryProvider')]
    public function testResolveProvinceAnyCountry(string $raw, string $expected): void
    {
        self::assertSame($expected, RegionSeedData::resolveProvinceAnyCountry($raw));
    }

    public function testEveryAliasResolvesToARealProvince(): void
    {
        foreach (RegionSeedData::PROVINCE_ALIASES as $country => $aliases) {
            foreach ($aliases as $alias => $code) {
                self::assertArrayHasKey(
                    $code,
                    RegionSeedData::PROVINCES[$country],
                    "Alias '{$alias}' points at {$country}/{$code}, which does not exist",
                );
            }
        }
    }

    public function testEveryCountryAliasResolvesToARealCountry(): void
    {
        foreach (RegionSeedData::COUNTRY_ALIASES as $alias => $code) {
            self::assertArrayHasKey($code, RegionSeedData::COUNTRIES, "Alias '{$alias}' points at nothing");
        }
    }
}
