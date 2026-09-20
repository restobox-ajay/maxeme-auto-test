<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraint;

/**
 * Issue #308's port of ProductController::validateProductRequest(), following
 * ValidCustomFieldRequest's #310 pattern: the validated value is the submitted name, with the
 * uploaded images, SKU, category, and the non-negative-numeric fields carried as constraint
 * options since none of them can be derived from the name alone.
 */
#[\Attribute]
final class ValidProductRequest extends Constraint
{
    /** @param list<UploadedFile> $images */
    public function __construct(
        public readonly string $sku,
        public readonly array $images,
        public readonly string $categoryId,
        public readonly EntityManagerInterface $entityManager,
        public readonly ?int $existingProductId,
        public readonly string $weight,
        public readonly string $costPrice,
        public readonly string $originalPrice,
        public readonly string $salePrice,
        public readonly string $deposit,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
