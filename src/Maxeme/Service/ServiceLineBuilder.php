<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Entity\ProductCore;
use App\Maxeme\Accounting\Money;
use App\Maxeme\Dto\ServiceLineData;
use App\Maxeme\Entity\GovtFee;
use App\Maxeme\Entity\Labour;
use App\Maxeme\Entity\ServiceItem;
use App\Maxeme\Entity\ServiceLine;
use App\Maxeme\Enum\ServiceLineType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Checks the service page's line rows and turns them into the service's lines. */
final class ServiceLineBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @param list<ServiceLineData> $rows
     *
     * @return array{lines: list<ServiceLine>, errors: array<string, string>} errors keyed "lines.{n}.{property}"; lines only when there are none
     */
    public function build(ServiceItem $service, array $rows): array
    {
        $existing = [];
        foreach ($service->getLines() as $line) {
            $existing[(string) $line->getId()] = $line;
        }

        $lines = [];
        $errors = [];
        foreach ($rows as $n => $row) {
            $rowErrors = FieldErrors::from($this->validator->validate($row));
            $type = $row->getType();
            // A kept row keeps its line (and id) unless its type changed.
            $line = $existing[(string) $row->id] ?? null;
            if ($line !== null && $line->getType() !== $type) {
                $line = null;
            }
            $item = null;
            if ($type !== null && $type->hasItem() && !isset($rowErrors['itemId'])) {
                // The item it already had stays allowed, even if it has since been switched off.
                $item = match (true) {
                    $row->itemId === null => null,
                    $line !== null && $line->getItemId() === (int) $row->itemId => $line->getItem(),
                    default => $this->findItem($type, (int) $row->itemId),
                };
                if ($item === null) {
                    $rowErrors['itemId'] = sprintf('Choose a %s item from the list.', $type->label());
                }
            }
            foreach ($rowErrors as $property => $message) {
                $errors[sprintf('lines.%d.%s', $n, $property)] = sprintf('Line %d: %s', $n + 1, $message);
            }
            if ($rowErrors !== [] || $type === null) {
                continue;
            }

            $lines[] = ($line ?? new ServiceLine($service, $type))
                ->setItem($item)
                ->setQuantity(Money::rounded($row->quantity))
                ->setUnitPrice(Money::rounded($row->unitPrice))
                ->setChargeThrough($row->chargeThrough);
        }

        return ['lines' => $errors === [] ? $lines : [], 'errors' => $errors];
    }

    /** The active record a line of $type may point at, or null. */
    private function findItem(ServiceLineType $type, int $id): Labour|ProductCore|GovtFee|null
    {
        return match ($type) {
            ServiceLineType::Labour, ServiceLineType::Sublet => $this->findLabour($id, $type === ServiceLineType::Sublet),
            ServiceLineType::Part => $this->entityManager->find(ProductCore::class, $id),
            ServiceLineType::GovtFee => $this->entityManager->getRepository(GovtFee::class)->findOneBy(['id' => $id, 'active' => true]),
            ServiceLineType::Discount => null,
        };
    }

    private function findLabour(int $id, bool $sublet): ?Labour
    {
        return $this->entityManager->getRepository(Labour::class)->findOneBy(['id' => $id, 'active' => true, 'sublet' => $sublet]);
    }
}
