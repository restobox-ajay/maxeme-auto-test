<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Hook;

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
use App\Twig\InjectionPointExtension;
use InventoryDepthBundle\Shipment\ShipmentRequest;
use InventoryDepthBundle\Shipment\ShipmentService;

/**
 * Proves `InvoiceShipmentsPanelProvider` is actually discovered through the real
 * `app.injection_point` tag — calling `InjectionPointExtension::render()` directly, the exact
 * function `injection_point()` resolves to in `templates/admin/invoice/detail.html.twig` — rather
 * than only testing the provider class in isolation, which would miss a wrong tag name or a wrong
 * `getPoint()` string the same way `ShipmentControllerTest`'s earlier bug was missed by testing
 * `ShipmentService` directly instead of the controller that actually builds its request.
 */
final class InvoiceShipmentsPanelProviderTest extends DoctrineIntegrationTestCase
{
    private const POINT = 'admin_invoice_detail_after_totals';

    private InjectionPointExtension $injectionPoints;
    private ShipmentService $shipments;
    private Company $company;
    private string $regionName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->injectionPoints = self::getContainer()->get(InjectionPointExtension::class);
        $this->shipments = self::getContainer()->get(ShipmentService::class);

        $region = (new FulfillmentRegion())->setName('Panel Region');
        $this->em->persist($region);
        $warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');
        $this->regionName = $warehouse->getName();

        $this->company = (new Company())->setName('Buyer Ltd');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testRendersNothingRecordedYetAndALinkWhenNoShipmentExists(): void
    {
        $invoiceLine = $this->invoiceLine('PANEL-SKU-1', '5.00');

        $html = $this->injectionPoints->render(self::POINT, ['document' => $invoiceLine->getInvoice()]);

        self::assertStringContainsString('Nothing recorded yet.', $html);
        self::assertStringContainsString('/shipments/new?invoice%5B0%5D=' . $invoiceLine->getInvoice()->getId(), $html);
    }

    public function testRendersAShippedLineForThisInvoice(): void
    {
        $invoiceLine = $this->invoiceLine('PANEL-SKU-2', '5.00');
        $shipment = $this->shipments->ship(ShipmentRequest::for($this->company)->add($invoiceLine, '3.00'));

        $html = $this->injectionPoints->render(self::POINT, ['document' => $invoiceLine->getInvoice()]);

        self::assertStringContainsString($shipment->getShipmentNumber(), $html);
        self::assertStringContainsString('PANEL-SKU-2', $html, 'the SKU column');
        self::assertStringContainsString('Widget', $html, 'the product name column');
        self::assertStringContainsString('Paperwork only', $html);
    }

    public function testACombinedShipmentStillShowsUpOnBothInvoicesItTouches(): void
    {
        $lineA = $this->invoiceLine('PANEL-COMBINE-A', '2.00');
        $lineB = $this->invoiceLine('PANEL-COMBINE-B', '3.00');

        $shipment = $this->shipments->ship(
            ShipmentRequest::for($this->company)->add($lineA, '2.00')->add($lineB, '3.00'),
        );

        $htmlForA = $this->injectionPoints->render(self::POINT, ['document' => $lineA->getInvoice()]);
        $htmlForB = $this->injectionPoints->render(self::POINT, ['document' => $lineB->getInvoice()]);

        self::assertStringContainsString($shipment->getShipmentNumber(), $htmlForA);
        self::assertStringContainsString($shipment->getShipmentNumber(), $htmlForB);
    }

    public function testRendersNothingForAnUnsavedInvoice(): void
    {
        $html = $this->injectionPoints->render(self::POINT, ['document' => new Invoice()]);

        self::assertSame('', $html);
    }

    public function testRendersNothingForAWrongContextDocument(): void
    {
        $html = $this->injectionPoints->render(self::POINT, ['document' => null]);

        self::assertSame('', $html);
    }

    private function invoiceLine(string $sku, string $quantity): InvoiceLine
    {
        $product = (new ProductCore())->setSku($sku)->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('PANEL-ORD-' . uniqid())
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
            ->setDocumentNumber('PANEL-INV-' . uniqid())
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
