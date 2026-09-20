<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\AppSetting;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the checks ConfigController::handleSettingForm() used to run by hand. */
final class ValidAppSettingRequestValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidAppSettingRequest) {
            throw new UnexpectedTypeException($constraint, ValidAppSettingRequest::class);
        }

        if (!$value instanceof \ArrayAccess) {
            throw new UnexpectedValueException($value, \ArrayAccess::class);
        }

        $name = (string) ($value['name'] ?? '');
        $key = (string) ($value['key'] ?? '');

        if ($name === '' || $key === '') {
            $this->context->buildViolation('Setting name is required.')->addViolation();

            return;
        }

        $existing = $constraint->entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key]);
        if ($existing instanceof AppSetting && ($constraint->currentSettingId === null || $existing->getId() !== $constraint->currentSettingId)) {
            $this->context->buildViolation(sprintf('Setting key "%s" is already in use.', $key))->atPath('key')->addViolation();
        }
    }
}
