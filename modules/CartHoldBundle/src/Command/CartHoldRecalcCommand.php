<?php

declare(strict_types=1);

namespace CartHoldBundle\Command;

use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\Inventory\InventoryBucketAuditLogger;
use App\Service\Inventory\InventoryOperationContext;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use CartHoldBundle\Entity\CartHold;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recomputes ProductInventory.cartHoldQuantity from source of truth (non-expired cart_hold
 * rows), correcting any drift the incremental sync path (CartHoldService::syncForCurrentCart())
 * missed. Lives in this bundle rather than the core app:inventory-recalc cron because this
 * bundle is the one that owns Cart Hold data — core has zero compile-time reference to it.
 * Meant to run hourly via crontab, same cadence as the core recalc and
 * Number1ProductImportBundle's approved recalc:
 *
 *   0 * * * * cd /path/to/app && php bin/console app:cart-hold:recalc >> /var/log/cart-hold-recalc.log 2>&1
 */
#[AsCommand(
    name: 'app:cart-hold:recalc',
    description: 'Recomputes the Cart Hold inventory bucket from source of truth, correcting any drift.',
)]
final class CartHoldRecalcCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryBucketAuditLogger $bucketAuditLogger,
        private readonly InventoryOperationContext $operations,
        private readonly WarehouseFulfillmentRegionService $warehouses,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The whole run is one operation named 'cron_correction' (#582), so every bucket this
        // command corrects is recorded in inventory_bucket_change_log under that action instead of
        // as an unattributed write. It has to span the flush below rather than each setter, because
        // that is when App\EventSubscriber\InventoryBucketChangeLogger reads the changeset — and it
        // spans the whole body rather than just the flush, because logDiscrepancy() sends mail and
        // anything that flushes an email log en route would otherwise settle a bucket outside it.
        //
        // No movement group: a recalc corrects a cached number, it does not move stock. The rows it
        // produces are group_id NULL by construction, which is the correct record.
        return $this->operations->run('cron_correction', fn (): int => $this->recalculate($input, $output));
    }

    /** The body of execute(), inside the ambient operation that names it in the bucket change log. */
    private function recalculate(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $now = new \DateTimeImmutable();
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(ci.product) AS productId, IDENTITY(ci.fulfillmentRegion) AS regionId, SUM(ci.quantity) AS total')
            ->from(CartHold::class, 'h')
            ->join('h.cartItem', 'ci')
            ->where('h.expiresAt > :now')
            ->setParameter('now', $now)
            ->groupBy('ci.product, ci.fulfillmentRegion')
            ->getQuery()
            ->getResult();

        // The holds are keyed by the REGION the cart item names; the bucket they feed belongs to
        // the WAREHOUSE serving it (#546). Both sides have to be keyed the same way, or every row
        // would be reported as a discrepancy against a key nothing else uses.
        $warehouseIdsByRegionId = $this->warehouses->warehouseIdsByRegionId();

        $newSums = [];
        foreach ($rows as $row) {
            $warehouseId = $warehouseIdsByRegionId[(int) $row['regionId']] ?? null;
            if ($warehouseId === null) {
                continue;
            }

            $key = $row['productId'] . '|' . $warehouseId;
            $newSums[$key] = QuantityScale::add($newSums[$key] ?? QuantityScale::canonical(0), $row['total']);
        }

        /** @var array<string, ProductInventory> $existingByKey */
        $existingByKey = [];
        foreach ($this->entityManager->getRepository(ProductInventory::class)->findAll() as $inventory) {
            $product = $inventory->getProduct();
            $warehouse = $inventory->getWarehouse();
            if ($product->getId() === null || !$warehouse instanceof Warehouse || $warehouse->getId() === null) {
                continue;
            }
            $existingByKey[$product->getId() . '|' . $warehouse->getId()] = $inventory;
        }

        $touched = 0;
        foreach ($existingByKey as $key => $inventory) {
            $newCartHold = $newSums[$key] ?? QuantityScale::canonical(0);
            if (QuantityScale::compare($inventory->getCartHoldQuantity(), $newCartHold) === 0) {
                continue;
            }

            $this->bucketAuditLogger->logDiscrepancy(
                $inventory->getProduct(),
                $inventory->getWarehouse(),
                'cart_hold',
                $inventory->getCartHoldQuantity(),
                $newCartHold,
                'cart_hold_recalc',
            );

            $inventory->setCartHoldQuantity($newCartHold)->touch();
            $this->entityManager->persist($inventory);
            $touched++;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Cart hold recalibration complete. %d row(s) corrected.', $touched));

        return Command::SUCCESS;
    }
}
