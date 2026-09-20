<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Issue #310's port of ConfigController::handleFulfillmentRegionForm()'s required name and
 * name-uniqueness checks. The validated value is the submitted name; the entity manager and the
 * region being edited (null on create) are constraint options, since the uniqueness check must
 * exclude the row being edited but not any other.
 */
#[\Attribute]
final class ValidFulfillmentRegionRequest extends Constraint
{
    public function __construct(
        public readonly EntityManagerInterface $entityManager,
        public readonly ?int $currentRegionId,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
