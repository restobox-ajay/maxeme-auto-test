<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use App\Entity\ProductCore;
use App\Maxeme\Accounting\Money;
use App\Maxeme\Dto\ServiceLineData;
use App\Maxeme\Entity\AbstractServiceLine;
use App\Maxeme\Entity\GovtFee;
use App\Maxeme\Entity\Labour;
use App\Maxeme\Enum\ServiceLineType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Checks line rows (the service page's, or one repair order service's) and turns them into lines:
 * a kept row keeps its line, a new row gets one from $newLine.
 */
final class ServiceLineBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @template T of AbstractServiceLine
     *
     * @param list<T>                          $existing the lines saved before
     * @param list<ServiceLineData>            $rows
     * @param callable(ServiceLineType): T     $newLine  a new line of that type, on the right parent
     * @param string                           $key      the error keys' prefix, e.g. "lines" or "jobs.0.lines"
     * @param string                           $label    the messages' prefix, e.g. "Line" or "Service 1, line"
     *
     * @return array{lines: list<T>, errors: array<string, string>} errors keyed "{key}.{n}.{property}"; lines only when there are none
     */
    public function build(array $existing, array $rows, callable $newLine, string $key = 'lines', string $label = 'Line'): array
    {
        $byId = [];
        foreach ($existing as $line) {
            $byId[(string) $line->getId()] = $line;
        }

        $lines = [];
        $errors = [];
        foreach ($rows as $n => $row) {
            $rowErrors = FieldErrors::from($this->validator->validate($row));
            $type = $row->getType();
            // A kept row keeps its line (and id) unless its type changed.
            $line = $byId[(string) $row->id] ?? null;
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
                $errors[sprintf('%s.%d.%s', $key, $n, $property)] = sprintf('%s %d: %s', $label, $n + 1, $message);
            }
            if ($rowErrors !== [] || $type === null) {
                continue;
            }

            $lines[] = ($line ?? $newLine($type))
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
