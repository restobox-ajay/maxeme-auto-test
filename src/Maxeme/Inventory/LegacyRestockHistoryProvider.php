<?php

declare(strict_types=1);

namespace App\Maxeme\Inventory;

use App\Contract\Inventory\ProductPurchaseHistoryProviderInterface;
use App\Entity\ProductCore;
use App\Maxeme\Entity\InventoryHistory;
use App\Maxeme\Service\PartConverter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The old system's restocks of a part, for the stock page's Purchase History (thread 13631).
 *
 * These are the legacy Parts Inventory's stock-in rows (InventoryHistory with a positive
 * quantity), imported read only. A product made from a legacy part carries the sync source
 * "maxeme_part:{id}", which is how its rows are found. The old system kept the PO number and
 * the unit price paid. The vendor was kept on the part itself, not on each restock, so that is
 * what the vendor column shows.
 */
final class LegacyRestockHistoryProvider implements ProductPurchaseHistoryProviderInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function getSource(): ?string
    {
        return null;
    }

    public function purchaseHistory(ProductCore $product, int $limit): array
    {
        $source = (string) $product->getSyncSource();
        if (!str_starts_with($source, PartConverter::SOURCE_PREFIX)) {
            return [];
        }
        $partId = substr($source, strlen(PartConverter::SOURCE_PREFIX));
        if (!ctype_digit($partId)) {
            return [];
        }

        /** @var list<InventoryHistory> $restocks */
        $restocks = $this->em->createQueryBuilder()
            ->select('h', 'p')
            ->from(InventoryHistory::class, 'h')
            ->innerJoin('h.part', 'p')
            ->andWhere('IDENTITY(h.part) = :part')->setParameter('part', (int) $partId)
            ->andWhere('h.quantity > 0')
            ->orderBy('h.createdOn', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $rows = [];
        foreach ($restocks as $restock) {
            $unitCost = $restock->getUnitPrice();
            $quantity = (string) $restock->getQuantity();
            $po = trim((string) $restock->getPoNumber());
            $vendor = trim((string) $restock->getPart()->getVendor());

            $rows[] = [
                'date' => $restock->getCreatedOn(),
                'sku' => $product->getSku(),
                'vendor' => $vendor !== '' ? $vendor : null,
                'reference' => 'Old system restock' . ($po !== '' ? ' · PO ' . $po : ''),
                'url' => null,
                'quantity' => $quantity,
                'unitCost' => $unitCost,
                'totalCost' => $unitCost !== null
                    ? bcround(bcmul($quantity, $unitCost, 6), 2, \RoundingMode::HalfAwayFromZero)
                    : null,
            ];
        }

        return $rows;
    }
}
