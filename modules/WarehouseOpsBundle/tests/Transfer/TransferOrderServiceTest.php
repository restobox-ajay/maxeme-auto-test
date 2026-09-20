<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Transfer;

use App\Entity\FulfillmentRegion;
use App\Entity\InventoryBucketChangeLog;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Entity\TransferOrderLine;
use WarehouseOpsBundle\Tests\Support\WarehouseOpsTestCase;
use WarehouseOpsBundle\Transfer\TransferException;
use WarehouseOpsBundle\Transfer\TransferOrderService;

/**
 * A transfer is two movements, and the state between them is the point: **stock on a truck is
 * sellable at neither end**.
 */
final class TransferOrderServiceTest extends WarehouseOpsTestCase
{
    private TransferOrderService $transfers;
    private Warehouse $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transfers = self::getContainer()->get(TransferOrderService::class);

        $east = (new FulfillmentRegion())->setName('East');
        $this->em->persist($east);
        $this->destination = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($east, 'BC', 'CA');
        $this->em->flush();
    }

    private function draft(int $quantity, ?object $lot = null): TransferOrder
    {
        $transfer = (new TransferOrder())
            ->setNumber('TR-000001')
            ->setFromWarehouse($this->warehouse)
            ->setToWarehouse($this->destination)
            ->setStatus(TransferOrder::STATUS_DRAFT);

        $line = (new TransferOrderLine())
            ->setProduct($this->product)
            ->setLot($lot instanceof \InventoryDepthBundle\Entity\InventoryLot ? $lot : null)
            ->setSku($this->product->getSku())
            ->setName($this->product->getName())
            ->setQuantityRequested($quantity);

        $transfer->addLine($line);
        $this->em->persist($transfer);
        $this->em->persist($line);
        $this->em->flush();

        return $transfer;
    }

    public function testADispatchedButUnreceivedTransferLeavesStockSellableAtNeitherEnd(): void
    {
        $this->receive(25, $this->binA);
        self::assertSame(25, $this->coreQuantity());

        $transfer = $this->draft(10);
        $this->transfers->dispatch($transfer, 'tr-1', 'tester');

        self::assertSame(TransferOrder::STATUS_DISPATCHED, $transfer->getStatus());
        self::assertSame(15, $this->coreQuantity(), 'the source can no longer sell what is on the truck');
        self::assertSame(0, $this->coreQuantity($this->product, $this->destination), 'and the destination does not have it yet');
        self::assertSame('10.0000', $transfer->inTransitUnits());

        // Still locatable: the in-transit row names the warehouse that shipped it.
        $transit = $this->details->findExisting($this->product, $this->warehouse, null, null, null, InventoryDetail::STATUS_IN_TRANSIT);
        self::assertNotNull($transit);
        self::assertSame('10.0000', $transit->getQuantity());
    }

    public function testReceiptPutsItOnTheDestinationShelf(): void
    {
        $this->receive(25, $this->binA);
        $transfer = $this->draft(10);
        $this->transfers->dispatch($transfer, 'tr-2', 'tester');

        /** @var TransferOrderLine $line */
        $line = $transfer->getLines()->first();
        $group = $this->transfers->receive($transfer, [$line->getId() => 10], [$line->getId() => null], 'tr-2', 'tester');

        self::assertSame(InventoryMovementGroup::TYPE_TRANSFER, $group->getType());
        self::assertSame(15, $this->coreQuantity());
        self::assertSame(10, $this->coreQuantity($this->product, $this->destination));
        self::assertSame('0.0000', $transfer->inTransitUnits(), 'everything that left arrived');
    }

    /**
     * The transfer buckets are the hard case for #582's attribution, and the reason both halves of
     * this service open an operation of their own.
     *
     * recomputeTransferBuckets() runs AFTER StockMovementService::apply() has returned, so it is
     * outside the operation that recorded the movements — and since #584 it is the ONLY writer of
     * `transfer_out` and `transfer_in` anywhere in the application. Left as it was, every row for
     * either bucket would read 'unattributed' with a null group: the whole gap #582 closes, one
     * layer further out.
     *
     * An earlier draft of this test asserted a source-side `received` correction instead. That
     * write no longer exists — #584 deleted adjustSourceReceived() along with the cached-sum
     * `transfer_out` that made it necessary — so what is checked here is the recompute that
     * replaced it.
     */
    public function testTheDispatchesTransferOutIsRecordedAgainstTheDispatchThatCausedIt(): void
    {
        $this->receive(25, $this->binA);
        $transfer = $this->draft(10);

        $before = $this->em->getRepository(InventoryBucketChangeLog::class)->count([]);

        $group = $this->transfers->dispatch($transfer, 'tr-log-0', 'tester');

        $transferOut = array_values(array_filter(
            $this->entriesSince($before),
            fn (InventoryBucketChangeLog $entry): bool => $entry->getBucket() === InventoryBucketChangeLog::BUCKET_TRANSFER_OUT,
        ));

        self::assertCount(1, $transferOut, 'the source warehouse put 10 units on a truck and that is one bucket change, which has to be in the log');
        self::assertSame($this->warehouse->getId(), $transferOut[0]->getWarehouse()->getId());
        self::assertSame('0.0000', $transferOut[0]->getPreviousQuantity());
        self::assertSame('10.0000', $transferOut[0]->getNewQuantity());
        self::assertSame(
            'transfer_dispatched',
            $transferOut[0]->getAction(),
            'written after apply() returned, so only the operation dispatch() opens for itself can name it',
        );
        self::assertSame(
            $group->getId(),
            $transferOut[0]->getGroupId(),
            'and it is attributed to the dispatch group, because the outer operation carries the group apply() handed back',
        );
    }

    public function testTheReceiptsTransferInIsRecordedAgainstTheReceiptThatCausedIt(): void
    {
        $this->receive(25, $this->binA);
        $transfer = $this->draft(10);
        $this->transfers->dispatch($transfer, 'tr-log-1', 'tester');
        $line = $transfer->getLines()->first();

        // Only the rows this receipt added: the dispatch above legitimately wrote its own.
        $before = $this->em->getRepository(InventoryBucketChangeLog::class)->count([]);

        $group = $this->transfers->receive($transfer, [$line->getId() => 10], [$line->getId() => null], 'tr-log-1', 'tester');

        $entries = $this->entriesSince($before);

        $transferIn = array_values(array_filter(
            $entries,
            fn (InventoryBucketChangeLog $entry): bool => $entry->getBucket() === InventoryBucketChangeLog::BUCKET_TRANSFER_IN,
        ));

        self::assertCount(1, $transferIn, "the destination's arrival is one transfer_in change and must appear in the log");
        self::assertSame($this->destination->getId(), $transferIn[0]->getWarehouse()->getId());
        self::assertSame('0.0000', $transferIn[0]->getPreviousQuantity());
        self::assertSame('10.0000', $transferIn[0]->getNewQuantity());
        self::assertSame('transfer_received', $transferIn[0]->getAction());
        self::assertSame(
            $group->getId(),
            $transferIn[0]->getGroupId(),
            'attributed to the receipt group even though it was written after apply() returned',
        );

        // The source's transfer_out is untouched by a receipt — quantity_dispatched did not move —
        // so it correctly produces no row at all. `transfer_out` stays standing at 10 for stock that
        // is now in another building, which is the invariant #584 exists to hold.
        self::assertSame(
            [],
            array_values(array_filter(
                $entries,
                fn (InventoryBucketChangeLog $entry): bool => $entry->getBucket() === InventoryBucketChangeLog::BUCKET_TRANSFER_OUT,
            )),
            'a receipt does not move the source\'s transfer_out, and a recompute writing back the same number logs nothing',
        );

        self::assertNotSame([], $entries, "the receipt's bucket changes are logged");
        foreach ($entries as $entry) {
            self::assertSame(
                $group->getId(),
                $entry->getGroupId(),
                'every bucket change in one receipt names the same group, which is what makes the halves findable together',
            );
        }
    }

    /**
     * The change log rows written since `$before` rows existed, oldest first.
     *
     * @return list<InventoryBucketChangeLog>
     */
    private function entriesSince(int $before): array
    {
        return array_values(array_slice(
            $this->em->getRepository(InventoryBucketChangeLog::class)->findBy([], ['id' => 'ASC']),
            $before,
        ));
    }

    public function testWhatLeftAndDidNotArriveStaysVisibleRatherThanBeingReconciledAway(): void
    {
        $this->receive(25, $this->binA);
        $transfer = $this->draft(10);
        $this->transfers->dispatch($transfer, 'tr-3', 'tester');

        /** @var TransferOrderLine $line */
        $line = $transfer->getLines()->first();
        $this->transfers->receive($transfer, [$line->getId() => 8], [$line->getId() => null], 'tr-3', 'tester');

        self::assertSame('10.0000', $line->getQuantityDispatched());
        self::assertSame('8.0000', $line->getQuantityReceived());
        self::assertSame('2.0000', $transfer->inTransitUnits(), 'the gap between the two is stock lost in transit');

        // The two are still sitting where they were, unsellable at both ends, until somebody writes
        // them off deliberately. Closing the document is not the same as pretending they arrived.
        $transit = $this->details->findExisting($this->product, $this->warehouse, null, null, null, InventoryDetail::STATUS_IN_TRANSIT);
        self::assertSame('2.0000', $transit?->getQuantity());
        self::assertSame(15, $this->coreQuantity());
        self::assertSame(8, $this->coreQuantity($this->product, $this->destination));
    }

    public function testATransferPrefersTheLongestDatedBatchRatherThanTheEarliest(): void
    {
        $soon = $this->lot('EARLY', '2026-09-01');
        $later = $this->lot('LATE', '2028-09-01');

        $this->receive(10, $this->binA, $soon);
        $this->receive(10, $this->binB, $later);

        // The opposite of a customer shipment, deliberately: sending the earliest-expiring stock to
        // a slower site is how it expires there.
        self::assertSame($later->getId(), $this->transfers->suggestLot($this->product, $this->warehouse)?->getId());
    }

    public function testDispatchingMoreThanIsThereIsRefusedBeforeAnythingMoves(): void
    {
        $this->receive(5, $this->binA);
        $transfer = $this->draft(10);

        try {
            $this->transfers->dispatch($transfer, 'tr-4', 'tester');
            self::fail('a transfer cannot send stock that is not there');
        } catch (\InventoryDepthBundle\Movement\InsufficientStockException $e) {
            self::assertStringContainsString('5', $e->getMessage());
        }

        self::assertSame(5, $this->coreQuantity(), 'nothing moved');
        self::assertSame(TransferOrder::STATUS_DRAFT, $transfer->getStatus());
    }

    public function testOnlyADraftDispatchesAndOnlyADispatchedReceives(): void
    {
        $this->receive(25, $this->binA);
        $transfer = $this->draft(10);
        $this->transfers->dispatch($transfer, 'tr-5', 'tester');

        $inBinAfterFirstDispatch = $this->inBin($this->binA);

        try {
            $this->transfers->dispatch($transfer, 'tr-5b', 'tester');
            self::fail('a dispatched transfer cannot be dispatched again');
        } catch (TransferException $e) {
            self::assertMatchesRegularExpression('/only a draft transfer can be dispatched/', $e->getMessage());
        }

        // "It threw" is not "nothing moved" (#594). The status guard sits before the movement
        // request is applied today; relocate it after — into dispatchWithinOperation(), which
        // applies and flushes before anything else can fail — and ten more units leave bin A into
        // in_transit on a transfer that then raises exactly this exception. expectException alone
        // could not tell the two apart. The sibling refusal above already asserts this way.
        self::assertSame($inBinAfterFirstDispatch, $this->inBin($this->binA), 'a refused re-dispatch takes nothing off the shelf');
        self::assertSame(TransferOrder::STATUS_DISPATCHED, $transfer->getStatus(), 'and leaves the transfer where it was');
    }

    /**
     * Revised for the simple-inventory bucket parity plan (2026-09-15), then revised again by
     * "Inventory detail always tracks location, even for simple products" (beaff217, 2026-09-18):
     * `transfer_out`/`transfer_in` still sum purely from `TransferOrderLine` quantities, dimensional
     * or not — that part of the 2026-09-15 plan needed no change and still needs none. What changed
     * is the sufficiency check and the movement's own bookkeeping: every line now resolves a real
     * `InventoryDetail` row, simple product included, with no bin because `sourceBin()` correctly
     * returns null when there is none to pick from.
     *
     * That means a simple product's `quantity` (the import baseline) is not itself withdrawable —
     * "unspecified until specified" (see `InventoryDetailAlwaysTracksLocationCest`): only stock that
     * has actually moved through `StockMovementService`, the same way every other fixture in this
     * suite receives its stock, ties to a detail row a transfer can draw from.
     */
    public function testASimpleInventoryProductTransfersOnBucketsAloneWithNoDetailRow(): void
    {
        $simple = (new \App\Entity\ProductCore())
            ->setSku('SIMPLE-TRANSFER-1')
            ->setName('Simple Transfer Thing')
            ->setSyncSource(\App\Entity\ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($simple);
        $this->em->flush();

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed-simple-transfer')
                ->receive($simple, new DetailKey($this->warehouse), 25),
        );

        $transfer = (new TransferOrder())
            ->setNumber('TR-SIMPLE-1')
            ->setFromWarehouse($this->warehouse)
            ->setToWarehouse($this->destination)
            ->setStatus(TransferOrder::STATUS_DRAFT);
        $line = (new TransferOrderLine())
            ->setProduct($simple)
            ->setSku($simple->getSku())
            ->setName($simple->getName())
            ->setQuantityRequested(10);
        $transfer->addLine($line);
        $this->em->persist($transfer);
        $this->em->persist($line);
        $this->em->flush();

        $group = $this->transfers->dispatch($transfer, 'tr-simple-1', 'tester');

        self::assertSame(InventoryMovementGroup::TYPE_TRANSFER, $group->getType());
        self::assertSame(TransferOrder::STATUS_DISPATCHED, $transfer->getStatus());

        // A simple product now gets a real detail row too (beaff217) — unbinned, since it was never
        // put away in one, but real: the in-transit leg exists and the available leg was drawn down.
        $inTransit = $this->details->findExisting($simple, $this->warehouse, null, null, null, InventoryDetail::STATUS_IN_TRANSIT);
        self::assertNotNull($inTransit, 'a simple product\'s stock still resolves a real in-transit row once it moves');
        self::assertSame('10.0000', $inTransit->getQuantity());
        self::assertSame('15.0000', $this->details->availableTotal($simple, $this->warehouse), 'the 25 received less the 10 dispatched');

        $sourceRow = $this->em->getRepository(\App\Entity\ProductInventory::class)
            ->findOneBy(['product' => $simple, 'warehouse' => $this->warehouse]);
        self::assertSame('0.0000', $sourceRow->getQuantity(), 'the import baseline was never used — everything here arrived through a movement');
        self::assertSame('25.0000', $sourceRow->getReceivedQuantity(), 'untouched by the transfer, which never writes received');
        self::assertSame('10.0000', $sourceRow->getTransferOutQuantity());

        $this->transfers->receive($transfer, [$line->getId() => 10], [$line->getId() => null], 'tr-simple-1', 'tester');

        $destinationRow = $this->em->getRepository(\App\Entity\ProductInventory::class)
            ->findOneBy(['product' => $simple, 'warehouse' => $this->destination]);
        self::assertSame('10.0000', $destinationRow->getTransferInQuantity());
        self::assertSame('0.0000', $transfer->inTransitUnits(), 'everything that left arrived');
    }
}
