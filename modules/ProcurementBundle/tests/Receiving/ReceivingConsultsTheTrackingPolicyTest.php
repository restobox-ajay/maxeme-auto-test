<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Receiving;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use App\Entity\Warehouse;
use InventoryDepthBundle\Entity\WarehouseLocation;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Receiving\ReceivingRequest;
use ProcurementBundle\Receiving\ReceivingService;

/**
 * Receiving consults the product's declared tracking policy (#573).
 *
 * **A pallet arriving with no code on it is still booked in, once somebody SAYS so** (item 67). The
 * row takes the policy's `sentinel_in`, is flagged `expect_resolution` and lands on the worklist. A
 * warehouse mid-transition has real stock and no codes for any of it, and refusing those deliveries
 * makes the system unusable on exactly the day they need it.
 *
 * What changed in item 67 is only how that row is reached. #573 wrote "tracking never blocks", and
 * the consequence was that a blank identity box and a genuinely unidentified pallet were the same
 * submission — so the typed form never asked for a serial, the scan console refused to, and the only
 * way to get serialised stock onto the shelf was to leave the box empty. Both screens ask now, so
 * the two cases are distinguishable, and `unidentified: true` is the receiver saying which one this
 * is. The ROW is identical in every respect; `aBlankIdentityNobodyDeclaredIsRefused()` below is the
 * other half of the same rule.
 *
 * **A sentinel is a label on the unidentified row, and it behaves the same in both modes.** Lot or
 * serial, blank or a value: one row, quantity N, `expect_resolution` set. The only difference
 * between the pairs below is what the column reads.
 *
 * The last test is the one that keeps the ticket's acceptance criterion honest: a product nobody
 * opted in must come out of receiving byte-identically to how it did before any of this existed.
 */
final class ReceivingConsultsTheTrackingPolicyTest extends DoctrineIntegrationTestCase
{
    private ReceivingService $receiving;
    private Warehouse $warehouse;
    private Vendor $vendor;
    private ProductCore $product;
    private WarehouseLocation $bin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receiving = self::getContainer()->get(ReceivingService::class);

        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->vendor = (new Vendor())->setName('Acme Supply')->setCurrency('CAD');
        $this->em->persist($this->vendor);

