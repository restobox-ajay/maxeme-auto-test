<?php

declare(strict_types=1);

namespace App\Contract\Fee;

use App\Entity\AbstractSalesDocument;
use App\Service\RegionSeedData;

use App\Entity\ProductCore;

final class FeeContext
{
    public readonly string $province;

    /**
     * @param array<int, array{product: ProductCore, qty: int}> $cartItems
     */
    public function __construct(
        string $province,
        public readonly array $cartItems,
        public readonly ?string $paymentMethod = null,
        public readonly ?int $companyId = null,
        /**
         * The document's line subtotal, before shipping, fees and tax.
         *
         * Fee calculators that price off the order's value rather than its contents need this —
         * cartItems carries products and quantities, not the prices actually being charged, which
         * differ by region price list and admin override. Defaults to 0.0 so the calculators that
         * only count items are unaffected.
         */
        public readonly float $subtotal = 0.0,
        /**
         * Coupon codes applied to this document, uppercased, in the order they were applied.
         *
         * Read only by CouponFeeCalculator, which turns each into its own negative fee line. They
         * ride on the context rather than being looked up because coupons are per-document state,
         * and the resolver has no document — same reason $subtotal is here.
         *
         * @var list<string>
         */
        public readonly array $couponCodes = [],
    ) {
        $p = trim($province);
        // One shared resolver instead of a copy of the province map in each context. Addresses now
        // store codes, so this is normally a pass-through; it still resolves a legacy display name so
        // an unconverted caller cannot silently stop matching a calculator and produce a $0-tax order.
        $this->province = RegionSeedData::resolveProvinceAnyCountry($p);
    }

    /**
     * Everything a fee calculator is given, read off the document that is being priced.
     *
     * Sixteen call sites used to assemble these five arguments by hand, each stripping price data
     * that was in scope in the same loop, and each free to disagree with the next about which rows
     * count. There is one answer now, and it is the document's.
     *
     * The subtotal is the header figure, not a re-sum of the rows: a document states what it is
     * worth, and a caller that has just rebuilt its lines writes that figure before asking for fees.
     *
     * Fee calculators still take this context. Shipping's went in step 9 and the calculators there
     * take the document; doing the same to fees is separate work, deliberately deferred until the
     * shape had been proved on one domain first.
     */
    public static function fromDocument(AbstractSalesDocument $document, ?string $paymentMethod = null): self
    {
        return new self(
            $document->getProvince(),
            $document->getCartItems(pricedOnly: true),
            $paymentMethod,
            $document->getCompany()?->getId(),
            (float) $document->getSubtotal(),
            $document->getCouponCodes(),
        );
    }
}
