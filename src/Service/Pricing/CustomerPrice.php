<?php

declare(strict_types=1);

namespace App\Service\Pricing;

/**
 * What a customer pays for one product, as a three-way answer rather than a nullable amount.
 *
 * The distinction that matters is NO_PRICE vs UNPRICED. "No Price" is a pricing rule an admin
 * deliberately set, and it routes a whole cart to an Estimate instead of an order (see
 * plan4-quotes-vs-orders.md section 2.1) — a business branch, not an absence. UNPRICED is the
 * opposite: nothing is configured, so there is simply no number to show. Collapsing both into
 * null (as the pricing code did while it lived on the controller) meant every caller that cared
 * about the branch had to re-resolve the pricing rule a second time just to tell them apart.
 */
final class CustomerPrice
{
    public const STATUS_PRICED = 'priced';
    public const STATUS_NO_PRICE = 'no_price';
    public const STATUS_UNPRICED = 'unpriced';

    /** @param ?string $amount decimal string with 2 places, non-null only when STATUS_PRICED */
    private function __construct(
        public readonly string $status,
        public readonly ?string $amount = null,
    ) {}

    public static function priced(string $amount): self
    {
        return new self(self::STATUS_PRICED, $amount);
    }

    /** The price list carries an explicit "No Price" rule for this product. */
    public static function noPrice(): self
    {
        return new self(self::STATUS_NO_PRICE);
    }

    /** No price list resolved, or one did but neither it nor the product carries a usable number. */
    public static function unpriced(): self
    {
        return new self(self::STATUS_UNPRICED);
    }

    public function isPriced(): bool
    {
        return $this->status === self::STATUS_PRICED;
    }

    public function isNoPrice(): bool
    {
        return $this->status === self::STATUS_NO_PRICE;
    }

    /** Amount as a float, for callers doing arithmetic; null whenever there is no number. */
    public function toFloat(): ?float
    {
        return $this->amount !== null ? (float) $this->amount : null;
    }
}
