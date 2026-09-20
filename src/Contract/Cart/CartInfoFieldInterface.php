<?php

declare(strict_types=1);

namespace App\Contract\Cart;

use App\Entity\Company;

/**
 * Lets a bundle contribute label/value rows to the "Your Profile" card on the
 * customer cart page (e.g. a registration number and whether it waives a fee)
 * without the cart controller/template hardcoding that bundle's custom-field
 * slug. Mirrors FeeCalculatorInterface's shape: tagged app.cart_info_field,
 * resolved by CartInfoFieldResolver.
 */
interface CartInfoFieldInterface
{
    /** @return CartInfoField[] */
    public function getFields(Company $company): array;
}
