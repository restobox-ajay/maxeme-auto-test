<?php

declare(strict_types=1);

namespace TaxCanadaSimpleBundle\Tax;

use App\Contract\Tax\TaxCalculatorInterface;
use App\Contract\Tax\TaxContext;
use App\Contract\Tax\TaxLine;
use App\Entity\SalesTax;
use App\Repository\SalesTaxRepository;

final class CanadaSimpleTaxCalculator implements TaxCalculatorInterface
{
    public const SOURCE = 'TaxCanadaSimpleBundle';

    /**
     * Every non-BC province (BC is handled by TaxBCBundle). Each province maps to
     * 1-2 tax components. The first component is the primary rate and applies to
     * both 'G' and 'S' tax classes. A second component, when present, is the
     * provincial sales tax component and is only added for 'S' — no exemption.
     * Company::$pstNumber is BC's own registration number and has no bearing on
     * any other province's PST/RST/QST, so it is never read here.
     */
    public const PROVINCES = [
        'ON' => ['name' => 'Ontario', 'components' => [['type' => 'HST', 'rate' => 0.13]]],
        'NB' => ['name' => 'New Brunswick', 'components' => [['type' => 'HST', 'rate' => 0.15]]],
        'NL' => ['name' => 'Newfoundland and Labrador', 'components' => [['type' => 'HST', 'rate' => 0.15]]],
        'NS' => ['name' => 'Nova Scotia', 'components' => [['type' => 'HST', 'rate' => 0.15]]],
        'PE' => ['name' => 'Prince Edward Island', 'components' => [['type' => 'HST', 'rate' => 0.15]]],
        'AB' => ['name' => 'Alberta', 'components' => [['type' => 'GST', 'rate' => 0.05]]],
        'SK' => ['name' => 'Saskatchewan', 'components' => [['type' => 'GST', 'rate' => 0.05], ['type' => 'PST', 'rate' => 0.06]]],
        'MB' => ['name' => 'Manitoba', 'components' => [['type' => 'GST', 'rate' => 0.05], ['type' => 'PST', 'rate' => 0.07]]],
        'QC' => ['name' => 'Quebec', 'components' => [['type' => 'GST', 'rate' => 0.05], ['type' => 'QST', 'rate' => 0.09975]]],
    ];

    public function __construct(private readonly SalesTaxRepository $salesTaxRepo) {}

    public function supports(TaxContext $context): bool
    {
        return $context->province !== 'BC' && isset(self::PROVINCES[$context->province]);
    }

    public function calculate(TaxContext $context): array
    {
        $province = self::PROVINCES[$context->province] ?? null;
        if ($province === null) {
            return [];
        }

        $rows = $this->ensureProvinceRows($context->province, $province);

        if ($context->taxClass === 'E') {
            return [];
        }

        $lines = [];
        foreach ($rows as $index => $row) {
            if ($row->getStatus() !== 'Active') {
                continue;
            }
            if ($index > 0 && $context->taxClass !== 'S') {
                continue;
            }

            $lines[] = new TaxLine($row->getTaxType(), $row->getRate(), round($context->subtotal * $row->getRate(), 2), $row->getSlug());
        }

        return $lines;
    }

    /**
     * @param array{name: string, components: array<int, array{type: string, rate: float}>} $province
     * @return SalesTax[]
     */
    public function ensureProvinceRows(string $provinceCode, array $province): array
    {
        $rows = [];
        foreach ($province['components'] as $component) {
            // GST is federal and identical in every province it applies to, so it's looked
            // up under the same 'gst' slug everywhere instead of a separate row per province.
            $isGst = $component['type'] === 'GST';
            $rows[] = $this->salesTaxRepo->getBySlug($isGst ? 'gst' : self::slugFor($provinceCode, $component['type']), [
                'province_name' => $isGst ? 'Canada (Federal)' : $province['name'],
                'abbreviation' => $isGst ? '' : $provinceCode,
                'tax_type' => $component['type'],
                'rate' => $component['rate'],
                'status' => 'Active',
                'source' => self::SOURCE,
            ]);
        }

        return $rows;
    }

    public static function slugFor(string $provinceCode, string $taxType): string
    {
        return strtolower($provinceCode).'-'.strtolower($taxType);
    }
}
