<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\CompanyAddress;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Line-for-line port of the rule CompanyController::validateAddress() used to run by hand — also
 * NORMALISES country/province onto the address, so both admin address paths store codes.
 * Validation is server-side because the <select> is only advice — and an unrecognised province
 * matches no tax calculator, which silently invoices the order at $0.
 */
final class ValidCompanyAddressValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCompanyAddress) {
            throw new UnexpectedTypeException($constraint, ValidCompanyAddress::class);
        }

        if ($value === null) {
            return;
        }

        if (!$value instanceof CompanyAddress) {
            throw new UnexpectedValueException($value, CompanyAddress::class);
        }

        $region = $constraint->region;
        if ($region !== null) {
            $rawCountry = trim((string) $value->getCountry());
            $countryCode = $rawCountry === '' ? 'CA' : $region->normalizeCountry($rawCountry);

            if ($countryCode === null || !$region->isValidCountry($countryCode)) {
                $this->context->buildViolation('Please choose a country from the list.')
                    ->atPath('country')->addViolation();
            } else {
                $value->setCountry($countryCode);

                $rawProvince = trim((string) $value->getProvince());
                if ($rawProvince !== '') {
                    $provinceCode = $region->normalizeProvince($countryCode, $rawProvince);
                    if ($provinceCode === null || !$region->isValidProvince($countryCode, $provinceCode)) {
                        $this->context->buildViolation('Please choose a province or state from the list.')
                            ->atPath('province')->addViolation();
                    } else {
                        $value->setProvince($provinceCode);
                    }
                }
            }
        }

        if (trim((string) $value->getAddressLine1()) === '') {
            $this->context->buildViolation('Address Line 1 is required.')
                ->atPath('addressLine1')->addViolation();
        }
        if (trim((string) $value->getCity()) === '') {
            $this->context->buildViolation('City is required.')
                ->atPath('city')->addViolation();
        }
        if (trim((string) $value->getProvince()) === '') {
            $this->context->buildViolation('Province is required.')
                ->atPath('province')->addViolation();
        }
        if (trim((string) $value->getCountry()) === '') {
            $this->context->buildViolation('Country is required.')
                ->atPath('country')->addViolation();
        }
        if (trim((string) $value->getPostalCode()) === '') {
            $this->context->buildViolation('Postal Code is required.')
                ->atPath('postalCode')->addViolation();
        }

        foreach ([
            'Email' => $value->getEmailPrimary(),
            'Secondary Email' => $value->getEmailSecondary(),
        ] as $label => $email) {
            if ($email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->context->buildViolation(sprintf('%s must be a valid email address.', $label))
                    ->addViolation();
            }
        }
    }
}
