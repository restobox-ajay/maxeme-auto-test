<?php

declare(strict_types=1);

namespace App\Maxeme\Inventory;

use App\Contract\Inventory\ProductActivityProviderInterface;
use App\Contract\Inventory\ProductSalesProviderInterface;
use App\Entity\ProductCore;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Schedule\ScheduleSettings;
use App\Repository\BundleStatusRepository;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryMovement;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The stock page's "Invoice" rows and its "Units sold" for parts used on repair orders (F18, as the
 * mockup's stock page shows them: "Invoice INV-000069 (part of a service) −1"). Read off the
 * movements RepairOrderStockIssue writes (operation key `maxeme-ro-{id}-…`): one row per movement,
 * signed as the change in on hand, linking to the repair order's invoices. Each row names the
 * movement group it stands for, so the page drops that group's plain "Movement" row.
 */
final class RepairOrderPartActivity implements ProductActivityProviderInterface, ProductSalesProviderInterface
{
    private const PREFIX = 'maxeme-ro-';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentNumbers $numbers,
        private readonly UrlGeneratorInterface $router,
        private readonly ScheduleSettings $schedule,
    ) {
    }

    public function getType(): string
    {
        return 'invoice';
    }

    public function getSource(): string
    {
        return BundleStatusRepository::CORE_SOURCE;
    }

    public function activityFor(ProductCore $product, int $limit): array
    {
        $rows = [];
        foreach ($this->movements($product, null, $limit) as $movement) {
            $group = $movement->getGroup();
            $repairOrder = $this->repairOrderOf($group->getClientOperationId());
            $out = $movement->getToDetail() === null;
            $label = 'Invoice ' . ($group->getReference() ?? '');
            if ($repairOrder !== null) {
                $label .= ' · ' . $this->numbers->repairOrderNumber($repairOrder);
                if ($out && !self::chargedThrough($repairOrder, $product)) {
                    $label .= ' (part of a service)';
                }
            }
            if (!$out) {
                $label .= ' (back in stock)';
            }
            $quantity = QuantityScale::canonical((string) $movement->getQuantity());
            $rows[] = [
                // The shop's date: stored in UTC, which is already tomorrow in the shop's evening.
                'date' => $this->schedule->toLocal($group->getOccurredAt())->format('Y-m-d'),
                'label' => $label,
                'quantity' => ($out ? '-' : '+') . self::plain($quantity),
                'url' => $repairOrder !== null ? $this->router->generate('maxeme_repair_order_invoices', ['id' => $repairOrder->getId()]) : null,
                'movementGroupId' => $group->getId(),
            ];
        }

        return $rows;
    }

    public function unitsSold(ProductCore $product, \DateTimeImmutable $since): float
    {
        $units = 0.0;
        foreach ($this->movements($product, $since, null) as $movement) {
            $units += ($movement->getToDetail() === null ? 1 : -1) * (float) $movement->getQuantity();
        }

        return $units;
    }

    /** @return list<InventoryMovement> newest first */
    private function movements(ProductCore $product, ?\DateTimeImmutable $since, ?int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('m', 'g')
            ->from(InventoryMovement::class, 'm')
            ->join('m.group', 'g')
            ->where('m.product = :product')->setParameter('product', $product)
            ->andWhere('g.clientOperationId LIKE :prefix')->setParameter('prefix', self::PREFIX . '%')
            ->orderBy('g.occurredAt', 'DESC')
            ->addOrderBy('m.id', 'DESC');
        if ($since !== null) {
            $query->andWhere('g.occurredAt >= :since')->setParameter('since', $since);
        }
        if ($limit !== null) {
            $query->setMaxResults($limit);
        }

        return $query->getQuery()->getResult();
    }

    private function repairOrderOf(string $operationKey): ?RepairOrder
    {
        if (preg_match('/^' . preg_quote(self::PREFIX, '/') . '(\d+)-/', $operationKey, $match) !== 1) {
            return null;
        }

        return $this->entityManager->find(RepairOrder::class, (int) $match[1]);
    }

    /** Whether a line of this part is billed on its own (charged through) rather than as part of a service. */
    private static function chargedThrough(RepairOrder $repairOrder, ProductCore $product): bool
    {
        foreach ($repairOrder->getJobs() as $job) {
            foreach ($job->getChargeThroughLines() as $line) {
                if ($line->getProduct()?->getId() === $product->getId()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** "2.0000" → "2", "0.5000" → "0.5". */
    private static function plain(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }
}
