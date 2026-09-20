<?php

declare(strict_types=1);

namespace ProcurementBundle\Contract\Purchase;

/**
 * The seam a bundle implements to put a charge on a purchase document (#655, #658).
 *
 * Tag the service `app.purchase_fee_calculator` and
 * `ProcurementBundle\Purchase\PurchaseFeeCalculatorResolver` offers it every purchase document that
 * is saved. Exactly the shape of `App\Contract\Tax\TaxCalculatorInterface` and
 * `App\Contract\Fee\FeeCalculatorInterface` — `supports()` then `calculate()` — because that is the
 * house pattern and a third spelling of it would be a third thing to learn.
 *
 * ## Nothing implements it yet, deliberately
 *
 * The ruling is that purchase fees are new, separate logic and that bundles subscribe later. So it
 * ships as a seam with callers and no subscribers: the save asks the resolver, the resolver finds
 * nothing tagged, and the document's charges are whatever an admin typed. A speculative freight or
 * brokerage calculator would be guessing at rules nobody has stated.
 *
 * ## Why the contract is in ProcurementBundle rather than core
 *
 * Core has never heard of a vendor. Every procurement concept in this application — `Vendor`,
 * `PurchaseOrder`, `VendorBill`, `VendorPrice` — lives in this bundle, and `PurchaseFeeContext`
 * necessarily names a `Vendor`, so a core contract would drag the vendor concept into core to serve
 * a bundle. A bundle owning a seam that other bundles plug into is already the house pattern:
 * `TaxBundle` owns `TaxCalculatorResolver` and the `app.tax_calculator` tag, and two separate tax
 * bundles subscribe to it.
 *
 * If a non-procurement bundle ever needs to subscribe, moving this interface to `src/Contract/` is
 * a namespace change with no behaviour change. It is not there today because core is frozen and
 * because nothing needs it there.
 */
interface PurchaseFeeCalculatorInterface
{
    public function supports(PurchaseFeeContext $context): bool;

    /** @return PurchaseChargeLine[] */
    public function calculate(PurchaseFeeContext $context): array;
}
