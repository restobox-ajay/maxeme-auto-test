<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Validation\Money;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One row of the invoice builder's item table: a service (with the parts it uses) or a part, picked
 * from the catalogue (`id`) or typed in. `parts` rows are [id, name, quantity].
 */
final class InvoiceItemData
{
    public const TYPE_PART = 'Parts';
    public const TYPE_SERVICE = 'Services';

    #[Assert\Choice(choices: [self::TYPE_PART, self::TYPE_SERVICE])]
    public string $type = self::TYPE_SERVICE;

    public ?int $id = null;

    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Range(min: 1, max: 99, notInRangeMessage: 'Quantities go from {{ min }} to {{ max }}.')]
    public int $quantity = 1;

    #[Money]
    public ?string $price = null;

    /** @var list<array{id: ?int, name: ?string, quantity: int}> */
    #[Assert\All([new Assert\Collection(fields: [
        'id' => new Assert\Optional(),
        'name' => new Assert\Length(max: 255),
        'quantity' => new Assert\Range(min: 1, max: 99, notInRangeMessage: 'Quantities go from {{ min }} to {{ max }}.'),
    ])])]
    public array $parts = [];

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $item = new self();
        $item->type = ($row['type'] ?? '') === self::TYPE_PART ? self::TYPE_PART : self::TYPE_SERVICE;
        $item->id = self::id($row['id'] ?? null);
        $item->name = self::text($row['name'] ?? null);
        $item->quantity = (int) ($row['quantity'] ?? 1);
        $item->price = self::text($row['price'] ?? null);

        foreach (is_array($row['parts'] ?? null) ? $row['parts'] : [] as $part) {
            $id = self::id($part['id'] ?? null);
            if ($id !== null) {
                $item->parts[] = ['id' => $id, 'name' => self::text($part['name'] ?? null), 'quantity' => (int) ($part['quantity'] ?? 1)];
            }
        }

        return $item;
    }

    /** A row nobody filled in (the builder always shows at least one). */
    public function isEmpty(): bool
    {
        return $this->id === null && $this->name === null;
    }

    public function isService(): bool
    {
        return $this->type === self::TYPE_SERVICE;
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
