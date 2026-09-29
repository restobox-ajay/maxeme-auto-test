<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Maxeme\Dto\PartData;
use App\Maxeme\Dto\RestockData;
use App\Maxeme\Entity\Part;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Parts Inventory changes (legacy InventoryBundle manageController): saving the part form,
 * editing one cell in place, and restocking. A typed quantity is recorded as a stock movement.
 */
final class PartService
{
    public function __construct(
        private readonly RecordWriter $records,
        private readonly StockLedger $stock,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param PartData $data already validated */
    public function save(Part $part, PartData $data): void
    {
        $isNew = $part->getId() === null;
        $data->applyTo($part);
        $this->entityManager->persist($part);

        if ($data->quantityValue() !== null) {
            $this->stock->setQuantity($part, $data->quantityValue(), $isNew ? 'Opening stock' : 'Quantity edited');
        }

        $this->entityManager->flush();
    }

    /**
     * One cell of the grid (legacy inlineupdateAction, which the legacy page never wired up).
     *
     * @return array<string, string> errors; empty when saved
     */
    public function saveField(Part $part, string $field, string $value): array
    {
        $property = PartData::FIELDS[$field] ?? throw new \InvalidArgumentException(sprintf('"%s" is not a part field.', $field));

        $data = PartData::fromEntity($part);
        $value = trim($value);
        $data->{$property} = $value !== '' ? $value : null;

        $errors = $this->records->validate($data);
        if (isset($errors[$property])) {
            return [$property => $errors[$property]];
        }

        $this->save($part, $data);

        return [];
    }

    /**
     * The invoice builder's "Order new part" (legacy OrderArrayToEntityFactory): a new part, with
     * the ordered quantity received against its PO number.
     *
     * @param PartData $part already validated
     * @param RestockData $order already validated
     */
    public function order(PartData $part, RestockData $order): Part
    {
        $this->save($entity = new Part(), $part);
        $this->restock($entity, $order);

        return $entity;
    }

    /** @param RestockData $data already validated */
    public function restock(Part $part, RestockData $data): void
    {
        $this->stock->record($part, (int) $data->quantity, $data->note, $data->poNumber, $data->unitPrice, $data->salePrice);
        $this->entityManager->flush();
    }
}
