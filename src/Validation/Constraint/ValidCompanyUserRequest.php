<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Issue #309's port of the validation Customer\CompanyUserController::create() and edit() used to
 * duplicate inline: name, email, and password rules, plus the email-uniqueness DB check the
 * original only ran once the other checks were clean. Carried as constraint options rather than a
 * validated entity because both actions build a $values array from raw request fields before any
 * CustomerUser is touched (edit() only writes them onto the entity after validation passes).
 *
 * create() and edit() differ in two ways this constraint parametrises rather than duplicating a
 * second validator for: create() always requires a password ($passwordRequired), where edit()
 * leaves an untouched account alone; and their uniqueness lookups use different repository methods
 * ($emailLookupInsensitive) — a pre-existing inconsistency this migration preserves rather than
 * silently changes.
 */
#[\Attribute]
final class ValidCompanyUserRequest extends Constraint
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $password,
        public readonly string $confirmPassword,
        public readonly bool $passwordRequired,
        public readonly EntityManagerInterface $entityManager,
        public readonly bool $emailLookupInsensitive,
        public readonly ?int $excludeUserId = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
