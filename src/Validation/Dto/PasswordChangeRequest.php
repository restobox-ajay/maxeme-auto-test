<?php

declare(strict_types=1);

namespace App\Validation\Dto;

use App\Validation\Constraint\ValidPasswordChange;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Shared shape for the admin and customer "change password" self-service forms (issue #311) — both
 * ask for the same three fields and enforce the same rules against them; only which user object the
 * current password is checked against differs, which is why $user is part of the value being
 * validated rather than a validator constructor argument.
 */
#[ValidPasswordChange]
final class PasswordChangeRequest
{
    public function __construct(
        public readonly PasswordAuthenticatedUserInterface&UserInterface $user,
        public readonly string $currentPassword,
        public readonly string $newPassword,
        public readonly string $confirmNewPassword,
    ) {
    }

    /**
     * Maps violations back to the form's snake_case field names, which is what both profile
     * templates key their inline {{ errors.current_password }}-style messages on.
     *
     * @return array<string, string>
     */
    public static function fieldErrors(ConstraintViolationListInterface $violations): array
    {
        $fieldNames = [
            'currentPassword' => 'current_password',
            'newPassword' => 'new_password',
            'confirmNewPassword' => 'confirm_new_password',
        ];

        $errors = [];
        foreach ($violations as $violation) {
            $propertyPath = $violation->getPropertyPath();
            $errors[$fieldNames[$propertyPath] ?? $propertyPath] = (string) $violation->getMessage();
        }

        return $errors;
    }
}
