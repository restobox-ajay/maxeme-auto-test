<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Enum\CategoryStatus;
use Symfony\Component\Validator\Constraints as Assert;

/** The service category form (wholesale's category form, plus Colour). The controller sets the parent. */
final class ServiceCategoryData extends FormData
{
    public const FIELDS = [
        'name' => 'name',
        'parent_id' => 'parentId',
        'colour' => 'colour',
        'status' => 'status',
    ];

    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(max: 160)]
    public ?string $name = null;

    #[Assert\Regex('/^\d+$/', message: 'Choose a parent category from the list.')]
    public ?string $parentId = null;

    /** "#1f77b4"; blank to use the parent category's colour. */
    #[Assert\Regex('/^#[0-9a-fA-F]{6}$/', message: 'Colour must look like #1f77b4.')]
    public ?string $colour = null;

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'statuses'])]
    public ?string $status = CategoryStatus::Visible->value;

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_map(static fn (CategoryStatus $status): string => $status->value, CategoryStatus::cases());
    }

    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return match ($property) {
            'name' => (string) $value,
            'status' => CategoryStatus::from((string) $value),
            default => $value,
        };
    }

    protected function managedElsewhere(): array
    {
        return ['parentId'];
    }
}
