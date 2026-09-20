<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductCore;
use App\Entity\ProductImage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductImage>
 */
class ProductImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductImage::class);
    }

    /** @return list<ProductImage> */
    public function findByProduct(ProductCore $product): array
    {
        return $this->findBy(['product' => $product], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Applies the given id order to the product's images. Images whose ids are not
     * present in $orderedImageIds keep their relative order and are placed after.
     *
     * @param list<int> $orderedImageIds
     */
    public function reorder(ProductCore $product, array $orderedImageIds): void
    {
        $byId = [];
        foreach ($this->findByProduct($product) as $image) {
            if ($image->getId() !== null) {
                $byId[$image->getId()] = $image;
            }
        }

        $sortOrder = 0;
        foreach ($orderedImageIds as $imageId) {
            if (isset($byId[$imageId])) {
                $byId[$imageId]->setSortOrder($sortOrder++);
                unset($byId[$imageId]);
            }
        }
        foreach ($byId as $image) {
            $image->setSortOrder($sortOrder++);
        }
    }

    public function setPrimary(ProductCore $product, int $imageId): void
    {
        foreach ($this->findByProduct($product) as $image) {
            $image->setPrimaryImage($image->getId() === $imageId);
        }
    }
}
