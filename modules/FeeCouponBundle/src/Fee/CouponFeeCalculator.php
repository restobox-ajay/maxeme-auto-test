<?php

declare(strict_types=1);

namespace FeeCouponBundle\Fee;

use App\Contract\Fee\FeeCalculatorInterface;
use App\Contract\Fee\FeeContext;
use App\Contract\Fee\FeeLine;
use App\Contract\Tax\TaxContext;
use App\Service\AppSettings;

/**
 * Turns an applied coupon code into a negative fee line.
 *
 * A coupon is a line on the document, not an adjustment folded into the totals, because that is
 * what tax and accounting need: the discount carries its own tax class, appears on the invoice as
 * its own row, and is visible as a figure rather than as the gap between a subtotal and a total.
 *
 * It is a fee calculator rather than a parallel "discount" stack so it inherits the whole of that
 * infrastructure — invoice and admin rendering, tax treatment, inclusion in the order total, and
 * the Bundle Management kill switch. It also inherits the property that matters most here: fee
 * lines are rebuilt from their calculators on every save, so the discount is re-derived from the
 * document's stored coupon code instead of being a stale figure an edit could silently orphan.
 */
final class CouponFeeCalculator implements FeeCalculatorInterface
{
    public const SOURCE = 'FeeCouponBundle';

    /** Slug prefix for the emitted line; the applied code is appended, e.g. "coupon-SAVE10". */
    public const SLUG_PREFIX = 'coupon-';

    public function __construct(private readonly AppSettings $appSettings) {}

    public function supports(FeeContext $context): bool
    {
        return $this->couponsEnabled() && $context->couponCodes !== [];
    }

    /**
     * One negative fee line per applied coupon.
     *
     * Coupons stack, and the combined discount is capped at the line subtotal — shipping, fees and
     * tax are excluded — so coupons can zero out the goods but never eat into the shipping the
     * carrier still has to be paid for, and can never drive the order total negative.
     *
     * The cap is consumed in the order the codes were applied: each coupon takes what is left of
     * the subtotal after the ones before it. So two 100% coupons discount 100% in total, not 200%,
     * and the second contributes nothing rather than the pair being silently rejected. Percentages
     * are always taken from the full subtotal rather than compounding on the running remainder,
     * which is what "10% off" is normally understood to mean.
     *
     * @return FeeLine[]
     */
    public function calculate(FeeContext $context): array
    {
        $subtotal = max(0.0, $context->subtotal);
        $remaining = $subtotal;
        $taxClass = $this->highestTaxClass($context);
        $lines = [];

        foreach ($context->couponCodes as $code) {
            $coupon = $this->findCoupon((string) $code, $subtotal);
            if ($coupon === null) {
                continue;
            }

            $raw = $coupon['type'] === 'percent'
                ? $subtotal * ($coupon['value'] / 100)
                : $coupon['value'];

            $discount = round(min(max($raw, 0.0), $remaining), 2);
            if ($discount <= 0.0) {
                continue;
            }

            $remaining = round($remaining - $discount, 2);
            $lines[] = new FeeLine(
                null,
                self::SLUG_PREFIX . $coupon['code'],
                'Coupon ' . $coupon['code'],
                $taxClass,
                -$discount,
                'main_line',
                FeeLine::TYPE_DISCOUNT,
                FeeLine::SOURCE_AUTO_CALC,
            );
        }

        return $lines;
    }

    /**
     * Which tax class the discount is taxed at.
     *
     * KNOWN SIMPLIFICATION: it takes the highest tax class present in the cart, so a discount
     * against a mixed-class order is reversed entirely at the highest rate. Strictly the discount
     * should be apportioned across the classes it actually reduces, in proportion to each class's
     * share of the subtotal, and the tax reversed per class. Correcting that needs the per-line
     * split, not just the highest class, so it is deliberately left for a follow-up rather than
     * approximated further here.
     */
    private function highestTaxClass(FeeContext $context): string
    {
        $codes = [];
        foreach ($context->cartItems as $item) {
            $product = $item['product'] ?? null;
            $codes[] = $product?->getSalesTaxCode();
        }

        return TaxContext::resolveHighestTaxClass($codes);
    }

    /**
     * The coupon matching this code, or null when there is none, it is malformed, or the order is
     * below its minimum subtotal.
     *
     * @return array{code: string, type: string, value: float, minSubtotal: float}|null
     */
    private function findCoupon(string $code, float $subtotal): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        foreach ($this->coupons() as $coupon) {
            if ($coupon['code'] !== $code) {
                continue;
            }

            return $subtotal < $coupon['minSubtotal'] ? null : $coupon;
        }

        return null;
    }

    /**
     * The configured coupons.
     *
     * Reads the same `checkout_coupons` JSON setting the customer controllers already parse, and
     * drops malformed rows the same way, so this bundle does not introduce a second notion of what
     * a valid coupon is. That setting has no admin editing screen — it is written directly to
     * app_setting — which is a gap this bundle documents rather than fixes.
     *
     * @return list<array{code: string, type: string, value: float, minSubtotal: float}>
     */
    private function coupons(): array
    {
        $raw = (string) ($this->appSettings->get('checkout_coupons', '') ?? '');
        if (trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $coupons = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $type = strtolower(trim((string) ($row['type'] ?? '')));
            $value = (float) ($row['value'] ?? 0);
            if ($code === '' || !in_array($type, ['percent', 'fixed'], true) || $value <= 0) {
                continue;
            }

            $coupons[] = [
                'code' => $code,
                'type' => $type,
                'value' => $value,
                'minSubtotal' => max(0.0, (float) ($row['minSubtotal'] ?? 0)),
            ];
        }

        return $coupons;
    }

    /** Admin switch (checkout_coupons_enabled) for whether coupon codes may be used at all. */
    private function couponsEnabled(): bool
    {
        return ($this->appSettings->get('checkout_coupons_enabled', 'Yes') ?? 'Yes') !== 'No';
    }
}
