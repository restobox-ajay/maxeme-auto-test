<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use App\Entity\Warehouse;
use App\Maxeme\Entity\Part;
use App\Service\Inventory\CoreInventoryTotalCountService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Copies the legacy Maxeme parts into core's product catalogue, which is where parts live now, and
 * links the invoice lines that used each part to its product. Safe to re-run: a product remembers
 * its part (`syncSource` "maxeme_part:{id}") and is updated, not duplicated.
 *
 * - SKU: the part's number ("VIN #") when no other product has it, else "PART-{id}".
 * - Active parts become Active products, deleted ones Inactive; cost and sale price carry over;
 *   manufacturer, vendor, type and notes go into the product's remarks.
 * - The stock becomes the product's count in $warehouse (core's recount), so available = the
 *   part's quantity. The legacy history rows stay as they were.
 */
final class PartConverter
{
    public const SOURCE_PREFIX = 'maxeme_part:';
    private const UNIT = 'EA';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CoreInventoryTotalCountService $counts,
    ) {
    }

    /** @return array{created: int, updated: int, lines: int} */
    public function convert(Warehouse $warehouse): array
    {
        $unit = $this->entityManager->getRepository(UnitOfMeasure::class)->findOneBy(['code' => self::UNIT]);
        $created = $updated = 0;

        /** @var list<Part> $parts */
        $parts = $this->entityManager->getRepository(Part::class)->findBy([], ['id' => 'ASC']);
        foreach ($parts as $part) {
            $product = $this->productFor($part);
            $product->getId() === null ? ++$created : ++$updated;

            $product->setName($part->getName() ?: $part->getDisplayName())
                ->setDescription($part->getDescription())
                ->setCostPrice($part->getUnitPrice())
                ->setDefaultPrice($part->getSalePrice())
                ->setRemarks(self::remarks($part));
            $part->isActive() ? $product->activate() : $product->deactivate();
            if ($unit !== null && $product->getBaseUnit() === null) {
                $product->setBaseUnit($unit)->setDefaultUnit($unit);
            }

            $this->entityManager->persist($product);
            $this->entityManager->flush();
            $this->counts->setCount($product, $warehouse, $part->getQuantity());
        }

        $lines = $this->entityManager->getConnection()->executeStatement(
            "UPDATE maxeme_invoice_part SET product_id = (SELECT p.id FROM product_core p WHERE p.sync_source = '" . self::SOURCE_PREFIX . "' || maxeme_invoice_part.part_id)
              WHERE part_id IS NOT NULL AND product_id IS NULL",
        );

        return ['created' => $created, 'updated' => $updated, 'lines' => $lines];
    }

    private function productFor(Part $part): ProductCore
    {
        $repository = $this->entityManager->getRepository(ProductCore::class);
        $source = self::SOURCE_PREFIX . $part->getId();
        $product = $repository->findOneBy(['syncSource' => $source]);
        if ($product !== null) {
            return $product;
        }

        $number = strtoupper(trim((string) $part->getVin()));
        $sku = $number !== '' && mb_strlen($number) <= 80 && $repository->findOneBy(['sku' => $number]) === null ? $number : 'PART-' . $part->getId();

        return (new ProductCore())->setSku($sku)->setSyncSource($source);
    }

    private static function remarks(Part $part): ?string
    {
        $remarks = array_filter([
            'Manufacturer' => $part->getManufacturer(),
            'Vendor' => $part->getVendor(),
            'Type' => $part->getType()?->name,
            'Notes' => $part->getNotes(),
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '');

        return $remarks !== [] ? implode("\n", array_map(static fn (string $label, string $value): string => $label . ': ' . trim($value), array_keys($remarks), $remarks)) : null;
    }
}
