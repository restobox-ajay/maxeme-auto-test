<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GeoCountry;
use App\Entity\GeoProvince;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single source of truth for country/province choices and validation.
 *
 * Replaces five separate copies of this data: an identical PROVINCE_MAP constant in TaxContext,
 * ShippingContext and FeeContext; PROVINCE_CODES in CustomerImportService; and an inline REGION_MAP
 * JavaScript literal in the admin address form. Because each consumer had its own list, they could
 * drift — and because addresses stored display names rather than codes, every consumer had to
 * normalise, which is how an unrecognised spelling ended up silently producing a $0-tax order.
 *
 * A plain service, not a static facade: Symfony shares services, so the first call loads the 65 rows
 * and every later call in the request reads the array. That gives "load once, keep in RAM" without
 * static state to bridge into the container or reset between tests.
 *
 * Loaded per request rather than into a cache pool deliberately — it is one query against a unique
 * index, and a cross-request cache would buy nothing while adding an invalidation problem the moment
 * someone edits a row.
 *
 * Nothing on the tax/fee/shipping hot path calls this. Addresses store codes, so the Context value
 * objects compare 'BC' === 'BC' with no lookup; normalisation happens once, at the write boundary.
 */
final class Region
{
    /** @var array<string, array{name: string, active: bool, provinces: array<string, array{name: string, active: bool}>}>|null */
    private ?array $data = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return array<string, string> active country code => name, in sort order */
    public function countries(): array
    {
        $out = [];
        foreach ($this->data() as $code => $country) {
            if ($country['active']) {
                $out[$code] = $country['name'];
            }
        }

        return $out;
    }

    /** @return array<string, string> active province code => name for the country, in sort order */
    public function provinces(string $country): array
    {
        $country = $this->normalizeCountry($country);
        if ($country === null) {
            return [];
        }

        $out = [];
        foreach ($this->data()[$country]['provinces'] as $code => $province) {
            if ($province['active']) {
                $out[$code] = $province['name'];
            }
        }

        return $out;
    }

    /**
     * Accepts a code or a stored display name/alias and returns the canonical country code.
     * Returns null when it resolves to nothing — callers decide whether that is a validation
     * failure or a value to leave alone.
     */
    public function normalizeCountry(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $upper = strtoupper($raw);
        if (isset($this->data()[$upper])) {
            return $upper;
        }

        $lower = strtolower($raw);
        foreach ($this->data() as $code => $country) {
            if (strtolower($country['name']) === $lower) {
                return $code;
            }
        }

        // Falls back to the shared static resolver so a historical alias means the same thing here
        // as it does in the normalisation migration.
        $seeded = RegionSeedData::resolveCountry($raw);

        return isset($this->data()[$seeded]) ? $seeded : null;
    }

    /**
     * Accepts a code, a display name, or a known legacy alias ('Québec', 'Newfoundland') and returns
     * the canonical province code for that country. Null when unresolvable.
     */
    public function normalizeProvince(string $country, string $raw): ?string
    {
        $countryCode = $this->normalizeCountry($country);
        $raw = trim($raw);
        if ($countryCode === null || $raw === '') {
            return null;
        }

        $provinces = $this->data()[$countryCode]['provinces'];

        $upper = strtoupper($raw);
        if (isset($provinces[$upper])) {
            return $upper;
        }

        $lower = strtolower($raw);
        foreach ($provinces as $code => $province) {
            if (strtolower($province['name']) === $lower) {
                return $code;
            }
        }

        $seeded = RegionSeedData::resolveProvince($countryCode, $raw);

        return isset($provinces[$seeded]) ? $seeded : null;
    }

    /** True only for an active country. */
    public function isValidCountry(string $country): bool
    {
        $code = $this->normalizeCountry($country);

        return $code !== null && $this->data()[$code]['active'];
    }

    /**
     * True only for an active province of an active country.
     *
     * Inactive rows fail here but are not retroactively stripped from stored addresses — validation
     * runs on write, so an address saved before a province was deactivated keeps its value until
     * someone edits it.
     */
    public function isValidProvince(string $country, string $province): bool
    {
        $countryCode = $this->normalizeCountry($country);
        if ($countryCode === null || !$this->data()[$countryCode]['active']) {
            return false;
        }

        $code = $this->normalizeProvince($countryCode, $province);

        return $code !== null && ($this->data()[$countryCode]['provinces'][$code]['active'] ?? false);
    }

    /** Display name for a stored country code, or null if unknown. */
    public function countryName(string $country): ?string
    {
        $code = $this->normalizeCountry($country);

        return $code === null ? null : $this->data()[$code]['name'];
    }

    /**
     * Display name for a stored province code, or null if unknown.
     *
     * Includes inactive rows on purpose: a document showing a historical address must still render
     * "Nunavut" after someone stops selling there.
     */
    public function provinceName(string $country, string $province): ?string
    {
        $countryCode = $this->normalizeCountry($country);
        if ($countryCode === null) {
            return null;
        }

        $code = $this->normalizeProvince($countryCode, $province);

        return $code === null ? null : ($this->data()[$countryCode]['provinces'][$code]['name'] ?? null);
    }

    /**
     * @return array<string, array{name: string, active: bool, provinces: array<string, array{name: string, active: bool}>}>
     */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $countries = $this->entityManager->getRepository(GeoCountry::class)
            ->createQueryBuilder('c')
            ->orderBy('c.sortOrder', 'ASC')->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        $data = [];
        foreach ($countries as $country) {
            $data[$country->getCode()] = [
                'name' => $country->getName(),
                'active' => $country->isActive(),
                'provinces' => [],
            ];
        }

        // Queried directly rather than walked off GeoCountry::getProvinces(): the inverse collection
        // is empty for any country created earlier in the same request (its constructor initialises
        // it, so Doctrine never lazy-loads), which silently produced empty dropdowns.
        $provinces = $this->entityManager->getRepository(GeoProvince::class)
            ->createQueryBuilder('p')
            ->innerJoin('p.country', 'c')->addSelect('c')
            ->orderBy('p.sortOrder', 'ASC')->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($provinces as $province) {
            $countryCode = $province->getCountry()->getCode();
            if (!isset($data[$countryCode])) {
                continue;
            }

            $data[$countryCode]['provinces'][$province->getCode()] = [
                'name' => $province->getName(),
                'active' => $province->isActive(),
            ];
        }

        return $this->data = $data;
    }
}
