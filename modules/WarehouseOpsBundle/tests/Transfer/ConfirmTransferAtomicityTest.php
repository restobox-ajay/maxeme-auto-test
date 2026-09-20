<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Transfer;

use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Entity\TransferOrderLine;
use WarehouseOpsBundle\Tests\Support\WarehouseOpsTestCase;
use WarehouseOpsBundle\Transfer\TransferOrderService;

/**
 * #787 §9.2's open question, answered directly rather than left as a caveat: `dispatch()` and
 * `receive()` are each their own committed unit of work (confirmed by reading TransferOrderService
 * — each does its own `$this->movements->apply()`, its own flush(), and its own
 * `recomputeTransferBuckets()` flush()), so a bare "call dispatch() then receive()" could dispatch
 * stock into transit and then fail to receive it, leaving a document and a movement nobody asked
 * to leave that way.
 *
 * MoveController::confirmTransfer() wraps the whole create-transfer / dispatch / receive sequence
 * in one `$em->wrapInTransaction()` call. This proves that wrapping actually works — that
 * Doctrine's `use_savepoints: true` (config/packages/doctrine.yaml) really does make the inner
 * services' own `wrapInTransaction()`/flush() calls participate in the SAME outer transaction via a
 * real SAVEPOINT, rather than committing early — by reproducing the exact shape confirmTransfer()
 * uses: create + persist a TransferOrder, dispatch() it for real, then fail before receive() would
 * run. If the outer wrap were not truly atomic, the TransferOrder and the dispatch's own
 * transfer_out movement would survive this test; they do not.
 */
final class ConfirmTransferAtomicityTest extends WarehouseOpsTestCase
{
    private TransferOrderService $transfers;
    private Warehouse $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transfers = self::getContainer()->get(TransferOrderService::class);

        $east = (new FulfillmentRegion())->setName('Atomicity East');
        $this->em->persist($east);
        $this->destination = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($east, 'BC', 'CA');
        $this->em->flush();
    }

    public function testAFailureAfterARealDispatchRollsBackTheWholeTransferNotJustTheMissingReceive(): void
    {
        $this->receive(20, $this->binA);
        self::assertSame(20, $this->coreQuantity());

        try {
            $this->em->wrapInTransaction(function (): void {
                $transfer = (new TransferOrder())
                    ->setNumber('TR-ATOMIC-1')
                    ->setFromWarehouse($this->warehouse)
                    ->setToWarehouse($this->destination)
                    ->setStatus(TransferOrder::STATUS_DRAFT);

                $line = (new TransferOrderLine())
                    ->setProduct($this->product)
                    ->setQuantityRequested(20);
                $transfer->addLine($line);

                $this->em->persist($transfer);
                $this->em->flush();

                // A REAL dispatch — the source row really does move to in_transit here, exactly as
                // MoveController::confirmTransfer() triggers it.
                $this->transfers->dispatch($transfer, 'atomic-test-op', 'tester');

                // Standing in for whatever would make receive() itself fail (a bin deleted mid-air,
                // a concurrent write) — what this test is actually about is whether the OUTER
                // transaction unwinds dispatch()'s already-flushed changes when anything after it
                // throws, not reproducing one specific receive() failure mode.
                throw new \RuntimeException('simulated receive() failure');
            });

            self::fail('expected the simulated failure to propagate out of wrapInTransaction()');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated receive() failure', $e->getMessage());
        }

        $this->em->clear();

        $transferCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(TransferOrder::class, 't')
            ->getQuery()
            ->getSingleScalarResult();
        self::assertSame(0, $transferCount, 'the TransferOrder itself must not survive a failure this deep in its own creation');

        // The source row is exactly as it was — not decremented into in_transit by the dispatch()
        // that (from its own, inner perspective) already succeeded and flushed.
        self::assertSame(20, $this->coreQuantity(), 'dispatch()\'s own movement must have rolled back too, not just the missing receive()');
    }
}
