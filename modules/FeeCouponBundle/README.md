# FeeCouponBundle

**Type:** Fee · **Name:** Coupons · **Edit route:** none (no admin config screen)

## What it does

Turns each coupon code applied to a document into a **negative fee line**.

A coupon is a line rather than an adjustment folded into the totals because that is what tax and accounting need: the discount carries its own tax class, appears on the invoice as its own row, and is a figure in its own right rather than the gap between a subtotal and a total.

It is a Fee bundle rather than a parallel "discount" stack so it inherits all of that infrastructure — invoice, admin and customer rendering; per-line tax treatment; inclusion in the order total; and the Bundle Management kill switch. It also inherits the property that matters most: fee lines are rebuilt from their calculators on every save, so the discount is re-derived from the document's stored codes rather than being a stale figure an edit could orphan.

## Where the codes are stored

`AbstractSalesDocument::$couponCodes` (`coupon_codes`, a JSON list on `sales_order` and `estimate`). Only the codes are stored, never the amounts — those are the fee lines, recomputed each time, so they always reflect the subtotal they are actually taken from.

`FeeContext` carries `$couponCodes` and `$subtotal` for the same reason: coupons are per-document state and the resolver has no document, and `cartItems` gives products and quantities but not the prices actually being charged.

## Stacking and the cap

Multiple coupons may apply, each producing its own line. The **combined** discount is capped at the line subtotal — shipping, fees and tax are excluded — so coupons can zero out the goods but never eat into shipping the carrier still has to be paid for, and can never drive an order total negative.

The cap is consumed in the order the codes were applied: each coupon takes what is left of the subtotal after the ones before it. Two 100% coupons discount 100% in total, not 200%, and the second simply contributes nothing. Percentages are always taken from the full subtotal rather than compounding on the running remainder, which is what "10% off" is normally understood to mean.

## How it's configured

No admin screen. Coupons are read from the existing `checkout_coupons` app setting — a JSON array:

```json
[{"code": "SAVE10", "type": "percent", "value": 10, "minSubtotal": 100}]
```

`type` is `percent` or `fixed`; rows missing a code, with an unknown type, or with a value of zero or less are skipped. This bundle parses that setting exactly as the customer controllers already do, so it does not introduce a second notion of what a valid coupon is.

**That setting has no editing screen anywhere in admin** — it is written directly to the `app_setting` table by hand, and unparseable JSON silently yields no coupons at all rather than an error. This bundle documents that gap rather than fixing it; giving coupons real storage and a CRUD screen is separate work.

`checkout_coupons_enabled` (admin Config → Settings) remains the on/off switch and is honoured by `supports()`. The bundle-level Active/Inactive toggle in Bundle Management works independently, as for any Fee bundle.

## Known simplification: the discount's tax class

The discount line is assigned the **highest tax class present in the cart**, so a discount against a mixed-class order is reversed entirely at the highest rate.

Strictly, a discount should be apportioned across the classes it actually reduces, in proportion to each class's share of the subtotal, and the tax reversed per class. Doing that needs the per-line split rather than just the highest class, so it is deliberately left for a follow-up rather than approximated further. `CouponFeeCalculatorTest::testTaxClassIsTheHighestInTheCart` pins the current behaviour so a change is deliberate.

## What is not wired up yet

Nothing sets `couponCodes` on customer-facing flows. Customer checkout (`CheckoutController::checkoutTotals()`) and the order-payment page still apply their own coupon arithmetic, and setting the codes there **as well** would discount twice. Those flows have to move onto this bundle in one step that removes their bespoke discount maths at the same time.

Today the admin order paths read `$order->getCouponCodes()`, so codes present on an order are honoured and survive an admin save.

## External dependencies

None.
