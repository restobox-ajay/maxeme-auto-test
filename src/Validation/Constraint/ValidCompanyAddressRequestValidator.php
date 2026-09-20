<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Service\Region;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Line-for-line port of the checks Customer\CompanyAddressController::create()/edit() and their
 * shared validateRegion() used to run by hand — also NORMALISES country/province onto $value, so
 * both customer address paths store codes, same as the admin side's ValidCompanyAddressValidator.
 */
final class ValidCompanyAddressRequestValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCompanyAddressRequest) {
            throw new UnexpectedTypeException($constraint, ValidCompanyAddressRequest::class);
        }

        if (!$value instanceof \ArrayAccess) {
            throw new UnexpectedValueException($value, \ArrayAccess::class);
        }

        if (trim((string) ($value['label'] ?? '')) === '') {
            $this->context->buildViolation('Address name is required.')->atPath('label')->addViolation();
        }
        if (trim((string) ($value['address1'] ?? '')) === '') {
            $this->context->buildViolation('Address line 1 is required.')->atPath('address1')->addViolation();
        }
        if (trim((string) ($value['city'] ?? '')) === '') {
            $this->context->buildViolation('City is required.')->atPath('city')->addViolation();
        }

        $this->validateRegion($value, $constraint->region);
    }

    private function validateRegion(\ArrayAccess $values, Region $region): void
    {
        // An omitted country means Canada — the default the form has always preselected, and what
        // the normalisation migration assumes for blank rows. Only a country that was *supplied* and
        // does not resolve is an error, so a client that never sends the field still validates its
        // province rather than failing on a field it did not touch.
        $rawCountry = trim((string) ($values['country'] ?? ''));
        $countryCode = $rawCountry === '' ? 'CA' : $region->normalizeCountry($rawCountry);
        if ($countryCode === null || !$region->isValidCountry($countryCode)) {
            $this->context->buildViolation('Please choose a country.')->atPath('country')->addViolation();

            return;
        }
        $values['country'] = $countryCode;

        $rawProvince = trim((string) ($values['province'] ?? ''));
        if ($rawProvince === '') {
            $this->context->buildViolation('Province is required.')->atPath('province')->addViolation();

            return;
        }

        $provinceCode = $region->normalizeProvince($countryCode, $rawProvince);
        if ($provinceCode === null || !$region->isValidProvince($countryCode, $provinceCode)) {
            $this->context->buildViolation('Please choose a province or state from the list.')->atPath('province')->addViolation();

            return;
        }
        $values['province'] = $provinceCode;
    }
}
