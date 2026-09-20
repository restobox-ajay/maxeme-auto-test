<?php

declare(strict_types=1);

namespace ProcurementBundle\Contract\Purchase;

use App\Entity\ProductCore;
use App\Service\RegionSeedData;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;

/**
 * Everything a purchase fee calculator is given (#655, #658).
 *
 * The buy-side twin of `App\Contract\Fee\FeeContext` in SHAPE only — a readonly value object built
 * from the document, handed to every registered calculator, carrying the few facts a charge could
 * plausibly be priced from. None of its code is shared and it names none of the sell side's
 * concepts: no payment method (we are not being paid), no company id (there is no customer), no
 * coupon codes (a vendor does not hand us a discount code).
 *
 * What a buy-side calculator actually needs:
 *
 *  - **the vendor** — freight terms, brokerage and fuel surcharges are per-supplier facts. The LIVE
 *    vendor, not the document's frozen `vendorName`, because a calculator prices now rather than
 *    reading history;
 *  - **the province** — where the goods land. The same figure the shared tax layer matches on;
 *  - **the currency** — a purchase document is read in the currency it was raised in and is never
 *    converted, so an amount has to know what it denominates;
 *  - **the line subtotal** — the header figure the caller has just written, for a charge that is a
 *    percentage of the document rather than of its contents;
 *  - **the lines** — {product, quantity} pairs, for a charge that counts goods. Rows with no
 *    product are left out; a calculator counting goods has nothing to count on them.
 *
 * ## No `fromDocument()` on a sales document, and that is the whole point
 *
 * `FeeContext::fromDocument()` takes an `AbstractSalesDocument`. That signature is why the sales fee
 * seam cannot serve a purchase document, and why reusing it would have meant either widening a core
 * contract around the buy side — ruled out — or pretending a bill is a sales document, which it is
 * not.
 *
 * The named constructors are per document type and additive: a sibling change adding
 * `fromPurchaseOrder()` beside `fromVendorBill()` is two factory methods on one class, not two
 * classes.
 */
final class PurchaseFeeContext
{
    public readonly string $province;

    /**
     * @param array<int, array{product: ProductCore, quantity: float}> $lines
     */
    public function __construct(
        public readonly Vendor $vendor,
        string $province,
        public readonly string $currency,
        public readonly float $subtotal,
        public readonly array $lines = [],
    ) {
        // The same shared resolver every other context in this application runs its province
        // through, so a calculator comparing 'BC' === 'BC' never has to think about whether it was
        // handed a code or a display name.
        $this->province = RegionSeedData::resolveProvinceAnyCountry(trim($province));
    }

    /**
     * Read off the bill being priced, so that no caller assembles these by hand.
     *
     * The subtotal is the header figure rather than a re-sum of the rows: a document states what it
     * is worth, and a save that has just rebuilt its lines writes that figure before asking for
     * charges.
     */
    public static function fromVendorBill(VendorBill $bill): self
    {
        $lines = [];
        foreach ($bill->getLines() as $line) {
            $product = $line->getProduct();
            if (!$product instanceof ProductCore) {
                continue;
            }

            $lines[] = ['product' => $product, 'quantity' => (float) $line->getQuantity()];
        }

        return new self(
            $bill->getVendor(),
            (string) ($bill->getTaxProvince() ?? ''),
            $bill->getCurrency(),
            (float) $bill->getSubtotal(),
            $lines,
        );
    }

    /**
     * The same, read off a purchase order.
     *
     * A purchase order states a quantity ORDERED where a bill states the quantity it is billing, so
     * the two factories differ in that one getter and in nothing else. The rule they share is the
     * one `FeeContext::fromDocument()` states: the subtotal is the header figure the save has just
     * written, not a re-sum of the rows.
     */
    public static function fromPurchaseOrder(PurchaseOrder $order): self
    {
        $lines = [];
        foreach ($order->getLines() as $line) {
            $product = $line->getProduct();
            if (!$product instanceof ProductCore) {
                continue;
            }

            $lines[] = ['product' => $product, 'quantity' => (float) $line->getQuantityOrdered()];
        }

        return new self(
            $order->getVendor(),
            $order->getTaxProvince() ?? '',
            $order->getCurrency(),
            (float) $order->getSubtotal(),
            $lines,
        );
    }
}
