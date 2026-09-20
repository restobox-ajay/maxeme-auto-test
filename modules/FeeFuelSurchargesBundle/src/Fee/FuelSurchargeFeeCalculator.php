<?php

declare(strict_types=1);

namespace FeeFuelSurchargesBundle\Fee;

use App\Contract\Fee\FeeCalculatorInterface;
use App\Contract\Fee\FeeContext;
use App\Contract\Fee\FeeFieldProviderInterface;
use App\Contract\Fee\FeeLine;
use App\Entity\ProductCore;
use App\Repository\FeeRepository;
use App\Repository\ProductFeeRepository;
use Symfony\Component\HttpFoundation\Request;

final class FuelSurchargeFeeCalculator implements FeeCalculatorInterface, FeeFieldProviderInterface
{
    public const SOURCE = 'FeeFuelSurchargesBundle';

    private const LOW_PROVINCES = ['AB', 'SK', 'MB'];

    public const FEES = [
        ['slug' => 'fuel-surcharge-low',  'name' => 'Fuel Surcharge (AB/SK/MB)', 'taxClass' => 'G', 'defaultValue' => 5.0],
        ['slug' => 'fuel-surcharge-high', 'name' => 'Fuel Surcharge (Other)',    'taxClass' => 'G', 'defaultValue' => 15.0],
    ];

    public function __construct(
        private readonly FeeRepository $feeRepo,
        private readonly ProductFeeRepository $productFeeRepo,
    ) {}

    public function supports(FeeContext $context): bool
    {
        return $context->province !== 'BC' && $context->province !== '';
    }

    public function calculate(FeeContext $context): array
    {
        $isLow = in_array($context->province, self::LOW_PROVINCES, true);
        $def   = $isLow ? self::FEES[0] : self::FEES[1];

        $fee = $this->feeRepo->ensureBySlug($def['slug'], $def + ['source' => self::SOURCE]);

        $applicable = false;
        foreach ($context->cartItems as ['product' => $product, 'qty' => $qty]) {
            if ($qty > 0 && $this->productFeeRepo->getValueForProduct($product, $fee) != 0.0) {
                $applicable = true;
                break;
            }
        }

        if (!$applicable || $fee->getDefaultValue() == 0.0) {
            return [];
        }

        return [new FeeLine($fee->getId(), $fee->getSlug(), $fee->getName(), $fee->getTaxClass(), $fee->getDefaultValue(), $fee->getPlacement())];
    }

    public function renderProductFields(ProductCore $product): string
    {
        $lowDef  = self::FEES[0];
        $highDef = self::FEES[1];
        $feeLow  = $this->feeRepo->ensureBySlug($lowDef['slug'],  $lowDef  + ['source' => self::SOURCE]);
        $feeHigh = $this->feeRepo->ensureBySlug($highDef['slug'], $highDef + ['source' => self::SOURCE]);

        $checked  = $this->productFeeRepo->getValueForProduct($product, $feeLow) == 1.0 ? ' checked' : '';
        $rateLow  = number_format($feeLow->getDefaultValue(), 2);
        $rateHigh = number_format($feeHigh->getDefaultValue(), 2);

        return <<<HTML
            <label class="check-row">
                <input type="checkbox" name="fee[fuel-surcharge]" value="1"{$checked}>
                Fuel Surcharge (AB/SK/MB: \${$rateLow} &bull; Other: \${$rateHigh} per eligible order)
            </label>
            HTML;
    }

    public function saveProductFields(ProductCore $product, Request $request): void
    {
        $value   = $request->request->all('fee')['fuel-surcharge'] ?? null;
        $eligible = $value !== null ? 1.0 : 0.0;

        foreach (self::FEES as $def) {
            $fee = $this->feeRepo->ensureBySlug($def['slug'], $def + ['source' => self::SOURCE]);
            $this->productFeeRepo->setValue($product, $fee, $eligible);
        }
    }
}
