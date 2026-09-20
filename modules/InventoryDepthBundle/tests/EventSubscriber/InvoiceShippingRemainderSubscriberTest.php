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
use InventoryDepthBundle\Entity\Shipment;
use InventoryDepthBundle\Entity\ShipmentLine;
use InventoryDepthBundle\Shipment\ShipmentRequest;
use InventoryDepthBundle\Shipment\ShipmentService;

/**
 * The listener half of "Completed auto-ships the remainder"
 * (`docs/plans/2026-09-14-shipment-dispatch.md`) — proven with `simple`-inventory products, since
 * the mechanism is entirely about invoice lines and never reaches the dimensional/lot-tracked
 * question 2 gate; `ShipmentServiceTest` already covers that gate directly.
 */
final class InvoiceShippingRemainderSubscriberTest extends DoctrineIntegrationTestCase
{
    private ShipmentService $shipments;
    private Company $company;
    private string $regionName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shipments = self::getContainer()->get(ShipmentService::class);

        $region = (new FulfillmentRegion())->setName('Auto Ship Region');
        $this->em->persist($region);
        $warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');
        $this->regionName = $warehouse->getName();

        $this->company = (new Company())->setName('Buyer Ltd');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testCompletingAnInvoiceWithNothingExplicitlyShippedGeneratesTheFullShipment(): void
    {
        $invoiceLine = $this->invoiceLine('AUTO-SHIP-FULL', '5.00');

        $this->complete($invoiceLine->getInvoice());

        $lines = $this->em->getRepository(ShipmentLine::class)->findBy(['invoiceLine' => $invoiceLine]);
        self::assertCount(1, $lines);
        // '5.0000', at the declared precision of the column. The remainder is a computed figure,
        // so QuantityScale is what writes it, and it writes every quantity the same way —
        // including an explicitly typed one, which is rounded to the store's configured scale on the
        // way in rather than kept byte for byte. A setting that is "applied globally" cannot make an
        // exception for figures somebody typed.
        self::assertSame('5.0000', $lines[0]->getQuantity());
        self::assertFalse($lines[0]->isMovementApplied(), 'a simple product records paperwork only');

        $shipment = $lines[0]->getShipment();
        self::assertInstanceOf(Shipment::class, $shipment);
        self::assertSame($this->company->getId(), $shipment->getCompany()->getId());
    }

    public function testCompletingAnInvoiceAfterAPartialShipmentOnlyShipsTheRest(): void
    {
        $invoiceLine = $this->invoiceLine('AUTO-SHIP-PARTIAL', '5.00');

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '2.00'));

        $this->complete($invoiceLine->getInvoice());

        $lines = $this->em->getRepository(ShipmentLine::class)->findBy(['invoiceLine' => $invoiceLine]);
        self::assertCount(2, $lines, 'the explicit 2 and the auto-shipped remainder of 3');

        $quantities = array_map(static fn (ShipmentLine $line): string => $line->getQuantity(), $lines);
        sort($quantities);
        // Two shapes side by side, on purpose: what a person typed, kept exactly, and what this
        // listener computed, at the column's own four decimal places.
        self::assertSame(['2.0000', '3.0000'], $quantities);
    }

    public function testCompletingAnInvoiceFullyShippedByHandGeneratesNoAutoShipment(): void
    {
        $invoiceLine = $this->invoiceLine('AUTO-SHIP-NONE', '5.00');

        $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '5.00'));

        $this->complete($invoiceLine->getInvoice());

        $lines = $this->em->getRepository(ShipmentLine::class)->findBy(['invoiceLine' => $invoiceLine]);
        self::assertCount(1, $lines, 'no second, auto-generated line');
    }

    private function invoiceLine(string $sku, string $quantity): InvoiceLine
    {
        $product = (new ProductCore())->setSku($sku)->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('AUTO-ORD-' . uniqid())
            ->setDocumentDate('2026-09-15')
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
            ->setDocumentNumber('AUTO-INV-' . uniqid())
            ->setDocumentDate('2026-09-15')
            ->setFulfillmentRegion($this->regionName);
        $order->addInvoice($invoice);

        $invoiceLine = (new InvoiceLine())
            ->setSalesOrderLine($orderLine)
            ->setProduct($product)
            ->setName($product->getName())
            ->setQuantity($quantity);
        $invoice->addLine($invoiceLine);
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoiceLine;
    }

    private function complete(Invoice $invoice): void
    {
        $invoice->issue(DocumentActor::system());
        $this->em->flush();
        $invoice->startProcessing(DocumentActor::system());
        $this->em->flush();
        $invoice->setStatus('Completed', DocumentActor::system(), 'Fulfilment completed.');
        $this->em->flush();
    }
}
