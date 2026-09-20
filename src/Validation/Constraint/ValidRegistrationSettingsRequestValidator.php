<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the rule ConfigController::registrationSettings() used to run by hand. */
final class ValidRegistrationSettingsRequestValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidRegistrationSettingsRequest) {
            throw new UnexpectedTypeException($constraint, ValidRegistrationSettingsRequest::class);
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if ($value !== 'auto') {
            return;
        }

        $priceList = $constraint->defaultPriceListId !== ''
            ? $constraint->entityManager->find(PriceList::class, (int) $constraint->defaultPriceListId)
            : null;
        $region = $constraint->defaultRegionId !== ''
            ? $constraint->entityManager->find(FulfillmentRegion::class, (int) $constraint->defaultRegionId)
            : null;

        if (!$priceList instanceof PriceList || !$region instanceof FulfillmentRegion) {
            $this->context->buildViolation('Choose a default price list and default fulfillment region before enabling auto-approve.')->addViolation();
        }
    }
}
