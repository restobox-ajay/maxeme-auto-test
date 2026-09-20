<?php

declare(strict_types=1);

namespace FeeUserDefinedBundle\Fee;

use App\Contract\Fee\FeeCalculatorInterface;
use App\Contract\Fee\FeeContext;
use App\Contract\Fee\FeeFieldProviderInterface;
use App\Contract\Fee\FeeLine;
use App\Entity\ProductCore;
use App\Repository\FeeRepository;
use App\Repository\ProductFeeRepository;
use Symfony\Component\HttpFoundation\Request;

final class UserDefinedFeeCalculator implements FeeCalculatorInterface, FeeFieldProviderInterface
{
    public const SOURCE = 'FeeUserDefinedBundle';

    public function __construct(
        private readonly FeeRepository $feeRepo,
        private readonly ProductFeeRepository $productFeeRepo,
    ) {}

    public function supports(FeeContext $context): bool
    {
        return true;
    }

    public function calculate(FeeContext $context): array
    {
        $fees  = $this->feeRepo->findBySource(self::SOURCE);
        $lines = [];

        foreach ($fees as $fee) {
            if ($fee->getPlacement() === 'main_line') {
                foreach ($context->cartItems as ['product' => $product, 'qty' => $qty]) {
                    if ($qty <= 0) {
                        continue;
                    }
                    $perUnit = $this->productFeeRepo->getValueForProduct($product, $fee) ?? $fee->getDefaultValue();
                    $amount = round($qty * $perUnit, 2);
                    if ($amount > 0) {
                        $label = sprintf('%s for %s (%s)', $fee->getName(), $product->getName(), $product->getSku());
                        $lines[] = new FeeLine($fee->getId(), $fee->getSlug(), $label, $fee->getTaxClass(), $amount, $fee->getPlacement());
                    }
                }
            } else {
                $total = 0.0;
                foreach ($context->cartItems as ['product' => $product, 'qty' => $qty]) {
                    if ($qty <= 0) {
                        continue;
                    }
                    $perUnit = $this->productFeeRepo->getValueForProduct($product, $fee) ?? $fee->getDefaultValue();
                    $total += $qty * $perUnit;
                }
                if ($total > 0) {
                    $lines[] = new FeeLine($fee->getId(), $fee->getSlug(), $fee->getName(), $fee->getTaxClass(), round($total, 2), $fee->getPlacement());
                }
            }
        }

        return $lines;
    }

    public function renderProductFields(ProductCore $product): string
    {
        $fees = $this->feeRepo->findBySource(self::SOURCE);
        if (empty($fees)) {
            return '';
        }

        $html = '';
        foreach ($fees as $fee) {
            $rawValue = $this->productFeeRepo->getValueForProduct($product, $fee);
            $value    = $rawValue !== null ? number_format($rawValue, 2, '.', '') : '';
            $label = htmlspecialchars($fee->getName(), ENT_QUOTES);
            $default = number_format($fee->getDefaultValue(), 2);
            $slug  = htmlspecialchars($fee->getSlug(), ENT_QUOTES);
            $html .= <<<HTML
                <label>
                    {$label} (default: \${$default})
                    <input type="number" step="0.01" min="0" name="fee[{$slug}]" value="{$value}">
                </label>
                HTML;
        }

        return $html;
    }

    public function saveProductFields(ProductCore $product, Request $request): void
    {
        $fees     = $this->feeRepo->findBySource(self::SOURCE);
        $feeInput = $request->request->all('fee');

        foreach ($fees as $fee) {
            $rawValue = $feeInput[$fee->getSlug()] ?? null;
            if ($rawValue !== null && $rawValue !== '') {
                $this->productFeeRepo->setValue($product, $fee, (float) $rawValue);
            } else {
                $this->productFeeRepo->deleteValue($product, $fee);
            }
        }
    }
}
