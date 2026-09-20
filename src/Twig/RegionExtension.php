<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Region;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the country/province reference data to templates.
 *
 * Two jobs, and they are deliberately different:
 *
 *   - `region_countries()` / `region_provinces(country)` build the <select> options. They return
 *     active rows only, because you should not be able to pick a country or province that has been
 *     turned off.
 *   - `province_name()` / `country_name()` render a stored code as a display name. They include
 *     inactive rows, because an invoice for an order shipped to a province you have since stopped
 *     serving must still print that province rather than a blank.
 *
 * Addresses store codes; every place that used to print `address.province` directly and get
 * "British Columbia" now prints a code, so it goes through `province_name()`.
 */
final class RegionExtension extends AbstractExtension
{
    public function __construct(private readonly Region $region)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('region_countries', [$this, 'countries']),
            new TwigFunction('region_provinces', [$this, 'provinces']),
            new TwigFunction('region_map', [$this, 'map']),
            new TwigFunction('country_name', [$this, 'countryName']),
            new TwigFunction('province_name', [$this, 'provinceName']),
        ];
    }

    /** @return array<string, string> code => name, active only, for dropdowns */
    public function countries(): array
    {
        return $this->region->countries();
    }

    /** @return array<string, string> code => name, active only, for dropdowns */
    public function provinces(?string $country): array
    {
        return $this->region->provinces((string) $country);
    }

    /**
     * The whole active country => provinces map, for forms that repopulate the province select when
     * the country changes. Emitted into a data attribute so the browser list and the server's
     * validation come from the same rows — the old inline REGION_MAP literal could not.
     *
     * @return array<string, array<string, string>>
     */
    public function map(): array
    {
        $out = [];
        foreach ($this->countries() as $code => $_name) {
            $out[$code] = $this->provinces($code);
        }

        return $out;
    }

    /**
     * Display name for a stored code. Falls back to the raw value so a legacy or unrecognised entry
     * still shows something rather than vanishing from a document.
     */
    public function countryName(?string $country): string
    {
        $country = trim((string) $country);

        return $this->region->countryName($country) ?? $country;
    }

    /** Display name for a stored province code, falling back to the raw value. */
    public function provinceName(?string $country, ?string $province): string
    {
        $province = trim((string) $province);

        return $this->region->provinceName((string) $country, $province) ?? $province;
    }
}
