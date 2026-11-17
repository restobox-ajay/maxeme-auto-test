<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The one reader of the `checkout_coupons` setting (a JSON list of coupons), shared by checkout,
 * the order payment page and FeeCouponBundle's fee line, so all three agree on which coupons
 * exist and what their minimum subtotal is.
 *
 * Each row: {"code": "SAVE10", "type": "percent"|"fixed", "value": 10, "minSubtotal": 100,
 * "label": "…"}. The minimum is read from `minSubtotal` or the older `min_subtotal`: the customer
 * controllers used to read only the snake_case key and the fee bundle only the camelCase one, so
 * whichever the stored JSON used, one side ignored the minimum. Malformed rows are dropped.
 */
final class CheckoutCoupons
{
    /**
     * @return list<array{code: string, type: string, value: float, minSubtotal: float, label: string}>
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
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

            $code = strtoupper(trim(self::scalar($row['code'] ?? '')));
            $type = strtolower(trim(self::scalar($row['type'] ?? '')));
            $value = (float) self::scalar($row['value'] ?? 0);
            if ($code === '' || !in_array($type, ['percent', 'fixed'], true) || $value <= 0) {
                continue;
            }

            $coupons[] = [
                'code' => $code,
                'type' => $type,
                'value' => $value,
                'minSubtotal' => max(0.0, (float) self::scalar($row['minSubtotal'] ?? $row['min_subtotal'] ?? 0)),
                'label' => trim(self::scalar($row['label'] ?? '')),
            ];
        }

        return $coupons;
    }

    /** A nested array or object in the JSON counts as empty rather than throwing on the cast. */
    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
