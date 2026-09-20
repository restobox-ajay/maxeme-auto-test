<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Inventory;

use App\Contract\Inventory\ShippedQuantityProviderInterface;
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
use InventoryDepthBundle\Entity\Shipment;
use InventoryDepthBundle\Entity\ShipmentLine;
use InventoryDepthBundle\Inventory\ShippedQuantityProvider;
use InventoryDepthBundle\Shipment\ShipmentRequest;
use InventoryDepthBundle\Shipment\ShipmentService;
use InventoryDepthBundle\Shipment\ShipmentVoidService;

/**
 * This bundle's answer to core's `app.shipped_quantity` seam, and what core does with it.
 *
 * Two subjects in one file because they are one guarantee. The first half is the sum itself against
 * the real schema — the half `InvoiceShippingStatusDeriverTest` deliberately fakes, since it has no
 * shipment table to sum. The second half is the sentence the whole feature exists for: **a shipment
 * recorded here moves the invoice off Not Shipped without anyone touching the invoice row.**
 *
 * The provider is resolved through the CONTAINER by its interface-shaped contract rather than
 * constructed, the same reasoning as `InvoiceShipmentsPanelProviderTest` calling
 * `InjectionPointExtension` instead of the panel class: a wrong tag name or a missing service entry
 * is exactly the failure a hand-built object cannot see, and the tag is the entire contract between
 * core and this bundle.
 */
final class ShippedQuantityProviderTest extends DoctrineIntegrationTestCase
{
    private ShippedQuantityProvider $provider;
    private ShipmentService $shipments;
    private ShipmentVoidService $voids;
    private Company $company;
    private string $regionName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = self::getContainer()->get(ShippedQuantityProvider::class);
        $this->shipments = self::getContainer()->get(ShipmentService::class);
        $this->voids = self::getContainer()->get(ShipmentVoidService::class);

        $region = (new FulfillmentRegion())->setName('Shipped Qty Region');
        $this->em->persist($region);
        $warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');
        $this->regionName = $warehouse->getName();

        $this->company = (new Company())->setName('Buyer Ltd');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testItAnswersCoresContract(): void
    {
        self::assertInstanceOf(ShippedQuantityProviderInterface::class, $this->provider);
    }

    // ── the sum ─────────────────────────────────────────────────────────────────────────────────

    public function testALineWithNoShipmentHasShippedNothing(): void
    {
        $line = $this->invoiceLine('QTY-NONE', '5.0000');

        self::assertSame(0.0, (float) $this->provider->shippedQuantityForInvoiceLine($line));
    }

    public function testAnUnsavedLineHasShippedNothing(): void
    {
        self::assertSame('0', $this->provider->shippedQuantityForInvoiceLine(new InvoiceLine()));
    }

