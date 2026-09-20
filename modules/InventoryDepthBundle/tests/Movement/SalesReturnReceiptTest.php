<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Movement;

use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\CreditMemoLine;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\SalesReturn;
use App\Entity\SalesReturnLine;
use App\Entity\Warehouse;
use App\Event\CreditMemoIssuedEvent;
use App\Event\SalesReturnReceivedEvent;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Goods arriving back against an authorised RMA, and the one column that must not move when they do
 * (#596).
 *
 * ## The arithmetic under test, written out
 *
 *     receive 10      received 10  approved 0  quarantine 0   available 10
 *     sell + ship 2   received 10  approved 2  quarantine 0   available  8
 *     return 2        received 10  approved 2  quarantine 2   available  6
 *     credit applied  received 10  approved 0  quarantine 2   available  8   correct
 *
 * Ten physical units, two held pending inspection, eight sellable — and `received_quantity` never
 * moves. It must not, because the shipment never decremented it either: the invoice's `approved`
 * hold absorbed the departure, and this app has no mechanism that decrements Starting Inventory on
 * shipment. Crediting `received` on the way back would count the same units as arriving twice.
 *
 * The assertions below are on the COLUMNS, not on availability. Availability is a subtraction over
 * six buckets and it can come out right with two of them wrong in opposite directions, which is
 * exactly the failure that would be invisible if this test only checked the total. Asserting
 * "availability is 8" is the cheap version of this test and it does not catch the bug it is named
 * for.
 *
 * ## Where this differs from CreditMemoRestockTest, which it otherwise mirrors
 *
 * #586 could only reach the third row of that table by ISSUING A CREDIT NOTE, so the goods coming
 * back and the money going back were one act and the middle row was never observed. That is the
 * defect #596 exists to fix, and it is why this test can do something the other one could not:
 * receive the goods, assert the middle row, and only then credit.
 *
 * The final case here is therefore the one #586 had no way to express at all — a return received in
 * one step and refused in another, leaving the units where they are.
 */
final class SalesReturnReceiptTest extends DoctrineIntegrationTestCase
{
    private StockMovementService $movements;
    private EventDispatcherInterface $events;
    private FulfillmentRegion $region;
    private Warehouse $warehouse;
    private ProductCore $product;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->movements = self::getContainer()->get(StockMovementService::class);
        $this->events = self::getContainer()->get(EventDispatcherInterface::class);

        $this->region = (new FulfillmentRegion())->setName('Returns Region');
        $this->em->persist($this->region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($this->region, 'BC', 'CA');

        $this->company = (new Company())->setName('Returning Buyer Ltd');
        $this->em->persist($this->company);

        $this->product = (new ProductCore())
            ->setSku('RMA-DIM-1')
            ->setName('Returnable Thing')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);
        $this->em->flush();

        // Ten on the shelf, booked the way stock actually arrives.
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'rma-seed')
                ->receive($this->product, new DetailKey($this->warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE), 10)
        );
        $this->em->flush();
    }

    /**
     * THE HEADLINE. Receiving an RMA writes `returned` rows and leaves `received_quantity` alone.
     *
     * `received_quantity` is asserted at every step, not only at the end, so a failure says WHICH
     * crossing moved it.
     */
    public function testReceivingAnAuthorisedReturnWritesReturnedRowsAndLeavesReceivedQuantityUntouched(): void
    {
        $before = $this->inventory();
        self::assertSame('10.0000', $before->getReceivedQuantity(), 'guard: ten arrived');
        self::assertSame('0.0000', $before->getQuarantineQuantity(), 'guard: nothing is in quarantine yet');
        self::assertSame('10.0000', $before->getAvailableQuantity(), 'guard: all ten are sellable');

        $invoice = $this->processingInvoiceFor('2.00');
        $this->ship($invoice, 2);

        $afterSale = $this->inventory();
        self::assertSame('10.0000', $afterSale->getReceivedQuantity(), 'shipping is not an arrival and not a write-off — received is untouched by the sale');
        self::assertSame('2.0000', $afterSale->getApprovedQuantity(), 'the invoice holds the two it billed');
        self::assertSame('8.0000', $afterSale->getAvailableQuantity(), 'eight left to sell');

        // The customer asks to send them back. NOTHING moves yet, and that is the whole point of
        // the document existing separately from the credit note.
        $return = $this->requestedReturnFor($invoice, 2);
        $this->em->flush();

        self::assertSame(
            0,
            $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED),
            'a REQUESTED return has moved nothing: nobody has agreed and no parcel has been sent',
        );

        $return->authorise();
        $this->em->flush();

        self::assertSame(
            0,
            $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED),
            'and an AUTHORISED one has still moved nothing — the customer has a number to write on a box, that is all',
        );

        // The parcel arrives.
        $this->receive($return);

        $afterReturn = $this->inventory();

        $report = sprintf(
            "\n  received   : %d"
            . "\n  quarantine : %d"
            . "\n  approved   : %d"
            . "\n  write_off  : %d"
            . "\n  quantity   : %d"
            . "\n  available  : %d"
            . "\n  returned detail rows : %d\n",
            $afterReturn->getReceivedQuantity(),
            $afterReturn->getQuarantineQuantity(),
            $afterReturn->getApprovedQuantity(),
            $afterReturn->getWriteOffQuantity(),
            $afterReturn->getQuantity(),
            $afterReturn->getAvailableQuantity(),
            $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED),
        );

        // THE COLUMN THIS TEST EXISTS FOR.
        self::assertSame(
            '10.0000',
            $afterReturn->getReceivedQuantity(),
            'received_quantity MUST NOT MOVE when an RMA is received: the shipment never decremented it, so adding '
            . 'on the way back counts those units as arriving twice' . $report,
        );

        self::assertSame(
            2,
            $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED),
            'the receipt wrote `returned` detail rows, so a returned unit stays distinguishable from one that failed '
            . 'inspection' . $report,
        );
        self::assertSame(
            '2.0000',
            $afterReturn->getQuarantineQuantity(),
            'and they sum into quarantine — present in the building, not sellable until somebody rules on them' . $report,
        );
        self::assertSame(
            8,
            $this->unitsWithStatus(InventoryDetail::STATUS_AVAILABLE),
            'the eight still on the shelf were not touched: returned goods do not go straight back into the sellable pool' . $report,
        );

        // THE MIDDLE ROW OF THE TABLE, which #586 could never observe because issuing the note did
        // both halves at once. The invoice is still holding its two, because nobody has credited
        // anything yet — the goods are back and the money is not.
        self::assertSame(
            '2.0000',
            $afterReturn->getApprovedQuantity(),
            'the invoice STILL holds its two: receiving goods is not crediting them, which is the entire reason the '
            . 'RMA and the credit note are separate documents' . $report,
        );
        self::assertSame(
            '6.0000',
            $afterReturn->getAvailableQuantity(),
            'six sellable — ten physical, two held by the invoice, two held pending inspection' . $report,
        );

        self::assertSame('Received', $return->getStatus()->value);
        self::assertInstanceOf(\DateTimeImmutable::class, $return->getReceivedAt(), 'and the receipt is stamped on the document');
    }

    /**
     * The fourth row of the table: the credit note is raised afterwards, in its own act, and
     * releases the hold without touching the goods again.
     *
     * This is the case #596 lists as inexpressible before it — "goods arrive in one period and the
     * credit is issued in the next" — reduced to two method calls with a flush between them.
     */
    public function testCreditingAfterTheGoodsHaveArrivedReleasesTheHoldAndMovesNoStock(): void
    {
        $invoice = $this->processingInvoiceFor('2.00');
        $this->ship($invoice, 2);

        $return = $this->requestedReturnFor($invoice, 2);
        $return->authorise();
        $this->em->flush();
        $this->receive($return);

        self::assertSame(2, $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED), 'guard: the goods are back');
        self::assertSame('2.0000', $this->inventory()->getApprovedQuantity(), 'guard: and the money has not moved');

        // Now the money, on its own day. The note carries the return, so it may not restock — and
        // does not need to, because the receipt already put the goods back.
        $memo = $this->creditNoteFor($invoice, 2, $return);
        $memo->issue();
        $this->em->flush();
        $this->events->dispatch(new CreditMemoIssuedEvent($memo));
        $this->em->flush();

        $after = $this->inventory();

        self::assertSame(
            2,
            $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED),
            'STILL two returned units, not four: the note carries the RMA, so it refused to restock and the receipt '
            . 'remains the only thing that brought these goods into the ledger',
        );
        self::assertSame('2.0000', $after->getQuarantineQuantity(), 'so quarantine did not double either');
        self::assertSame('10.0000', $after->getReceivedQuantity(), 'and received is untouched on this path as on every other');
        self::assertSame(
            '0.0000',
            $after->getApprovedQuantity(),
            'the invoice has stopped holding the units that were credited — that is what the note did',
        );
        self::assertSame('8.0000', $after->getAvailableQuantity(), 'ten physical, two held pending inspection, eight sellable');
    }

    /**
     * A credit note carrying a `sales_return_id` REFUSES to restock, and the refusal is what keeps
     * quarantine from doubling.
     *
     * Written so that removing CreditMemo::assertRestockAndReturnAreExclusive() fails it TWICE and
     * for the right reasons: the flag would be accepted (first assertion) and the subscriber would
     * then book the same two units a second time (last assertion). A bare expectException() would
     * only prove the throw, which is the half that is easy to keep and easy to make vacuous.
     */
    public function testACreditNoteCarryingAReturnRefusesToRestockSoTheUnitsDoNotComeBackTwice(): void
    {
        $invoice = $this->processingInvoiceFor('2.00');
        $this->ship($invoice, 2);

        $return = $this->requestedReturnFor($invoice, 2);
        $return->authorise();
        $this->em->flush();
        $this->receive($return);

        self::assertSame(2, $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED), 'guard: the RMA brought two units in');

        $memo = $this->creditNoteFor($invoice, 2, $return);

        // Exactly what the admin screen would do if its POST carried restock=1 against a note that
        // names a return: setSalesReturn() has already run, so setRestock(true) meets the guard.
        $refused = false;
        try {
            $memo->setRestock(true);
        } catch (\DomainException $e) {
            $refused = true;
            self::assertStringContainsString(
                $return->getDocumentNumber(),
                $e->getMessage(),
                'the refusal names the document that already owns the goods, so an admin knows what to do about it',
            );
        }

        self::assertTrue(
            $refused,
            'a credit note carrying a sales_return_id MUST REFUSE to restock. Accepting the flag lets the same '
            . 'physical units enter the ledger twice — once from the RMA receipt, once from the note — with no row '
            . 'anywhere saying which half was the mistake',
        );

        // Issue it anyway, in whatever state it reached, and dispatch exactly as the controller
        // does. With the guard in place the note is financial-only and nothing moves; without it the
        // subscriber books two more units here.
        $memo->issue();
        $this->em->flush();
        $this->events->dispatch(new CreditMemoIssuedEvent($memo));
        $this->em->flush();

        self::assertSame(
            2,
            $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED),
            'the units came back ONCE. Four here would be the double-restock: two from the RMA receipt and two more '
            . 'from a note that should have refused',
        );
        self::assertSame(
            '2.0000',
            $this->inventory()->getQuarantineQuantity(),
            'and quarantine reads two rather than four, which is the figure a warehouse manager actually sees',
        );
        self::assertSame('10.0000', $this->inventory()->getReceivedQuantity(), 'received is still untouched');
    }

    /**
     * #586's behaviour must survive: a STANDALONE credit note with no `sales_return_id` still
     * restocks.
     *
     * #596 is explicit that `restock` is not removed. Goodwill credits and credits where the goods
     * are not worth the freight to ship back are the case it exists for, and a fix that quietly
     * disabled it would have broken the ordinary standalone credit while passing every test about
     * returns.
     */
    public function testAStandaloneCreditNoteWithNoReturnStillRestocks(): void
    {
        $invoice = $this->processingInvoiceFor('2.00');
        $this->ship($invoice, 2);

        $memo = $this->creditNoteFor($invoice, 2, null);
        $memo->setRestock(true);

        self::assertTrue($memo->isRestock(), 'with no return attached the flag is accepted exactly as it was in #586');

        $memo->issue();
        $this->em->flush();
        $this->events->dispatch(new CreditMemoIssuedEvent($memo));
        $this->em->flush();

        self::assertSame(
            2,
            $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED),
            'CreditMemoRestockSubscriber still puts the goods back for a note that owns them — #596 changed '
            . 'ownership, not the mechanism',
        );
        self::assertSame('2.0000', $this->inventory()->getQuarantineQuantity(), 'and they sit in quarantine like any other return');
        self::assertSame('10.0000', $this->inventory()->getReceivedQuantity(), 'received is untouched here too');
    }

    /**
     * A return received and then DECLINED leaves the units exactly where they are, and invents no
     * disposition for them.
     *
     * This is the uncomfortable state and it is the correct one. The goods are in the building,
     * counted in quarantine, not sellable and not the customer's to have back for free. What happens
     * to them — shipped back at the customer's cost, scrapped, sold as B-stock — is a commercial
     * decision that a status change must not make on somebody's behalf.
     *
     * Both halves are asserted, because either one alone would pass while the other was wrong: stock
     * unchanged says nothing about whether a line was quietly marked `scrap`, and dispositions
     * staying null says nothing about whether the rows were moved.
     */
    public function testDecliningAfterReceiptLeavesTheUnitsInQuarantineAndInventsNoDisposition(): void
    {
        $invoice = $this->processingInvoiceFor('2.00');
        $this->ship($invoice, 2);

        $return = $this->requestedReturnFor($invoice, 2);
        $return->authorise();
        $this->em->flush();
        $this->receive($return);

        $beforeDecline = $this->inventory();
        self::assertSame(2, $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED), 'guard: the goods arrived');
        self::assertSame('2.0000', $beforeDecline->getQuarantineQuantity(), 'guard: and are held pending a ruling');

        $return->decline('Out of warranty — the fault is customer damage.');
        $this->em->flush();

        $after = $this->inventory();

        self::assertSame('Declined', $return->getStatus()->value);
        self::assertSame(
            2,
            $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED),
            'declining moved nothing. The goods are physically in the building whether or not we agree to credit '
            . 'them, and a status change that scrapped or restocked them would be destroying or reselling stock on '
            . 'the strength of a paperwork decision',
        );
        self::assertSame('2.0000', $after->getQuarantineQuantity(), 'so they stay in quarantine — counted, and not sellable');
        self::assertSame(
            '0.0000',
            $after->getWriteOffQuantity(),
            'and nothing was written off: refusing to credit a return is not the same as deciding the goods are worthless',
        );
        self::assertSame('10.0000', $after->getReceivedQuantity(), 'received is untouched by a decline, as by everything else here');

        foreach ($return->getLines() as $line) {
            self::assertNull(
                $line->getDisposition(),
                'NO disposition was invented. Writing one here would claim somebody had inspected the goods and '
                . 'reached a conclusion, which is exactly what has not happened',
            );
        }

        self::assertSame(
            '2.0000',
            $return->strandedUnits(),
            'the document reports the stranded quantity so the detail screen can surface it — units that are correct '
            . 'and invisible are how stock rots in a bucket nobody reports on',
        );
        self::assertStringContainsString(
            'Out of warranty',
            (string) $return->getNotes(),
            'and the reason is on the document, appended rather than replacing whatever explained the authorisation',
        );
    }

    /** Receiving twice — a retried request, a listener run twice — must not book the goods twice. */
    public function testReceivingIsIdempotentOnTheReturnsIdentity(): void
    {
        $invoice = $this->processingInvoiceFor('2.00');
        $return = $this->requestedReturnFor($invoice, 2);
        $return->authorise();
        $this->em->flush();
        $this->receive($return);

        self::assertSame(2, $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED), 'guard: two came back');

        $this->events->dispatch(new SalesReturnReceivedEvent($return));
        $this->em->flush();

        self::assertSame(
            2,
            $this->unitsWithStatus(InventoryDetail::STATUS_RETURNED),
            'a re-dispatch finds the existing movement group by the return\'s own operation id and applies nothing',
        );
        self::assertSame('10.0000', $this->inventory()->getReceivedQuantity(), 'and nothing leaks into received on the second pass either');
    }

    /**
     * The movement group carries the RMA number, which is how a warehouse actually finds it.
     *
     * Not decoration. When a box turns up with RMA-7 on the label and somebody asks the ledger what
     * happened to it, the reference is the only thing on the movement that says so — the group's
     * TYPE is `receipt`, indistinguishable from a purchase receipt, because adding a `return` type
     * would change InventoryMovementGroup::types() and every existing consumer of that list for a
     * label.
     */
    public function testTheMovementGroupNamesTheReturnItCameFrom(): void
    {
        $invoice = $this->processingInvoiceFor('2.00');
        $return = $this->requestedReturnFor($invoice, 2);
        $return->authorise();
        $this->em->flush();
        $this->receive($return);

        $group = $this->em->getRepository(InventoryMovementGroup::class)
            ->findOneBy(['clientOperationId' => 'sales-return-receipt-' . (string) $return->getId()]);

        self::assertInstanceOf(InventoryMovementGroup::class, $group, 'the receipt wrote one group, keyed on the return');
        self::assertSame($return->getDocumentNumber(), $group->getReference(), 'and the group names the RMA a searcher would type');
        self::assertSame(InventoryMovementGroup::TYPE_RECEIPT, $group->getType(), 'a return IS goods arriving');
    }

    /*
     * ------------------------------------------------------------------------------------------
     * Fixtures
     * ------------------------------------------------------------------------------------------
     */

    /** Received the way the controller receives: transition, flush, THEN dispatch. */
    private function receive(SalesReturn $return): void
    {
        $return->receive($this->warehouse);
        $this->em->flush();

        $this->events->dispatch(new SalesReturnReceivedEvent($return));
        $this->em->flush();
    }

    private function requestedReturnFor(Invoice $invoice, int $units): SalesReturn
    {
        $return = (new SalesReturn())
            ->setCompany($this->company)
            ->setDocumentNumber('RMA-' . uniqid())
            ->setInvoice($invoice)
            ->setReason('Arrived damaged.');

        $return->addLine(
            (new SalesReturnLine())
                ->setProduct($this->product)
                ->setInvoiceLine($invoice->getLines()->first())
                ->setName($this->product->getName())
                ->setSku($this->product->getSku())
                ->setQuantity(number_format($units, 2, '.', '')),
        );

        $this->em->persist($return);

        return $return;
    }

    private function creditNoteFor(Invoice $invoice, int $units, ?SalesReturn $return): CreditMemo
    {
        $memo = (new CreditMemo())
            ->setCompany($this->company)
            ->setDocumentNumber('CN-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setInvoice($invoice)
            ->setSalesReturn($return)
            ->setFulfillmentRegion($this->region->getName())
            ->setSubtotal('10.00')
            ->setTax('0.00')
            ->setTotal('10.00');

        $memo->addLine(
            (new CreditMemoLine())
                ->setInvoiceLine($invoice->getLines()->first())
                ->setProduct($this->product)
                ->setName($this->product->getName())
                ->setLocation($this->region->getName())
                ->setQuantity(number_format($units, 2, '.', ''))
                ->setPrice('5.00')
                ->setSubtotal(number_format($units * 5, 2, '.', '')),
        );

        $this->em->persist($memo);

        return $memo;
    }

    private function processingInvoiceFor(string $quantity): Invoice
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('RMA-ORD-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setFulfillmentRegion($this->region->getName());

        $orderLine = (new SalesOrderLine())
            ->setProduct($this->product)
            ->setName($this->product->getName())
            ->setQuantity($quantity)
            ->setLocation($this->region->getName());
        $order->addLine($orderLine);
        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->persist($order);
        $this->em->flush();

        $invoice = (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber('RMA-INV-' . uniqid())
            ->setDocumentDate('2026-09-01')
            ->setFulfillmentRegion($this->region->getName())
            ->setSubtotal('20.00')
            ->setTax('0.00')
            ->setTotal('20.00');
        $order->addInvoice($invoice);
        $invoice->addLine(
            (new InvoiceLine())
                ->setSalesOrderLine($orderLine)
                ->setProduct($this->product)
                ->setName($this->product->getName())
                ->setQuantity($quantity)
                ->setLocation($this->region->getName()),
        );
        $this->em->persist($invoice);
        $this->em->flush();

        $invoice->issue(DocumentActor::system());
        $this->em->flush();
        $invoice->startProcessing(DocumentActor::system());
        $this->em->flush();

        return $invoice;
    }

    /**
     * The warehouse dispatching what the invoice billed: `available → sold`, in the same warehouse.
     *
     * Nothing in the invoice lifecycle does this by itself — StockMovementService says so, and #552
     * is where it would be wired — so the test conducts it, exactly as CreditMemoRestockTest and
     * SellingADimensionalProductTest do. Without it the detail rows would say twelve for ten
     * physical units, and every figure below would still be arithmetically "right" while describing
     * a warehouse that does not exist.
     */
    private function ship(Invoice $invoice, int $units): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_SHIP, 'ship-' . $invoice->getId(), null, null, $invoice->getDocumentNumber())
                ->move(
                    $this->product,
                    new DetailKey($this->warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE),
                    new DetailKey($this->warehouse, null, null, null, InventoryDetail::STATUS_SOLD),
                    $units,
                )
        );
        $this->em->flush();
    }

    private function inventory(): ProductInventory
    {
        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $this->product->getId(),
            'warehouse' => $this->warehouse->getId(),
        ]);
        self::assertInstanceOf(ProductInventory::class, $row);

        // Refreshed rather than the identity map's copy: these buckets are written by the movement
        // service and by the reservation reconciler, so an in-memory instance can be behind the row.
        $this->em->refresh($row);

        return $row;
    }

    private function unitsWithStatus(string $status): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM inventory_detail WHERE product_id = ? AND status = ?',
            [$this->product->getId(), $status],
        );
    }
}
