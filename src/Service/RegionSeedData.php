<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The canonical country/province list, as PHP rather than SQL.
 *
 * Lives in code — not only in the seed migration — because three separate things need it:
 *
 *   1. the migration that creates and seeds geo_country / geo_province;
 *   2. the test suites, which build schema with SchemaTool and never run migrations, so without a
 *      programmatic seed every geo table would be empty in every test and all province validation
 *      would fail;
 *   3. the one-off migration that normalises existing company_address rows from display names to
 *      codes, which needs the name => code mapping.
 *
 * Names are taken verbatim from the list the address form's dropdown already offered (13 Canadian
 * provinces/territories and 50 US states) so nothing the UI accepted before becomes invalid.
 *
 * @see Region for the runtime API. This class is data only.
 */
final class RegionSeedData
{
    /** country code => display name */
    public const COUNTRIES = [
        'CA' => 'Canada',
        'US' => 'United States',
    ];

    /** country code => [province code => display name] */
    public const PROVINCES = [
        'CA' => [
            'AB' => 'Alberta',
            'BC' => 'British Columbia',
            'MB' => 'Manitoba',
            'NB' => 'New Brunswick',
            'NL' => 'Newfoundland and Labrador',
            'NS' => 'Nova Scotia',
            'NT' => 'Northwest Territories',
            'NU' => 'Nunavut',
            'ON' => 'Ontario',
            'PE' => 'Prince Edward Island',
            'QC' => 'Quebec',
            'SK' => 'Saskatchewan',
            'YT' => 'Yukon',
        ],
        'US' => [
            'AL' => 'Alabama',
            'AK' => 'Alaska',
            'AZ' => 'Arizona',
            'AR' => 'Arkansas',
            'CA' => 'California',
            'CO' => 'Colorado',
            'CT' => 'Connecticut',
            'DE' => 'Delaware',
            'FL' => 'Florida',
            'GA' => 'Georgia',
            'HI' => 'Hawaii',
            'ID' => 'Idaho',
            'IL' => 'Illinois',
            'IN' => 'Indiana',
            'IA' => 'Iowa',
            'KS' => 'Kansas',
            'KY' => 'Kentucky',
            'LA' => 'Louisiana',
            'ME' => 'Maine',
            'MD' => 'Maryland',
            'MA' => 'Massachusetts',
            'MI' => 'Michigan',
            'MN' => 'Minnesota',
            'MS' => 'Mississippi',
            'MO' => 'Missouri',
            'MT' => 'Montana',
            'NE' => 'Nebraska',
            'NV' => 'Nevada',
            'NH' => 'New Hampshire',
            'NJ' => 'New Jersey',
            'NM' => 'New Mexico',
            'NY' => 'New York',
            'NC' => 'North Carolina',
            'ND' => 'North Dakota',
            'OH' => 'Ohio',
            'OK' => 'Oklahoma',
            'OR' => 'Oregon',
            'PA' => 'Pennsylvania',
            'RI' => 'Rhode Island',
            'SC' => 'South Carolina',
            'SD' => 'South Dakota',
            'TN' => 'Tennessee',
            'TX' => 'Texas',
            'UT' => 'Utah',
            'VT' => 'Vermont',
            'VA' => 'Virginia',
            'WA' => 'Washington',
            'WV' => 'West Virginia',
            'WI' => 'Wisconsin',
            'WY' => 'Wyoming',
        ],
    ];

    /**
     * Spellings the app has stored historically that are not the canonical name, mapped to their
     * code. Used by the normalisation migration and by Region::normalizeProvince() so a legacy
     * value still resolves.
     *
     * 'Québec' was accepted by TaxContext's old PROVINCE_MAP; 'Newfoundland' was too.
     *
     * @var array<string, array<string, string>> country code => [lowercased alias => province code]
     */
    public const PROVINCE_ALIASES = [
        'CA' => [
            'québec' => 'QC',
            'newfoundland' => 'NL',
        ],
        'US' => [],
    ];

    /** Country names/aliases the app has stored historically, lowercased => code. */
    public const COUNTRY_ALIASES = [
        'canada' => 'CA',
        'united states' => 'US',
        'united states of america' => 'US',
        'usa' => 'US',
        'us' => 'US',
        'ca' => 'CA',
    ];

    /**
     * Resolve a stored country value — code, display name, or historical alias — to its code.
     *
     * Static and database-free on purpose: the normalisation migration needs this before the geo
     * tables can be trusted, and {@see Region} reuses it as its fallback so the two can never
     * disagree about what 'Canada' means.
     */
    public static function resolveCountry(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $upper = strtoupper($raw);
        if (isset(self::COUNTRIES[$upper])) {
            return $upper;
        }

        $lower = strtolower($raw);
        foreach (self::COUNTRIES as $code => $name) {
            if (strtolower($name) === $lower) {
                return $code;
            }
        }

        return self::COUNTRY_ALIASES[$lower] ?? null;
    }

    /**
     * Resolve a province value without knowing its country.
     *
     * Used by AbstractSalesDocument::getProvince() and by the TaxContext / FeeContext value objects,
     * which are handed a province but no country. Safe because the two countries share no province code, no province
     * name and no alias — verified by RegionSeedDataTest, which fails if a future addition creates an
     * overlap and makes this ambiguous.
     *
     * Returns the raw value trimmed and uppercased when nothing resolves, so a genuinely unknown
     * value still compares as unequal to every real code rather than becoming null.
     */
    public static function resolveProvinceAnyCountry(string $raw): string
    {
        return self::knownProvinceAnyCountry($raw) ?? strtoupper(trim($raw));
    }

    /**
     * The same lookup, but NULL rather than the raw value when nothing in either country matches
     * (queue item 61).
     *
     * `resolveProvinceAnyCountry()` above cannot answer "is this a province at all": it hands back
     * the typed string uppercased, so `resolveProvinceAnyCountry('XX')` is `'XX'` and every caller
     * testing its result against `''` is testing for an EMPTY INPUT and nothing else. Two such
     * callers existed and both read as validation while refusing nothing —
     * `ConfigController::handleWarehouseForm()`'s "is not a province or state code" branch, which
     * could not fire, and `Warehouse::setProvince()`, whose docblock promised to store an
     * unresolvable value as null and stored `'XX'`.
     *
     * That distinction is load-bearing now that a warehouse may not exist without a province: a
     * province nothing recognises passes every "the province is known" gate in the application and
     * then no calculator claims it, which produces the $0.00-derived-from-nothing this item exists
     * to close. Anything asking "may this be saved" asks here; anything merely normalising a value
     * that is already trusted may keep using the lenient one.
     */
    public static function knownProvinceAnyCountry(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        foreach (array_keys(self::PROVINCES) as $countryCode) {
            $code = self::resolveProvince($countryCode, $raw);
            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    /** Resolve a stored province value within a country to its code. Null when unresolvable. */
    public static function resolveProvince(string $countryCode, string $raw): ?string
    {
        $raw = trim($raw);
        $countryCode = strtoupper(trim($countryCode));
        if ($raw === '' || !isset(self::PROVINCES[$countryCode])) {
            return null;
        }

        $provinces = self::PROVINCES[$countryCode];

        $upper = strtoupper($raw);
        if (isset($provinces[$upper])) {
            return $upper;
        }

        $lower = strtolower($raw);
        foreach ($provinces as $code => $name) {
            if (strtolower($name) === $lower) {
                return $code;
            }
        }

        return self::PROVINCE_ALIASES[$countryCode][$lower] ?? null;
    }
}
