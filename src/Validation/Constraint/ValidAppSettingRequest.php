<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Issue #310's port of ConfigController::handleSettingForm()'s required name/key and
 * key-uniqueness checks. The validated value is an \ArrayObject wrapping the submitted name/key
 * pair; the entity manager and the setting being edited (null on create) are constraint options,
 * since the uniqueness check must exclude the row being edited but not any other.
 */
#[\Attribute]
final class ValidAppSettingRequest extends Constraint
{
    public function __construct(
        public readonly EntityManagerInterface $entityManager,
        public readonly ?int $currentSettingId,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
