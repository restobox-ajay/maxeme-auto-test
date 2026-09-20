<?php

declare(strict_types=1);

namespace App\Service\Pricing;

use App\Entity\Company;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;

/**
 * Pricing bound to one (company, region) pair — everything below the price list, for as many
 * products as the caller wants.
 *
 * The scope exists because the price list is a property of the buyer and the region, not of the
 * product: a document has exactly one. Resolving it here, once, is what stops a 20-line cart from
 * running the same company/region lookup 40 times, which is what happened while each pricing
 * method resolved it for itself.
 *
 * A null company is a guest, not an error — guests price against a region's guestPriceList.
 */
final class CustomerPricingScope
{
    /**
     * Applied when a price list resolves but carries no rule for the product: the customer is on a
     * price group, so they get the group's implied discount off the base price rather than list.
     */
    private const IMPLIED_PRICE_GROUP_DISCOUNT_PERCENT = 10.0;

    private const RULE_NO_PRICE = 'No Price';
    private const RULE_HIDE = 'Hide';

    private bool $priceListResolved = false;
    private ?PriceList $priceList = null;

    /** @internal built by CustomerPricingResolver::for() */
    public function __construct(
        private readonly CustomerPricingResolver $resolver,
        private readonly ?Company $company,
        private readonly ?string $regionName,
    ) {}

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function getRegionName(): ?string
    {
        return $this->regionName;
    }

    /**
     * The price list this buyer pays against, or null when none resolves — which happens both when
     * the buyer has no active row for the region and when no region was given and more than one
     * candidate would be ambiguous. Callers treat "ambiguous" and "none" alike, so they are not
     * separated here either.
     *
     * Memoized for the life of the scope, including the null answer: a document is priced against
     * one price list, and re-asking mid-render could only produce inconsistency, never freshness.
     */
    public function priceList(): ?PriceList
    {
        if (!$this->priceListResolved) {
            $this->priceList = $this->company instanceof Company
                ? $this->resolver->priceListForCompanyRegion($this->company, $this->regionName)
                : $this->resolver->priceListForGuestRegion($this->regionName);
            $this->priceListResolved = true;
        }

        return $this->priceList;
    }

    /** The rule row for this product on the resolved price list, if any. */
    public function pricingFor(ProductCore $product): ?ProductPricing
    {
        $priceList = $this->priceList();
        if (!$priceList instanceof PriceList) {
            return null;
        }

        return $this->resolver->findPricing($product, $priceList);
    }

    /** True when the price list hides this product outright — it should not appear at all. */
    public function isHidden(ProductCore $product): bool
    {
        return self::ruleType($this->pricingFor($product)) === self::RULE_HIDE;
    }

    /** True when the price list carries a deliberate "No Price" rule for this product. */
    public function isNoPrice(ProductCore $product): bool
    {
        return self::ruleType($this->pricingFor($product)) === self::RULE_NO_PRICE;
    }

    /**
     * The rule ladder, ordered so a more specific instruction beats a more general one: an explicit
     * "No Price"; a flat Number; a discount off the product's base price; the price list row's own
     * stored price; the implied price-group discount; finally the bare base price.
     */
    public function priceFor(ProductCore $product): CustomerPrice
    {
        $pricing = $this->pricingFor($product);
        if (self::ruleType($pricing) === self::RULE_NO_PRICE) {
            return CustomerPrice::noPrice();
        }

        $basePrice = self::basePriceAmount($product);
        $baseNumber = $basePrice !== null && is_numeric($basePrice) ? (float) $basePrice : null;

        if ($pricing instanceof ProductPricing) {
            $type = self::ruleType($pricing);
            $value = $pricing->getRuleValue();
            $valueNumber = $value !== null && is_numeric($value) ? (float) $value : null;

            if ($type === 'Number' && $valueNumber !== null) {
                return self::pricedAt($valueNumber);
            }

            if ($baseNumber !== null && $valueNumber !== null) {
                if ($type === 'Discount%') {
                    return self::pricedAt($baseNumber * (1 - ($valueNumber / 100)));
                }

                if ($type === 'Discount$') {
                    return self::pricedAt($baseNumber - $valueNumber);
                }
            }

            if (is_numeric($pricing->getPrice())) {
                return self::pricedAt((float) $pricing->getPrice());
            }
        }

        if ($this->priceList() instanceof PriceList && $baseNumber !== null) {
            return self::pricedAt($baseNumber * (1 - (self::IMPLIED_PRICE_GROUP_DISCOUNT_PERCENT / 100)));
        }

        if ($baseNumber !== null) {
            return self::pricedAt($baseNumber);
        }

        return CustomerPrice::unpriced();
    }

    /**
     * A product this buyer may actually put in a cart, by SKU: active and visible, not private to
     * some other company, and not hidden by their own price list. $visibleTo is passed separately
     * from the scope's own company on purpose — catalog visibility keys off the logged-in user's
     * company, while pricing keys off the company the pricing lookup resolved, and the two can
     * disagree (see CustomerPricingResolver::companyForPricing()).
     */
    public function findPurchasableProduct(?Company $visibleTo, string $sku): ?ProductCore
    {
        $product = $this->resolver->findVisibleProductBySku($visibleTo, $sku);
        if (!$product instanceof ProductCore) {
            return null;
        }

        return $this->isHidden($product) ? null : $product;
    }

    private static function ruleType(?ProductPricing $pricing): string
    {
        return $pricing instanceof ProductPricing ? trim((string) ($pricing->getRuleType() ?? '')) : '';
    }

    /**
     * Formats an already-decided amount; it does not correct one. The sign is meaningful and is
     * settled upstream at the admin write path (#458), so a negative arriving here is a deliberate
     * entry rather than a data error, and rewriting it to zero would make the storefront disagree
     * with the price the admin grid shows for the same product. Two decimals, nothing else.
     */
    private static function pricedAt(float $amount): CustomerPrice
    {
        return CustomerPrice::priced(number_format($amount, 2, '.', ''));
    }

    /** The product's own price, before any price list has a say. */
    private static function basePriceAmount(ProductCore $product): ?string
    {
        $defaultPrice = trim((string) ($product->getDefaultPrice() ?? ''));
        if ($defaultPrice !== '' && is_numeric($defaultPrice)) {
            return $defaultPrice;
        }

        $originalPrice = trim((string) ($product->getOriginalPrice() ?? ''));
        if ($originalPrice !== '' && is_numeric($originalPrice)) {
            return $originalPrice;
        }

        return null;
    }
}
