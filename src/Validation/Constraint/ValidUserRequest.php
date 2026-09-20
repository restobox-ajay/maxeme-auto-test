<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\AdminUser;
use App\Entity\CustomerUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Issue #309's port of UserController::validateUserRequest(), following the ValidRedirect pattern
 * (#222/#305): one constraint covering several concerns (email, role, staff permissions, company
 * choice) that all used to live in a single hand-rolled method, exactly as ValidRedirect covers
 * both source-path and destination rules.
 *
 * The validated value is the submitted email; everything else validateUserRequest() used to close
 * over (the acting admin, the user being edited, the DB) is carried as constraint options instead,
 * since none of it can be derived from the email alone and there is no shared entity to attach the
 * rule to (a create() call has no user yet). Role labels for the actor and the existing user are
 * resolved by the caller (UserController::roleLabelForUser()) rather than recomputed here, so that
 * logic keeps exactly one home.
 */
#[\Attribute]
final class ValidUserRequest extends Constraint
{
    public function __construct(
        public readonly EntityManagerInterface $entityManager,
        public readonly string $normalizedType,
        public readonly AdminUser|CustomerUser|null $existingUser,
        public readonly string $role,
        public readonly array $allowedRoles,
        public readonly AdminUser|CustomerUser|null $actor,
        public readonly ?string $actorRoleLabel,
        public readonly ?string $existingUserRoleLabel,
        public readonly ?string $companyId,
        public readonly bool $validateEmail = true,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
