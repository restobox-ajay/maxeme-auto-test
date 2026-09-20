<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Service\Region;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Line-for-line port of the checks Customer\AuthController::register() used to run by hand over
 * its posted fields, including the ship/bill country+province normalisation the controller used
 * to do inline — the normalised codes are written back onto $value so the controller persists
 * them, same as ValidCompanyAddressRequestValidator does for the single-address customer forms.
 */
final class ValidRegistrationRequestValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidRegistrationRequest) {
            throw new UnexpectedTypeException($constraint, ValidRegistrationRequest::class);
        }

        if (!$value instanceof \ArrayAccess) {
            throw new UnexpectedValueException($value, \ArrayAccess::class);
        }

        if ((string) ($value['company_name'] ?? '') === '') {
            $this->context->buildViolation('Registered company name is required.')->atPath('company_name')->addViolation();
        }

        $companyEmail = (string) ($value['company_email'] ?? '');
        if ($companyEmail === '') {
            $this->context->buildViolation('Company email is required.')->atPath('company_email')->addViolation();
        } elseif (filter_var($companyEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->context->buildViolation('Company email must be a valid email address.')->atPath('company_email')->addViolation();
        }

        if ((string) ($value['first_name'] ?? '') === '') {
            $this->context->buildViolation('First name is required.')->atPath('first_name')->addViolation();
        }

        if ((string) ($value['last_name'] ?? '') === '') {
            $this->context->buildViolation('Last name is required.')->atPath('last_name')->addViolation();
        }

        $email = (string) ($value['user_email'] ?? '');
        if ($email === '') {
            $this->context->buildViolation('User email is required.')->atPath('user_email')->addViolation();
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->context->buildViolation('User email must be a valid email address.')->atPath('user_email')->addViolation();
        }

        $userPhone = (string) ($value['user_phone'] ?? '');
        $companyPhone = (string) ($value['company_phone'] ?? '');
        if ($userPhone === '' && $companyPhone === '') {
            $this->context->buildViolation('Phone number is required.')->atPath('user_phone')->addViolation();
        }

        $password = (string) ($value['password'] ?? '');
        $confirmPassword = (string) ($value['confirm_password'] ?? '');
        if ($password === '' || $confirmPassword === '') {
            $this->context->buildViolation('Password and confirm password are required.')->atPath('password')->addViolation();
        } elseif ($password !== $confirmPassword) {
            $this->context->buildViolation('Passwords do not match.')->atPath('confirm_password')->addViolation();
        } elseif (strlen($password) < 8) {
            $this->context->buildViolation('Password must be at least 8 characters long.')->atPath('password')->addViolation();
        }

        if ((string) ($value['agree_terms'] ?? '') !== '1') {
            $this->context->buildViolation('Please accept the Privacy Policy and Terms & Conditions to continue.')->atPath('agree_terms')->addViolation();
        }

        if ((string) ($value['ship_address1'] ?? '') === '') {
            $this->context->buildViolation('Shipping address line 1 is required.')->atPath('ship_address1')->addViolation();
        }

        if ((string) ($value['ship_city'] ?? '') === '') {
            $this->context->buildViolation('Shipping city is required.')->atPath('ship_city')->addViolation();
        }

        $region = $constraint->region;

        // Normalised to codes and validated against geo_province, not merely non-empty: an
        // unrecognised province matches no tax calculator and the order silently invoices at $0.
        // Blank falls through to isValidCountry(''), which is false, and is rejected exactly like
        // an invalid one (#333) — the 'CA' default lives only in the rendered <select>.
        $shipCountry = $region->normalizeCountry((string) ($value['ship_country'] ?? '')) ?? (string) ($value['ship_country'] ?? '');
        if (!$region->isValidCountry($shipCountry)) {
            $this->context->buildViolation('Please choose a shipping country from the list.')->atPath('ship_country')->addViolation();
        } elseif ((string) ($value['ship_province'] ?? '') === '') {
            $this->context->buildViolation('Shipping province is required.')->atPath('ship_province')->addViolation();
        } else {
            $resolved = $region->normalizeProvince($shipCountry, (string) $value['ship_province']);
            if ($resolved === null || !$region->isValidProvince($shipCountry, $resolved)) {
                $this->context->buildViolation('Please choose a shipping province or state from the list.')->atPath('ship_province')->addViolation();
            } else {
                $value['ship_province'] = $resolved;
            }
        }
        $value['ship_country'] = $shipCountry;

        if ((string) ($value['ship_postal'] ?? '') === '') {
            $this->context->buildViolation('Shipping postal code is required.')->atPath('ship_postal')->addViolation();
        }

        $billSame = (bool) ($value['bill_same'] ?? false);
        if (!$billSame) {
            if ((string) ($value['bill_address1'] ?? '') === '') {
                $this->context->buildViolation('Billing address line 1 is required when billing address is not the same as shipping.')->atPath('bill_address1')->addViolation();
            }
            if ((string) ($value['bill_city'] ?? '') === '') {
                $this->context->buildViolation('Billing city is required when billing address is not the same as shipping.')->atPath('bill_city')->addViolation();
            }

            // #333: same silent 'CA' substitution as ship_country above, settled the same way — a
            // separate billing address that omits bill_country is a missing required field, not an
            // implicit Canadian one.
            $billingCountry = $region->normalizeCountry((string) ($value['bill_country'] ?? '')) ?? (string) ($value['bill_country'] ?? '');
            if (!$region->isValidCountry($billingCountry)) {
                $this->context->buildViolation('Please choose a billing country from the list.')->atPath('bill_country')->addViolation();
            } elseif ((string) ($value['bill_province'] ?? '') === '') {
                $this->context->buildViolation('Billing province is required when billing address is not the same as shipping.')->atPath('bill_province')->addViolation();
            } else {
                $resolvedBilling = $region->normalizeProvince($billingCountry, (string) $value['bill_province']);
                if ($resolvedBilling === null || !$region->isValidProvince($billingCountry, $resolvedBilling)) {
                    $this->context->buildViolation('Please choose a billing province or state from the list.')->atPath('bill_province')->addViolation();
                } else {
                    $value['bill_province'] = $resolvedBilling;
                }
            }
            $value['bill_country'] = $billingCountry;

            if ((string) ($value['bill_postal'] ?? '') === '') {
                $this->context->buildViolation('Billing postal code is required when billing address is not the same as shipping.')->atPath('bill_postal')->addViolation();
            }
        }
    }
}
