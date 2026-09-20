<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\EventSubscriber;

use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use Doctrine\ORM\Events;
use InventoryDepthBundle\Entity\Shipment;
use InventoryDepthBundle\Entity\ShipmentLine;
use InventoryDepthBundle\Shipment\ShipmentException;
use InventoryDepthBundle\Shipment\ShipmentRequest;
use InventoryDepthBundle\Shipment\ShipmentService;

/**
 * "And if everything was shipped then invoice is set to completed" — the owner's second sentence,
 * and the mirror of `InvoiceShippingRemainderSubscriberTest` next door.
 *
 * ## Every status is read out of the DATABASE, never off an entity fetched beforehand
 *
 * `invoice.status` is read back with raw SQL through {@see self::statusColumn()}, for the reason
 * `ShippedQuantityProviderTest` reads `shipping_status` that way: the entity in memory is what the
 * listener and the gate were working from, so asking it would be asking the accused, and an
 * in-memory value that never reached the database would pass. Both halves matter here, because this
 * listener writes from inside a `postFlush` and owes its own flush for the write to exist at all.
 */
final class InvoiceCompletedWhenFullyShippedSubscriberTest extends DoctrineIntegrationTestCase
{
    private ShipmentService $shipments;
    private Company $company;
    private string $regionName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shipments = self::getContainer()->get(ShipmentService::class);

        $region = (new FulfillmentRegion())->setName('Auto Complete Region');
        $this->em->persist($region);
        $warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');
        $this->regionName = $warehouse->getName();

        $this->company = (new Company())->setName('Buyer Ltd');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    // ── it completes ────────────────────────────────────────────────────────────────────────────

