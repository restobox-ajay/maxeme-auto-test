<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the checks ProductController::validateProductRequest() used to run by hand. */
final class ValidProductRequestValidator extends ConstraintValidator
{
    private const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const MAX_IMAGE_SIZE_BYTES = 5 * 1024 * 1024;

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidProductRequest) {
            throw new UnexpectedTypeException($constraint, ValidProductRequest::class);
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $hasBadMime = false;
        $hasTooLarge = false;
        foreach ($constraint->images as $imageFile) {
            if (!in_array($imageFile->getMimeType(), self::ALLOWED_IMAGE_MIMES, true)) {
                $hasBadMime = true;
            } elseif ($imageFile->getSize() > self::MAX_IMAGE_SIZE_BYTES) {
                $hasTooLarge = true;
            }
        }
        if ($hasBadMime) {
            $this->context->buildViolation('Each image must be a JPG, PNG, WebP, or GIF file.')->atPath('images')->addViolation();
        }
        if ($hasTooLarge) {
            $this->context->buildViolation('Each image must be smaller than 5 MB.')->atPath('images')->addViolation();
        }

        if ($value === '') {
            $this->context->buildViolation('Product name is required.')->atPath('name')->addViolation();
        }
        if ($constraint->sku === '') {
            $this->context->buildViolation('SKU is required.')->atPath('sku')->addViolation();
        }

        if ($constraint->sku !== '') {
            $match = $constraint->entityManager->getRepository(ProductCore::class)->findOneBy(['sku' => $constraint->sku]);
            if ($match instanceof ProductCore && ($constraint->existingProductId === null || $match->getId() !== $constraint->existingProductId)) {
                $this->context->buildViolation(sprintf('SKU "%s" is already in use.', $constraint->sku))->atPath('sku')->addViolation();
            }
        }

        $categoryId = $constraint->categoryId;
        if ($categoryId !== '' && (!ctype_digit($categoryId) || (int) $categoryId <= 0 || !$constraint->entityManager->find(ProductCategory::class, (int) $categoryId) instanceof ProductCategory)) {
            $this->context->buildViolation('Please choose a valid category.')->atPath('category_id')->addViolation();
        }

        foreach ([
            'weight' => ['Weight', $constraint->weight],
            'cost_price' => ['Cost Price', $constraint->costPrice],
            'original_price' => ['Original Price', $constraint->originalPrice],
            'sale_price' => ['Sale Price', $constraint->salePrice],
            'deposit' => ['Deposit', $constraint->deposit],
        ] as $field => [$label, $raw]) {
            if ($raw !== '' && !self::isNonNegativeNumeric($raw)) {
                $this->context->buildViolation(sprintf('%s must be a valid non-negative number.', $label))->atPath($field)->addViolation();
            }
        }
    }

    private static function isNonNegativeNumeric(string $value): bool
    {
        $normalized = str_replace(',', '', $value);
        if (!is_numeric($normalized)) {
            return false;
        }

        return (float) $normalized >= 0;
    }
}
