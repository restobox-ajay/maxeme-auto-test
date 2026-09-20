<?php

declare(strict_types=1);

namespace App\Contract\Shipping;

use App\Entity\AbstractSalesDocument;

/**
 * A shipping calculator is handed the document being shipped, not a flattened copy of five of its
 * fields (issue #165 step 9).
 *
 * ShippingContext carried province, cart items, highest tax class, company id and shipping address
 * id, and nothing else — so a rule that needed the subtotal, the coupon codes or the buyer's address
 * book could not have them without core adding a parameter and all seven implementations changing
 * with it. The document answers those five questions and every other one, so it is what gets passed.
 *
 * The document may be a Cart, a SalesOrder or an Estimate, and a calculator must not be able to tell
 * which: that is the claim the issue makes. It may also be transient — the admin order form's
 * preview endpoints and the customer cart/checkout pages each build one that is never persisted — so
 * read it as a description of what is being shipped, not as a saved record.
 *
 * getCompany() can be null: a guest cart has no buyer, where `?int $companyId` made that obvious.
 */
interface ShippingOptionInterface
{
    public function supports(AbstractSalesDocument $document): bool;

    /** @return ShippingOption[] */
    public function getOptions(AbstractSalesDocument $document): array;
}