    public function testItSumsEveryShipmentAgainstTheSameLine(): void
    {
        $line = $this->invoiceLine('QTY-SPLIT', '5.0000');
        $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '2.0000'));
        $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '1.0000'));

        self::assertSame(3.0, (float) $this->provider->shippedQuantityForInvoiceLine($line));
    }

    /**
     * A fractional `shipment_line.quantity` survives as a fraction, which is the reason this
     * provider returns a decimal string rather than an int.
     *
     * The row is written directly rather than through `ShipmentService::ship()`, because ship()
     * cannot produce one: `assertRequestIsShippable()` does `(int) round((float) $quantity)` on
     * every quantity it is handed, so a 0.4 request is rejected outright as "must be greater than
     * zero". That is a real limitation of the shipping SERVICE against a `NUMERIC(14, 4)` column and
     * is out of scope here — what this pins is that the provider reads whatever the column holds,
     * whoever wrote it, and does not add a second rounding of its own on the way out.
     */
    public function testAFractionalQuantitySurvivesAsAFraction(): void
    {
        $line = $this->invoiceLine('QTY-FRACTION', '1.0000');
        $this->recordShipmentLineDirectly($line, '0.4000');

        self::assertSame(0.4, (float) $this->provider->shippedQuantityForInvoiceLine($line));
        self::assertSame(
            'Partially Shipped',
            $this->shippingStatusColumn($line->getInvoice()),
            'rounded to whole units this reads as nothing shipped, and the invoice would sit on Not Shipped with goods gone',
        );
    }

    public function testAVoidedShipmentDoesNotCount(): void
    {
        $line = $this->invoiceLine('QTY-VOID', '5.0000');
        $shipment = $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '5.0000'));
        self::assertSame(5.0, (float) $this->provider->shippedQuantityForInvoiceLine($line));

        $this->voids->void($shipment, 'Sent to the wrong depot.', 'tester');

        // The lines are untouched by a void on purpose — the withdrawal is a second fact, not an
        // unwrite — so this has to exclude them by the shipment's own voidedAt, exactly as
        // ShipmentService's oversell guard does.
        self::assertSame(0.0, (float) $this->provider->shippedQuantityForInvoiceLine($line));
    }

    public function testOneLineDoesNotCountAnothersShipment(): void
    {
        $mine = $this->invoiceLine('QTY-MINE', '5.0000');
        $theirs = $this->invoiceLine('QTY-THEIRS', '5.0000');
        $this->shipments->ship(ShipmentRequest::for($this->company)->add($theirs, '5.0000'));

        self::assertSame(0.0, (float) $this->provider->shippedQuantityForInvoiceLine($mine));
    }

    // ── what core does with it ──────────────────────────────────────────────────────────────────

    /**
     * The adversarial case for the whole feature.
     *
     * Nothing here writes `invoice.shipping_status`, and nothing here touches the invoice at all —
     * a shipment is recorded against one of its lines and that is the entire act. The column is read
     * back with raw SQL rather than off the entity, because the entity is what the listener and the
     * deriver were working from: asking it would be asking the accused, and an in-memory value that
     * never reached the database would pass.
     */
    public function testAPartialShipmentMovesTheInvoiceOffNotShippedWithoutTouchingTheInvoiceRow(): void
    {
        $line = $this->invoiceLine('QTY-PARTIAL', '10.0000');
        $bystander = $this->invoiceLine('QTY-BYSTANDER', '10.0000');

        self::assertSame('Not Shipped', $this->shippingStatusColumn($line->getInvoice()), 'precondition');

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '4.0000'));

        self::assertSame('Partially Shipped', $this->shippingStatusColumn($line->getInvoice()));
        // The invoice nobody shipped anything for. A subscriber that queued too widely, or a deriver
        // that answered from the bundle being installed rather than from this invoice's own rows,
        // would move this one too.
        self::assertSame('Not Shipped', $this->shippingStatusColumn($bystander->getInvoice()));
    }

    public function testShippingTheRestCompletesTheInvoicesShippingStatus(): void
    {
        $line = $this->invoiceLine('QTY-FINISH', '10.0000');
        $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '4.0000'));
        self::assertSame('Partially Shipped', $this->shippingStatusColumn($line->getInvoice()));

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '6.0000'));

        self::assertSame('Shipped', $this->shippingStatusColumn($line->getInvoice()));
    }

    /**
     * Voiding the shipment takes the invoice back, and this is why the listener watches `Shipment`
     * and not only `ShipmentLine`.
     *
     * `ShipmentVoidService` writes `voidedAt` on the shipment and leaves every line alone, so a
     * listener watching lines only would see no change at all and leave the invoice reading Shipped
     * with the units back on the shelf.
     */
    public function testVoidingTheShipmentTakesTheInvoiceBackToNotShipped(): void
    {
        $line = $this->invoiceLine('QTY-UNSHIP', '10.0000');
        $shipment = $this->shipments->ship(ShipmentRequest::for($this->company)->add($line, '10.0000'));
        self::assertSame('Shipped', $this->shippingStatusColumn($line->getInvoice()));

        $this->voids->void($shipment, 'Loaded onto the wrong truck.', 'tester');

        self::assertSame('Not Shipped', $this->shippingStatusColumn($line->getInvoice()));
    }

    /**
     * One `ShipmentLine` of $quantity against $line, written without going through ShipmentService.
     *
     * Only for the fractional case above, where ship() refuses the request its own int rounding
     * produces. Everything else in this file ships the ordinary way, so this is not a second path
     * being normalised — it is the column being read at the precision it is declared with.
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

    /**
     * `invoice.shipping_status` as the DATABASE holds it, for the invoice $line belongs to.
     */
    private function shippingStatusColumn(Invoice $invoice): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT shipping_status FROM invoice WHERE id = ?',
            [$invoice->getId()],
        );
    }

    /**
     * One approved order, one issued-nothing invoice for it, one line of $quantity.
     *
     * The same shape InvoiceShipmentsPanelProviderTest builds, because a shipment needs a line whose
     * fulfillment region resolves to a real warehouse.
     */
    private function invoiceLine(string $sku, string $quantity): InvoiceLine
    {
        $product = (new ProductCore())->setSku($sku)->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('QTY-ORD-' . uniqid())
            ->setDocumentDate('2026-09-20')
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
            ->setDocumentNumber('QTY-INV-' . uniqid())
            ->setDocumentDate('2026-09-20')
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
