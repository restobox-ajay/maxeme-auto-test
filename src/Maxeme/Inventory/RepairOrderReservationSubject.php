<?php

declare(strict_types=1);

namespace App\Maxeme\Inventory;

use App\Entity\InventoryReservation;
use App\Entity\InvoiceInventoryReservation;
use App\Entity\OrderInventoryReservation;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\RepairOrderInventoryReservation;
use App\Maxeme\Enum\RepairOrderStatus;
use App\Maxeme\Enum\ServiceLineType;
use App\Service\Inventory\InventoryReservationSubject;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A repair order, as core's InventoryReservationReconciler sees it: the parts on its services'
 * lines, held in the bucket its status puts them in.
 *
 *   Authorized                        → sales_hold (the customer approved)
 *   In Progress, Awaiting Parts       → approved   (the work started; parts added later go here too)
 *   Completed, Picked Up, Invoiced    → shipped    (the work is done)
 *   anything else (an estimate, Cancelled) → none: what it held is released
 *
 * A part line holds its quantity whether or not it is charged through: the part is used either way.
 */
final class RepairOrderReservationSubject implements InventoryReservationSubject
{
    public function __construct(
        private readonly RepairOrder $repairOrder,
        private readonly string $warehouseRegion,
        private readonly string $reference,
    ) {
    }

    public static function bucketFor(RepairOrderStatus $status): ?string
    {
        return match ($status) {
            RepairOrderStatus::Authorized => OrderInventoryReservation::BUCKET_SALES_HOLD,
            RepairOrderStatus::InProgress, RepairOrderStatus::AwaitingParts => InvoiceInventoryReservation::BUCKET_APPROVED,
            RepairOrderStatus::Completed, RepairOrderStatus::CompletedPickedUp, RepairOrderStatus::Invoiced => InvoiceInventoryReservation::BUCKET_SHIPPED,
            default => null,
        };
    }

    public function targetBucket(): ?string
    {
        return self::bucketFor($this->repairOrder->getStatus());
    }

    public function heldLines(): iterable
    {
        foreach ($this->repairOrder->getJobs() as $job) {
            foreach ($job->getLines() as $line) {
                $product = $line->getProduct();
                if ($line->getType() === ServiceLineType::Part && $product !== null) {
                    yield ['product' => $product, 'location' => null, 'quantity' => QuantityScale::canonical($line->getQuantity()), 'lot' => null, 'serial' => null];
                }
            }
        }
    }

    public function fallbackRegionName(): ?string
    {
        return $this->warehouseRegion;
    }

    public function existingReservations(EntityManagerInterface $entityManager): array
    {
        return $entityManager->getRepository(RepairOrderInventoryReservation::class)->findBy(['repairOrder' => $this->repairOrder]);
    }

    public function newReservation(ProductCore $product, Warehouse $warehouse): InventoryReservation
    {
        return new RepairOrderInventoryReservation($this->repairOrder, $product, $warehouse, (string) $this->targetBucket());
    }

    public function changeAction(): string
    {
        return 'repair_order_reconciled';
    }

    public function document(): object
    {
        return $this->repairOrder;
    }

    public function reference(): string
    {
        return $this->reference;
    }
}
