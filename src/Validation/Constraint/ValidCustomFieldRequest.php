<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Repository\CustomFieldDefinitionRepository;
use Symfony\Component\Validator\Constraint;

/**
 * Issue #310's port of CustomFieldController::validateRequest(), following ValidUserRequest's #309
 * pattern: the validated value is the submitted label, with the field/object type choices, slug,
 * and the create-vs-update distinction carried as constraint options since none of them can be
 * derived from the label alone — object type and slug are only ever set at create time, since
 * update() never lets either change.
 */
#[\Attribute]
final class ValidCustomFieldRequest extends Constraint
{
    public function __construct(
        public readonly string $fieldType,
        public readonly array $fieldTypes,
        public readonly ?string $objectType,
        public readonly array $objectTypes,
        public readonly ?string $slug,
        public readonly CustomFieldDefinitionRepository $definitionRepo,
        public readonly bool $isCreate,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
