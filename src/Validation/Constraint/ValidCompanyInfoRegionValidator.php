<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the rule ConfigController::companyInformation() used to run by hand. */
final class ValidCompanyInfoRegionValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCompanyInfoRegion) {
            throw new UnexpectedTypeException($constraint, ValidCompanyInfoRegion::class);
        }

        if (!$value instanceof \ArrayAccess) {
            throw new UnexpectedValueException($value, \ArrayAccess::class);
        }

        $region = $constraint->region;

        // Blank stays allowed — the invoice omits the line entirely rather than printing a
        // placeholder. Anything non-blank has to resolve to a real code: province_name() falls
        // back to echoing whatever it was given, so an unvalidated "B.C." would print verbatim
        // on invoices while every buyer address on the same page printed a full province name.
        $rawCountry = (string) ($value['company_country'] ?? '');
        $country = $rawCountry === '' ? '' : $region->normalizeCountry($rawCountry);

        if ($country === null) {
            $this->context->buildViolation('That country is not one of the configured countries.')->atPath('company_country')->addViolation();

            return;
        }
        $value['company_country'] = $country;

        $rawProvince = (string) ($value['company_state'] ?? '');
        $province = ($rawProvince === '' || $country === '') ? '' : $region->normalizeProvince($country, $rawProvince);

        if ($province === null) {
            $this->context->buildViolation('That state/province is not valid for the selected country.')->atPath('company_state')->addViolation();

            return;
        }
        $value['company_state'] = $province;
    }
}
