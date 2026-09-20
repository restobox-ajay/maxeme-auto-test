<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\Company;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the rule CompanyController::validateCompany() used to run by hand. */
final class ValidCompanyValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCompany) {
            throw new UnexpectedTypeException($constraint, ValidCompany::class);
        }

        if ($value === null) {
            return;
        }

        if (!$value instanceof Company) {
            throw new UnexpectedValueException($value, Company::class);
        }

        if (trim($value->getName()) === '') {
            $this->context->buildViolation('Company name is required.')
                ->atPath('name')->addViolation();
        }

        $email = $value->getPrimaryEmail();
        if ($email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->context->buildViolation('Primary Email must be a valid email address.')
                ->atPath('primaryEmail')->addViolation();
        }

        // No uniqueness check on the External ID: it is an externally-assigned code, so two companies
        // sharing one is the customer's business, not a validation error for us to raise.
    }
}