        $this->product = (new ProductCore())
            ->setSku('TRK-1')
            ->setName('Tracked Thing')
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);

        $this->bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('A-01');
        $this->em->persist($this->bin);
        $this->em->flush();
    }

    public function testAnUnknownBatchTakesTheSentinelAndIsBookedInAnyway(): void
    {
        $this->putOnPolicy(TrackingPolicy::MODE_LOT, '[PENDING]');
        $order = $this->issuedOrder('6.00');

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)
                ->add($this->product, '6.00', $order->getLines()->first(), null, null, null, null, null, true),
        );

        self::assertSame('[PENDING]', $receipt->getLines()->first()->getLot()?->getCode());

        $row = $this->detailRow();
        self::assertSame(6, (int) $row['quantity'], 'the delivery is booked in — tracking never blocks');
        self::assertSame(1, (int) $row['expect_resolution'], 'and lands on the worklist instead');
    }

    /** A blank sentinel is a legitimate configuration: no batch on the row, still flagged. */
    public function testABlankSentinelLeavesTheBatchOffAndStillFlagsTheRow(): void
    {
        $this->putOnPolicy(TrackingPolicy::MODE_LOT, null);
        $order = $this->issuedOrder('6.00');

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)
                ->add($this->product, '6.00', $order->getLines()->first(), null, null, null, null, null, true),
        );

        self::assertNull($receipt->getLines()->first()->getLot());

        $row = $this->detailRow();
        self::assertNull($row['lot_id']);
        self::assertSame(1, (int) $row['expect_resolution']);
    }

    /**
     * Unidentified serialised units take the policy's sentinel, exactly as an unknown batch does.
     *
     * Four arriving together are ONE row of four wearing the label, not four rows and not
     * `PENDING-1`…`PENDING-4`. A sentinel is a label on the unidentified row; it is not a serial
     * number, so the quantity-1 ceiling — which is about genuine serials, each naming one physical
     * unit — does not apply to it.
     */
    public function testUnidentifiedSerialisedUnitsTakeTheSentinelOnOneRow(): void
    {
        $this->putOnPolicy(TrackingPolicy::MODE_SERIAL, '[PENDING]');
        $order = $this->issuedOrder('4.00');

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)
                ->add($this->product, '4.00', $order->getLines()->first(), null, null, null, null, null, true),
        );

        self::assertSame('[PENDING]', $receipt->getLines()->first()->getSerial());

        $row = $this->detailRow();
        self::assertSame('[PENDING]', $row['serial']);
        self::assertSame(4, (int) $row['quantity'], 'one row of four — a label identifies no unit');
        self::assertSame(1, (int) $row['expect_resolution']);
    }

    /** The same delivery under a blank sentinel: the identical row, with the label unset. */
    public function testABlankSerialSentinelLeavesTheDimensionNullOnTheSameSingleRow(): void
    {
        $this->putOnPolicy(TrackingPolicy::MODE_SERIAL, null);
        $order = $this->issuedOrder('4.00');

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)
                ->add($this->product, '4.00', $order->getLines()->first(), null, null, null, null, null, true),
        );

        self::assertNull($receipt->getLines()->first()->getSerial());

        $row = $this->detailRow();
        self::assertNull($row['serial']);
        self::assertSame(4, (int) $row['quantity']);
        self::assertSame(1, (int) $row['expect_resolution']);
    }

    /**
     * The other half of the sentinel rule: a blank identity NOBODY declared is refused (item 67).
     *
     * This is the gate that did not exist, and its absence is why the gap was invisible — a receiver
     * who never saw the serial on the carton and a warehouse that genuinely has no codes produced
     * the same row, so neither screen had any reason to ask. The refusal names the product and says
     * what to do about it, both ways.
     */
    public function testABlankIdentityNobodyDeclaredIsRefused(): void
    {
        $this->putOnPolicy(TrackingPolicy::MODE_SERIAL, '[PENDING]');
        $order = $this->issuedOrder('4.00');

        $this->expectException(\ProcurementBundle\Receiving\ReceivingException::class);
        $this->expectExceptionMessageMatches('/is serialised and no serial was entered/');

        $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '4.00', $order->getLines()->first()),
        );
    }

    /** The same, in lot mode: the two dimensions are one rule wearing two nouns. */
    public function testABlankBatchNobodyDeclaredIsRefused(): void
    {
        $this->putOnPolicy(TrackingPolicy::MODE_LOT, '[PENDING]');
        $order = $this->issuedOrder('6.00');

        $this->expectException(\ProcurementBundle\Receiving\ReceivingException::class);
        $this->expectExceptionMessageMatches('/is lot-tracked and no batch code was entered/');

        $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '6.00', $order->getLines()->first()),
        );
    }

    /**
     * A policy in lot mode with BOTH directions unticked captures nothing, so it demands nothing.
     *
     * `TrackingPolicy::isInert()` says so and the new-policy form allows the combination — the
     * walkthrough called it "None wearing another name". This pins the receiving half: no batch is
     * demanded and no sentinel is substituted, because there is nothing pending.
     */
    public function testAPolicyThatCapturesNothingInboundDemandsNothing(): void
    {
        $this->putOnInertLotPolicy(requiresExpiry: false);
        $order = $this->issuedOrder('6.00');

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '6.00', $order->getLines()->first()),
        );

        self::assertNull($receipt->getLines()->first()->getLot(), 'nothing is captured, so nothing is substituted either');

        $row = $this->detailRow();
        self::assertSame(6, (int) $row['quantity']);
        self::assertSame(0, (int) $row['expect_resolution'], 'and it is not on the worklist, because nothing is pending');
    }

    /**
     * THE SAME POLICY WITH `requires_expiry` TICKED DEMANDS A DATE WITH NO BATCH TO PUT IT ON.
     *
     * Pinned rather than fixed, and flagged loudly rather than left to be discovered. The ruled
     * mapping is `isExpiryRequired() -> requiresExpiry()` (#795: expiry is its own Yes/No and says
     * nothing about mode or direction) — so a policy that captures no batch inbound still refuses a
     * delivery for want of an expiry date. The receipt form renders an expiry box for exactly this
     * case so the demand is at least answerable.
     *
     * The one-line correction, if this should be unreachable, is
     * `tracksLotsInbound() && requiresExpiry()` in ProductReceivingRule::isExpiryRequired().
     * The other correction is on the policy FORM, which should not offer a lot policy with both
     * directions unticked at all; that screen is core and behind the freeze.
     */
    public function testAnInertLotPolicyThatRequiresExpiryStillDemandsOne(): void
    {
        $this->putOnInertLotPolicy(requiresExpiry: true);
        $order = $this->issuedOrder('6.00');

        $this->expectException(\ProcurementBundle\Receiving\ReceivingException::class);
        $this->expectExceptionMessageMatches('/needs an expiry date\./');

        $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '6.00', $order->getLines()->first()),
        );
    }

    private function putOnInertLotPolicy(bool $requiresExpiry): void
    {
        $policy = (new TrackingPolicy())
            ->setName('Lot, neither direction ' . uniqid())
            ->setMode(TrackingPolicy::MODE_LOT)
            ->setRequiresExpiry($requiresExpiry)
            ->setTrackIn(false)
            ->setTrackOut(false);
        $this->em->persist($policy);
        $this->product->setTrackingPolicy($policy);
        $this->em->flush();
    }

    /**
     * A policy that says the batch must carry an expiry refuses a batch that arrived without one.
     *
     * The walkthrough's finding (5): a policy flagged "the batch must carry an expiry date" produced
     * a batch with NO expiry, and the Lots screen then printed "Does not expire" about it. Nothing
     * consulted `requiresExpiry()` at receiving — only the bundle's own `expiry_required` rule
     * flag, which is a different row that nobody had set.
     */
    public function testAPolicyRequiringExpiryRefusesABatchWithoutOne(): void
    {
        $policy = (new TrackingPolicy())
            ->setName('Lot + expiry ' . uniqid())
            ->setMode(TrackingPolicy::MODE_LOT)
            ->setRequiresExpiry(true)
            ->setTrackIn(true);
        $this->em->persist($policy);
        $this->product->setTrackingPolicy($policy);
        $this->em->flush();

        $order = $this->issuedOrder('6.00');

        $this->expectException(\ProcurementBundle\Receiving\ReceivingException::class);
        $this->expectExceptionMessageMatches('/needs an expiry date\./');

        $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)
                ->add($this->product, '6.00', $order->getLines()->first(), 'LOT-NO-DATE'),
        );
    }

    /** A batch that WAS typed is not a placeholder, so nothing is flagged. */
    public function testATypedBatchIsNotFlagged(): void
    {
        $this->putOnPolicy(TrackingPolicy::MODE_LOT, '[PENDING]');
        $order = $this->issuedOrder('6.00');

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)->add($this->product, '6.00', $order->getLines()->first(), 'RUN-42'),
        );

        self::assertSame('RUN-42', $receipt->getLines()->first()->getLot()?->getCode());

        $row = $this->detailRow();
        self::assertSame(0, (int) $row['expect_resolution']);
    }

    /**
     * The acceptance criterion. A product on no policy is received exactly as it always was.
     */
    public function testAProductOnNoPolicyIsUntouchedByAnyOfThis(): void
    {
        $order = $this->issuedOrder('6.00');

        $receipt = $this->receiving->receive(
            ReceivingRequest::againstPurchaseOrder($order)
                ->add($this->product, '6.00', $order->getLines()->first(), null, null, null, null, null, true),
        );

        self::assertNull($receipt->getLines()->first()->getLot());

        $row = $this->detailRow();
        self::assertNull($row['lot_id']);
        self::assertNull($row['serial']);
        self::assertSame(0, (int) $row['expect_resolution']);
    }

    /**
     * The delivery's one detail row.
     *
     * Fetched as a list and asserted to be a single row, because "N unidentified units are ONE row
     * of N" is part of what these tests are for — a suffix-generating implementation would produce
     * N, and a single-row fetch would happily return the first of them.
     *
     * @return array<string, mixed>
     */
    private function detailRow(): array
    {
        $this->em->clear();

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT lot_id, serial, quantity, expect_resolution FROM inventory_detail WHERE product_id = ?',
            [$this->product->getId()],
        );

        self::assertCount(1, $rows, 'the delivery should have produced exactly one detail row');

        return $rows[0];
    }

    private function putOnPolicy(string $mode, ?string $sentinelIn): void
    {
        $policy = (new TrackingPolicy())
            ->setName('Policy ' . $mode)
            ->setMode($mode)
            ->setTrackIn(true)
            ->setSentinelIn($sentinelIn);
        $this->em->persist($policy);

        $this->product->setTrackingPolicy($policy);
        $this->em->flush();
    }

    private function issuedOrder(string $quantity): PurchaseOrder
    {
        $order = (new PurchaseOrder())
            ->setPoNumber('PO-' . random_int(100000, 999999))
            ->setVendor($this->vendor)
            ->deriveTaxProvinceFrom($this->warehouse)
            ->setCurrency('CAD');
        $this->em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setProduct($this->product)
            ->setName($this->product->getName())
            ->setSku($this->product->getSku())
            ->setQuantityOrdered($quantity)
            ->setUnitCost('1.0000')
            ->setSubtotal('0.00')
            ->setSortOrder(0);
        $order->addLine($line);
        $this->em->persist($line);

        $order->setStatus('Issued', DocumentActor::system());
        $this->em->flush();

        return $order;
    }
}
