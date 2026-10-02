<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Entity\RepairOrderCharge;
use App\Maxeme\Enum\RepairOrderChargeKind;
use App\Maxeme\Validation\Formatted;
use App\Maxeme\Validation\InputRule;
use Symfony\Component\Validator\Constraints as Assert;

/** One custom fee or discount on the repair order page (charges[n][id|kind|label|amount]). */
final class RepairOrderChargeData
{
    public ?string $id = null;

    #[Assert\NotBlank(message: 'Choose Fee or Discount.')]
    #[Assert\Choice(callback: [self::class, 'kinds'], message: 'Choose Fee or Discount.')]
    public ?string $kind = null;

    #[Assert\NotBlank(message: 'Enter what the fee or discount is for.')]
    #[Assert\Length(max: 120)]
    public ?string $label = null;

    #[Assert\NotBlank(message: 'Enter the amount.')]
    #[Formatted(InputRule::Money)]
    public ?string $amount = null;

    /**
     * @param mixed $rows the posted charges[] array
     *
     * @return list<self> the rows as typed, blank ones (no label, no amount) left out
     */
    public static function listFromRequest(mixed $rows): array
    {
        $charges = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = static function (string $key) use ($row): ?string {
                $text = is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';

                return $text !== '' ? $text : null;
            };

            $charge = new self();
            $charge->id = $value('id');
            $charge->kind = $value('kind');
            $charge->label = $value('label');
            // An amount is positive; Fee or Discount says which way it goes.
            $charge->amount = $value('amount') !== null ? ltrim((string) $value('amount'), '-') : null;
            if ($charge->label === null && $charge->amount === null) {
                continue;
            }
            $charges[] = $charge;
        }

        return $charges;
    }

    public static function fromEntity(RepairOrderCharge $charge): self
    {
        $data = new self();
        $data->id = (string) $charge->getId();
        $data->kind = $charge->getKind()->value;
        $data->label = $charge->getLabel();
        $data->amount = $charge->getAmount();

        return $data;
    }

    public function getKind(): ?RepairOrderChargeKind
    {
        return $this->kind !== null ? RepairOrderChargeKind::tryFrom($this->kind) : null;
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_map(static fn (RepairOrderChargeKind $kind): string => $kind->value, RepairOrderChargeKind::cases());
    }
}
