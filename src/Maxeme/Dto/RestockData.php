<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Validation\Money;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Parts Inventory › Inventory (legacy cns_inventory_history_form): a stock change with its PO
 * number and prices. Not an entity form, so it does not extend FormData.
 */
final class RestockData
{
    #[Assert\Length(max: 255)]
    public ?string $poNumber = null;

    #[Money]
    public ?string $unitPrice = null;

    #[Money]
    public ?string $salePrice = null;

    /** Added to the stock; negative removes stock. */
    #[Assert\NotBlank(message: 'Enter the quantity to add (negative to remove).')]
    #[Assert\Regex(pattern: '/^-?\d{1,9}$/', message: 'Quantity must be a whole number.')]
    public ?string $quantity = '0';

    #[Assert\Length(max: 255)]
    public ?string $note = null;

    public static function fromRequest(Request $request): self
    {
        $data = new self();
        foreach (['poNumber' => 'po_number', 'unitPrice' => 'unit_price', 'salePrice' => 'sale_price', 'quantity' => 'quantity', 'note' => 'note'] as $property => $field) {
            $value = trim((string) $request->request->get($field, ''));
            $data->{$property} = $value !== '' ? $value : null;
        }

        return $data;
    }
}