    public function testShippingAWholeLineCompletesTheInvoice(): void
    {
        $line = $this->processingInvoiceLine('AC-WHOLE', '5.00');
        self::assertSame('Processing', $this->statusColumn($line->getInvoice()), 'precondition');

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '5.00'));

        self::assertSame('Completed', $this->statusColumn($line->getInvoice()));
        self::assertSame('Shipped', $this->shippingStatusColumn($line->getInvoice()));
    }

    /**
     * The adversarial case for the decimal half and the completion half at once.
     *
     * 0.6 + 0.9 is exactly 1.5, and the invoice has to notice. As floats the two parts sum to
     * 1.4999999999999998 and this line is short forever — the invoice would sit at Processing with
     * every unit of it already gone. Rounded to whole units, as this bundle used to do, the FIRST
     * shipment is refused outright as "quantity must be greater than zero" and there is nothing to
     * complete at all.
     */
    public function testTwoFractionalShipmentsSummingExactlyToTheBilledQuantityCompleteTheInvoice(): void
    {
        $line = $this->processingInvoiceLine('AC-FRACTIONS', '1.5000');

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '0.6000'));
        self::assertSame('Processing', $this->statusColumn($line->getInvoice()), 'still owed 0.9');
        self::assertSame('Partially Shipped', $this->shippingStatusColumn($line->getInvoice()));

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '0.9000'));

        self::assertSame('Completed', $this->statusColumn($line->getInvoice()));
        self::assertSame('Shipped', $this->shippingStatusColumn($line->getInvoice()));
    }

    public function testEveryLineMustBeCoveredNotJustTheTotals(): void
    {
        // 6 shipped against a 3 + 3 invoice is "enough" on the totals and is NOT completion: one
        // whole line never left the shelf. The deriver caps each line at its own billed quantity and
        // this listener asks the deriver rather than doing its own sum, which is what makes that
        // true here without a second copy of the rule.
        $first = $this->processingInvoiceLine('AC-CAP-A', '3.00');
        $second = $this->addLineTo($first->getInvoice(), 'AC-CAP-B', '3.00');

        try {
            $this->shipments->ship(ShipmentRequest::for($this->company)->add($first, '6.00'));
            self::fail('ship() should refuse to put 6 against a line billed at 3');
        } catch (ShipmentException) {
            // The oversell guard is what stops this arriving; the assertion below is that the
            // invoice did not complete on a partial shipment either way.
        }

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($first, '3.00'));

        self::assertSame('Processing', $this->statusColumn($first->getInvoice()));
        self::assertSame('Partially Shipped', $this->shippingStatusColumn($first->getInvoice()));
        self::assertSame('3.0000', $this->shipments->remainingToShip($second), 'the second line is still owed in full');
    }

    // ── it leaves alone what it must ─────────────────────────────────────────────────────────────

    /**
     * 0.4 of a 1-unit line. Today's rounding refuses this outright — `(int) round(0.4)` is 0 and the
     * request is rejected as "shipment quantity must be greater than zero" — so a real part shipment
     * could not be recorded at all, and the status it should have produced was unreachable.
     */
    public function testAFractionOfAUnitShipsAndLeavesTheInvoiceExactlyWhereItWas(): void
    {
        $line = $this->processingInvoiceLine('AC-PART', '1.0000');

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '0.4000'));

        self::assertSame('Partially Shipped', $this->shippingStatusColumn($line->getInvoice()));
        self::assertSame('Processing', $this->statusColumn($line->getInvoice()), 'an invoice still owing 0.6 has not been fulfilled');
        self::assertSame('0.6000', $this->shipments->remainingToShip($line));
    }

    public function testAnInvoiceThatIsNotAtProcessingIsLeftWhereItIsAndTheShipmentStillStands(): void
    {
        // Pending, not Processing. The gate allows Completed only from Processing, so this refuses —
        // quietly, because the goods have already been recorded as gone and failing that write to
        // enforce a lifecycle rule would lose the shipment.
        $line = $this->invoiceLine('AC-PENDING', '2.00');
        $line->getInvoice()->issue(DocumentActor::system());
        $this->em->flush();
        self::assertSame('Pending', $this->statusColumn($line->getInvoice()), 'precondition');

        $shipment = $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '2.00'));

        self::assertSame('Pending', $this->statusColumn($line->getInvoice()));
        self::assertNotNull($shipment->getId(), 'the shipment was recorded despite the refused completion');
        self::assertSame('Shipped', $this->shippingStatusColumn($line->getInvoice()), 'the derived column still moved — it is a different axis');
    }

    public function testACancelledInvoiceIsNeverCompletedByAShipment(): void
    {
        $line = $this->processingInvoiceLine('AC-CANCELLED', '2.00');
        $line->getInvoice()->setStatus('Cancelled', DocumentActor::system());
        $this->em->flush();

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '2.00'));

        self::assertSame('Cancelled', $this->statusColumn($line->getInvoice()));
    }

    /**
     * Constraint 4, asserted rather than assumed.
     *
     * An invoice with nothing billable must never complete itself by having nothing to wait for.
     * The deriver answers `NotShipped` for it — "Shipped and not shipped are both vacuously true of
     * nothing" — so this falls out of asking the deriver instead of counting lines here, and this
     * test is what proves that reasoning holds rather than merely sounds right.
     *
     * The `ShipmentLine` is written directly because `ship()` refuses a zero quantity, which is the
     * correct refusal and also means the listener could never be reached through it. The row is what
     * queues the invoice; the point is what happens next.
     */
    public function testAnInvoiceWithNothingBillableNeverCompletesItself(): void
    {
        $line = $this->processingInvoiceLine('AC-NOTHING', '0.0000');

        $this->recordShipmentLineDirectly($line, '1.0000');

        self::assertSame('Processing', $this->statusColumn($line->getInvoice()));
        self::assertSame('Not Shipped', $this->shippingStatusColumn($line->getInvoice()));
    }

    // ── it does not loop ────────────────────────────────────────────────────────────────────────

    /**
     * Ship everything -> Completed -> `InvoiceShippingRemainderSubscriber` finds nothing left ->
     * stop.
     *
     * The termination pin for the direction this change adds. A flush inside a `postFlush` that
     * triggers another flush is exactly where this shape spins, so the flushes are COUNTED rather
     * than reasoned about: a cascade that grows by one pass per round trip shows up here as a number
     * climbing, long before it becomes a hang somebody has to interrupt. One shipment and one
     * shipment line say the same thing from the other side — a second auto-shipment would mean the
     * remainder listener disagreed with this one about what was left.
     */
    public function testShippingEverythingCompletesOnceAndGeneratesNoSecondShipment(): void
    {
        $line = $this->processingInvoiceLine('AC-NO-LOOP-SHIP', '4.00');

        $flushes = $this->countFlushes();
        $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '4.00'));

        self::assertSame('Completed', $this->statusColumn($line->getInvoice()));
        self::assertCount(1, $this->em->getRepository(Shipment::class)->findAll());
        self::assertCount(1, $this->em->getRepository(ShipmentLine::class)->findBy(['invoiceLine' => $line]));
        $this->assertChainSettled($flushes, 'ship -> complete -> remainder');
    }

    /**
     * The mirror direction, which existed before this change and must still terminate: Completed by
     * hand -> the remainder listener auto-ships the lot -> those inserts reach this listener -> the
     * invoice is ALREADY Completed, so it stops before the deriver is even asked.
     *
     * That first check is load-bearing rather than an optimisation: `setStatus('Completed')` on an
     * invoice that is already Completed THROWS, because the gate runs the document's rules before
     * its no-op check on purpose. Without it this direction would not merely loop, it would fail
     * loudly on the second pass.
     */
    public function testCompletingByHandAutoShipsTheRemainderAndStopsThere(): void
    {
        $line = $this->processingInvoiceLine('AC-NO-LOOP-COMPLETE', '4.00');

        $flushes = $this->countFlushes();
        $line->getInvoice()->setStatus('Completed', DocumentActor::system());
        $this->em->flush();

        self::assertSame('Completed', $this->statusColumn($line->getInvoice()));
        self::assertCount(1, $this->em->getRepository(Shipment::class)->findAll(), 'the auto-shipment, and only it');
        self::assertCount(1, $this->em->getRepository(ShipmentLine::class)->findBy(['invoiceLine' => $line]));
        $this->assertChainSettled($flushes, 'complete -> remainder -> here');
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────────

    /**
     * Generous on purpose. The settled chain is a handful of passes — the outer write, this
     * listener's own flush, and each other postFlush listener's — and the failure being guarded
     * against is unbounded, not off-by-one. A ceiling tight enough to argue about would fail on an
     * unrelated listener being added and teach the next person to raise it without reading why.
     */
    private const FLUSH_CEILING = 25;

    /**
     * The flush cascade $flushes was watching both HAPPENED and stopped.
     *
     * Two bounds, because one alone proves nothing. Without the lower one a counter that was never
     * called at all — a listener unregistered, a fixture that flushed nothing — passes the ceiling
     * vacuously and the test reads green while measuring silence. It settles at six today.
     */
    private function assertChainSettled(object $flushes, string $chain): void
    {
        self::assertGreaterThan(1, $flushes->count, sprintf('%s: the cascade did not run at all', $chain));
        self::assertLessThan(self::FLUSH_CEILING, $flushes->count, sprintf('%s: the cascade did not settle', $chain));
    }

    /**
     * Counts every `postFlush` from now until the test ends.
     *
     * Registered straight on the EntityManager's event manager rather than in the container, so it
     * sees exactly the passes the real listeners cause and nothing about the wiring changes for
     * them. The counter is returned rather than read back off this class, so a test says in one line
     * which window it is measuring.
     */
    private function countFlushes(): object
    {
        $counter = new class {
            public int $count = 0;

            public function postFlush(): void
            {
                $this->count++;
            }
        };

        $this->em->getEventManager()->addEventListener([Events::postFlush], $counter);

        return $counter;
    }

    /** `invoice.status` as the DATABASE holds it. */
    private function statusColumn(Invoice $invoice): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM invoice WHERE id = ?', [$invoice->getId()]);
    }

    /** `invoice.shipping_status` as the DATABASE holds it. */
    private function shippingStatusColumn(Invoice $invoice): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT shipping_status FROM invoice WHERE id = ?', [$invoice->getId()]);
    }

    /**
     * One `ShipmentLine` of $quantity against $line, written without going through ShipmentService.
     *
     * Only for the nothing-billable case, where `ship()` correctly refuses a zero-quantity line and
     * so could never queue the invoice at all. Same escape hatch, and same one-case-only rule, as
     * `ShippedQuantityProviderTest::recordShipmentLineDirectly()`.
     */
    private function recordShipmentLineDirectly(InvoiceLine $line, string $quantity): void
    {
        $shipment = (new Shipment())
            ->setCompany($this->company)
            ->setShipmentNumber('SHP-DIRECT-' . uniqid());
        $shipment->addLine(
            (new ShipmentLine())
                ->setInvoiceLine($line)
                ->setProduct($line->getProduct())
                ->setName($line->getName())
                ->setSku($line->getSku())
                ->setQuantity($quantity),
        );

        $this->em->persist($shipment);
        $this->em->flush();
    }

    /** An invoice line on an invoice walked to Processing — the one state Completed is reachable from. */
    private function processingInvoiceLine(string $sku, string $quantity): InvoiceLine
    {
        $line = $this->invoiceLine($sku, $quantity);

        $line->getInvoice()->issue(DocumentActor::system());
        $this->em->flush();
        $line->getInvoice()->startProcessing(DocumentActor::system());
        $this->em->flush();

        return $line;
    }

    /** A second line on an invoice that already exists, so "every line, not the totals" has two to cover. */
    private function addLineTo(Invoice $invoice, string $sku, string $quantity): InvoiceLine
    {
        $product = (new ProductCore())->setSku($sku)->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);

        $line = (new InvoiceLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku($sku)
            ->setQuantity($quantity);
        $invoice->addLine($line);
        $this->em->flush();

        return $line;
    }

    /** One approved order, one Draft invoice for it, one line of $quantity. */
    private function invoiceLine(string $sku, string $quantity): InvoiceLine
    {
        $product = (new ProductCore())->setSku($sku)->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('AC-ORD-' . uniqid())
            ->setDocumentDate('2026-09-17')
            ->setFulfillmentRegion($this->regionName);
        $this->em->persist($order);

        $orderLine = (new SalesOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setQuantity($quantity)
            ->setLocation($this->regionName);
        $order->addLine($orderLine);
        $this->em->flush();

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->flush();

        $invoice = (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber('AC-INV-' . uniqid())
            ->setDocumentDate('2026-09-17')
            ->setFulfillmentRegion($this->regionName);
        $order->addInvoice($invoice);

        $invoiceLine = (new InvoiceLine())
            ->setSalesOrderLine($orderLine)
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku($sku)
            ->setQuantity($quantity);
        $invoice->addLine($invoiceLine);
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoiceLine;
    }
}
