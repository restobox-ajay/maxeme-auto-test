<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Line-for-line port of the four checks UserController::validateUserRequest() used to run by hand.
 *
 * The three role labels compared against below ('Admin', 'Super Admin', 'Tech Support') mirror
 * UserController's own ROLE_* constants; they are duplicated here rather than shared because those
 * constants are private to the controller and used throughout it for unrelated display and
 * permission logic well outside this validator's scope.
 */
final class ValidUserRequestValidator extends ConstraintValidator
{
    private const ROLE_ADMIN = 'Admin';
    private const ROLE_SUPERADMIN = 'Super Admin';
    private const ROLE_TECH_SUPPORT = 'Tech Support';

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidUserRequest) {
            throw new UnexpectedTypeException($constraint, ValidUserRequest::class);
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $this->validateEmail($value, $constraint);
        $this->validateRole($constraint);
        $this->validateStaffPermissions($constraint);
        $this->validateCompany($constraint);
    }

    private function validateEmail(string $email, ValidUserRequest $constraint): void
    {
        if (!$constraint->validateEmail) {
            return;
        }

        if ($email === '') {
            $this->context->buildViolation('User email is required.')->atPath('email')->addViolation();
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->context->buildViolation('Email must be a valid email address.')->atPath('email')->addViolation();
        }

        if ($email === '') {
            return;
        }

        $existingUser = $constraint->existingUser;
        if ($constraint->normalizedType === 'customer') {
            $existingCustomer = $constraint->entityManager->getRepository(CustomerUser::class)->findOneBy(['email' => $email]);
            if ($existingCustomer instanceof CustomerUser && (!($existingUser instanceof CustomerUser) || $existingCustomer->getId() !== $existingUser->getId())) {
                $this->context->buildViolation(sprintf('Email "%s" is already used by a customer user.', $email))->atPath('email')->addViolation();
            }
        } else {
            $existingAdmin = $constraint->entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
            if ($existingAdmin instanceof AdminUser && (!($existingUser instanceof AdminUser) || $existingAdmin->getId() !== $existingUser->getId())) {
                $this->context->buildViolation(sprintf('Email "%s" is already used by an admin user.', $email))->atPath('email')->addViolation();
            }
        }
    }

    private function validateRole(ValidUserRequest $constraint): void
    {
        if (!in_array($constraint->role, $constraint->allowedRoles, true)) {
            $this->context->buildViolation('Role is invalid.')->atPath('role')->addViolation();
        }
    }

    private function validateStaffPermissions(ValidUserRequest $constraint): void
    {
        $existingUser = $constraint->existingUser;
        $appliesToStaff = ($constraint->normalizedType === 'admin' || $existingUser instanceof AdminUser) && !($existingUser instanceof CustomerUser);
        if (!$appliesToStaff) {
            return;
        }

        $actor = $constraint->actor;
        if (!$actor instanceof AdminUser) {
            $this->context->buildViolation('Only admin staff can manage staff users.')->addViolation();

            return;
        }

        $actorRole = $constraint->actorRoleLabel;
        if (!in_array($actorRole, [self::ROLE_SUPERADMIN, self::ROLE_ADMIN, self::ROLE_TECH_SUPPORT], true)) {
            $this->context->buildViolation('You are not allowed to manage staff users.')->addViolation();
        } elseif (
            $actorRole === self::ROLE_ADMIN
            && $existingUser instanceof AdminUser
            && $constraint->existingUserRoleLabel === self::ROLE_SUPERADMIN
        ) {
            $this->context->buildViolation('Admins cannot edit super admin users.')->addViolation();
        } elseif (
            $actorRole === self::ROLE_ADMIN
            && $constraint->role === self::ROLE_SUPERADMIN
        ) {
            $this->context->buildViolation('Admins cannot assign the super admin role.')->addViolation();
        } elseif (
            $actorRole === self::ROLE_ADMIN
            && $existingUser instanceof AdminUser
            && $existingUser->getId() === $actor->getId()
            && $constraint->role === self::ROLE_SUPERADMIN
        ) {
            $this->context->buildViolation('Admins cannot upgrade themselves to super admin.')->addViolation();
        }
    }

    private function validateCompany(ValidUserRequest $constraint): void
    {
        $companyId = $constraint->companyId ?? '';

        if ($constraint->normalizedType === 'customer' && ($companyId === '' || $companyId === '0')) {
            $this->context->buildViolation('Please choose a customer.')->atPath('company')->addViolation();
        } elseif ($companyId !== '' && $companyId !== '0') {
            if (!ctype_digit($companyId) || (int) $companyId <= 0) {
                $this->context->buildViolation('Please choose a valid company.')->atPath('company')->addViolation();
            } elseif (!$constraint->entityManager->find(Company::class, (int) $companyId) instanceof Company) {
                $this->context->buildViolation('Please choose a valid company.')->atPath('company')->addViolation();
            }
        }
    }
}
