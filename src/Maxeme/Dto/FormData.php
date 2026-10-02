<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * A Maxeme form's fields, as the strings the form posts. Subclasses declare FIELDS (form field
 * name => property on both the DTO and the entity, in form order) plus their validation
 * constraints, and convert types in toEntityValue() where a property is not a string.
 *
 * Blank inputs become null, as the legacy forms stored them.
 */
abstract class FormData
{
    /** @var array<string, string> form field => property */
    public const FIELDS = [];

    public static function fromRequest(Request $request): static
    {
        return static::fromArray($request->request->all());
    }

    /** @param array<mixed> $values form field => posted value (a nested part of a bigger form) */
    public static function fromArray(array $values): static
    {
        $data = new static();
        foreach (static::FIELDS as $field => $property) {
            $value = is_scalar($values[$field] ?? null) ? trim((string) $values[$field]) : '';
            $data->{$property} = $value !== '' ? $value : null;
        }

        return $data;
    }

    public static function fromEntity(object $entity): static
    {
        $data = new static();
        foreach (static::FIELDS as $property) {
            $value = self::accessor()->getValue($entity, $property);
            $data->{$property} = match (true) {
                $value === null => null,
                $value instanceof \BackedEnum => (string) $value->value,
                default => (string) $value,
            };
        }

        return $data;
    }

    /** Writes every field onto $entity, except those listed in managedElsewhere(). */
    public function applyTo(object $entity): void
    {
        foreach (array_diff(static::FIELDS, $this->managedElsewhere()) as $property) {
            self::accessor()->setValue($entity, $property, $this->toEntityValue($property, $this->{$property}));
        }

        if (method_exists($entity, 'touch')) {
            $entity->touch();
        }
    }

    /** @return array<string, ?string> form field => value, for pre-filling an edit form */
    public function toFormValues(): array
    {
        $values = [];
        foreach (static::FIELDS as $field => $property) {
            $values[$field] = $this->{$property};
        }

        return $values;
    }

    /** The entity value for a (validated) form value. Override for non-string properties. */
    protected function toEntityValue(string $property, ?string $value): mixed
    {
        return $value;
    }

    /** @return list<string> properties the form shows but applyTo() must not write (a service applies them) */
    protected function managedElsewhere(): array
    {
        return [];
    }

    private static function accessor(): PropertyAccessorInterface
    {
        static $accessor;

        return $accessor ??= PropertyAccess::createPropertyAccessor();
    }
}
