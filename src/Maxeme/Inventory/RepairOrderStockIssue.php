<?php

declare(strict_types=1);

namespace App\Maxeme\Inventory;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Maxeme\Document\DocumentNumbers;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Enum\InvoiceStatus;
use App\Maxeme\Enum\RepairOrderStatus;
use App\Maxeme\Enum\ServiceLineType;
use App\Maxeme\Repository\InvoiceRepository;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovement;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\AutomaticSourcePicker;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A repair order's parts leave the stock when its invoice is issued, and come back when that is
 * undone (F15, the user's rule of 2026-11-21):
 *
 *   - the repair order has an invoice that is not cancelled, and is not Cancelled itself
 *       → every part on its services' lines leaves the stock (available → out), whether or not the
 *         line is charged through: the part was used either way ("part of a service");
 *   - every invoice is cancelled or deleted, or the repair order is Cancelled
 *       → what was taken comes back (in → available).
 *
 * Out of the stock altogether, not into core's `sold` status: core never counts `sold` against on
 * hand (an order's invoice keeps holding it instead), while a withdrawal lowers on hand, which is
 * what the mockup's stock page shows ("Invoice … (part of a service) −1").
 *
 * It is a reconcile, not a one-off: what should be out is compared with what this repair order's
 * own movements took out so far, and only the difference moves. So a part added, removed or
 * changed after the invoice was issued is followed too. Each is a core `ship` movement with the
 * invoice number as its reference and `maxeme-ro-{id}-` as the start of its operation key, which
 * is how its total is found again.
 *
 * Only what is on the shelf can be taken: a part with too little stock takes what there is, and
 * the rest is taken by a later reconcile once stock is received.
 */
final class RepairOrderStockIssue
{
    /** @param array{region: string} $warehouse */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StockMovementService $movements,
        private readonly AutomaticSourcePicker $picker,
        private readonly InventoryDetailRepository $details,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly InvoiceRepository $invoices,
        private readonly DocumentNumbers $numbers,
        private readonly Security $security,
        #[Autowire(param: 'maxeme.warehouse')]
        private readonly array $warehouse,
    ) {
    }

    /** The invoice its parts are out under (its newest one not cancelled), or null when they should be on the shelf. */
    public function issuingInvoice(RepairOrder $repairOrder): ?Invoice
    {
        if ($repairOrder->getStatus() === RepairOrderStatus::Cancelled) {
            return null;
        }

        // The newest not cancelled, else the newest: so a cancelled one here means all are.
        $invoice = $this->invoices->findCurrentForRepairOrder($repairOrder);

        return $invoice?->getStatus() === InvoiceStatus::Cancelled ? null : $invoice;
    }

    public function sync(RepairOrder $repairOrder): void
    {
        $id = $repairOrder->getId();
        $warehouse = $this->warehouses->warehouseForRegionName($this->warehouse['region']);
        if ($id === null || !$warehouse instanceof Warehouse) {
            return;
        }

        $invoice = $this->issuingInvoice($repairOrder);

        /** @var array<int, array{product: ProductCore, quantity: string}> $wanted */
        $wanted = [];
        if ($invoice !== null) {
            foreach ($repairOrder->getJobs() as $job) {
                foreach ($job->getLines() as $line) {
                    $product = $line->getProduct();
                    if ($line->getType() === ServiceLineType::Part && $product !== null && $product->getId() !== null) {
                        $key = $product->getId();
                        $wanted[$key] = ['product' => $product, 'quantity' => QuantityScale::add($wanted[$key]['quantity'] ?? '0', QuantityScale::canonical($line->getQuantity()))];
                    }
                }
            }
        }

        $issued = $this->issuedByProduct($id);
        $roNumber = $this->numbers->repairOrderNumber($repairOrder);
        // The invoice the parts went out under, or the cancelled one they come back from.
        $document = $invoice ?? $this->invoices->findCurrentForRepairOrder($repairOrder);
        $reference = $document !== null ? $this->numbers->number($document) : $roNumber;
        $actor = $this->security->getUser()?->getUserIdentifier();

        $out = MovementRequest::of(InventoryMovementGroup::TYPE_SHIP, self::operationKey($id), sprintf('Parts used on repair order %s (invoice issued)', $roNumber), $actor, $reference);
        $back = MovementRequest::of(InventoryMovementGroup::TYPE_SHIP, self::operationKey($id), sprintf('Parts back in stock from repair order %s (invoice cancelled or part removed)', $roNumber), $actor, $reference);

        foreach (array_unique([...array_keys($wanted), ...array_keys($issued)]) as $productId) {
            $product = $wanted[$productId]['product'] ?? $this->entityManager->find(ProductCore::class, $productId);
            if (!$product instanceof ProductCore) {
                continue;
            }
            $difference = QuantityScale::sub($wanted[$productId]['quantity'] ?? '0', $issued[$productId] ?? '0');

            if (QuantityScale::compare($difference, 0) > 0) {
                $onShelf = '0';
                foreach ($this->details->pickableRows($product, $warehouse) as $row) {
                    $onShelf = QuantityScale::add($onShelf, QuantityScale::canonical($row->getQuantity()));
                }
                $take = QuantityScale::compare($difference, $onShelf) <= 0 ? $difference : $onShelf;
                // From the rows core's own picker would take (earliest expiry, then bin order).
                foreach (QuantityScale::compare($take, 0) > 0 ? $this->picker->plan($product, $warehouse, $take) : [] as $step) {
                    $row = $step['detail'];
                    $out->remove($product, new DetailKey($warehouse, $row->getLocation(), $row->getLot(), $row->getSerial(), InventoryDetail::STATUS_AVAILABLE), $step['quantity']);
                }
            } elseif (QuantityScale::compare($difference, 0) < 0) {
                $back->receive($product, new DetailKey($warehouse), QuantityScale::sub('0', $difference));
            }
        }

        foreach ([$back, $out] as $request) {
            if (!$request->isEmpty()) {
                $this->movements->apply($request);
            }
        }
    }

    /**
     * What this repair order's movements have taken out of the stock so far, per product: out of
     * `available` to nowhere, minus in from nowhere.
     *
     * @return array<int, string>
     */
    private function issuedByProduct(int $repairOrderId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(m.product) AS product', 'm.quantity AS quantity', 'f.status AS fromStatus', 't.status AS toStatus')
            ->from(InventoryMovement::class, 'm')
            ->join('m.group', 'g')
            ->leftJoin('m.fromDetail', 'f')
            ->leftJoin('m.toDetail', 't')
            ->where('g.clientOperationId LIKE :prefix')
            ->setParameter('prefix', self::operationPrefix($repairOrderId) . '%')
            ->getQuery()
            ->getArrayResult();

        $issued = [];
        foreach ($rows as $row) {
            $product = (int) $row['product'];
            $quantity = QuantityScale::canonical((string) $row['quantity']);
            if ($row['fromStatus'] !== null && $row['toStatus'] === null) {
                $issued[$product] = QuantityScale::add($issued[$product] ?? '0', $quantity);
            }
            if ($row['fromStatus'] === null && $row['toStatus'] !== null) {
                $issued[$product] = QuantityScale::sub($issued[$product] ?? '0', $quantity);
            }
        }

        return $issued;
    }

    private static function operationPrefix(int $repairOrderId): string
    {
        return sprintf('maxeme-ro-%d-', $repairOrderId);
    }

    private static function operationKey(int $repairOrderId): string
    {
        return self::operationPrefix($repairOrderId) . bin2hex(random_bytes(12));
    }
}
