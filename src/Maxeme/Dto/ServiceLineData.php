<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Entity\ServiceLine;
use App\Maxeme\Enum\ServiceLineType;
use App\Maxeme\Validation\Formatted;
use App\Maxeme\Validation\InputRule;
use Symfony\Component\Validator\Constraints as Assert;

/** One row of the service page's lines (lines[n][type|item_id|item_label|quantity|unit_price|charge_through|id]). */
final class ServiceLineData
{
    /** The line's id when it was saved before; blank for a new row. */
    public ?string $id = null;

    #[Assert\NotBlank(message: 'Choose the line type.')]
    #[Assert\Choice(callback: [self::class, 'types'], message: 'Choose the line type from the list.')]
    public ?string $type = null;

    /** A Labour, product or Govt Fee id, by type; none for a discount. */
    #[Assert\Regex('/^\d+$/', message: 'Choose an item from the list.')]
    public ?string $itemId = null;

    /** The search box's text, kept so a row with an error shows what was typed. */
    public ?string $itemLabel = null;

    #[Assert\NotBlank(message: 'Enter a quantity.')]
    #[Formatted(InputRule::Quantity)]
    #[Assert\Positive(message: 'The quantity must be more than 0.')]
    public ?string $quantity = null;

    #[Assert\NotBlank(message: 'Enter the price per unit.')]
    #[Formatted(InputRule::SignedMoney)]
    public ?string $unitPrice = null;

    public bool $chargeThrough = false;

    /**
     * @param mixed $rows the posted lines[] array
     *
     * @return list<self> the rows as typed, blank ones (no type, item or price) left out
     */
    public static function listFromRequest(mixed $rows): array
    {
        $lines = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = static function (string $key) use ($row): ?string {
                $text = is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';

                return $text !== '' ? $text : null;
            };

            $line = new self();
            $line->id = $value('id');
            $line->type = $value('type');
            $line->itemId = $value('item_id');
            $line->itemLabel = $value('item_label');
            $line->quantity = $value('quantity');
            $line->unitPrice = $value('unit_price');
            $line->chargeThrough = $value('charge_through') === '1';
            if ($line->type === null && $line->itemId === null && $line->unitPrice === null) {
                continue;
            }
            // A discount is one negative amount, however it was typed.
            if ($line->getType() === ServiceLineType::Discount) {
                $line->quantity = '1';
                $line->unitPrice = $line->unitPrice !== null ? '-' . ltrim($line->unitPrice, '-') : null;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    public static function fromEntity(ServiceLine $line): self
    {
        $data = new self();
        $data->id = (string) $line->getId();
        $data->type = $line->getType()->value;
        $data->itemId = $line->getItemId() !== null ? (string) $line->getItemId() : null;
        $data->itemLabel = $line->getType()->hasItem() ? $line->getItemLabel() : null;
        $data->quantity = $line->getQuantity();
        $data->unitPrice = $line->getUnitPrice();
        $data->chargeThrough = $line->isChargeThrough();

        return $data;
    }

    public function getType(): ?ServiceLineType
    {
        return $this->type !== null ? ServiceLineType::tryFrom($this->type) : null;
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_map(static fn (ServiceLineType $type): string => $type->value, ServiceLineType::cases());
    }
}
