<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Controller\Admin;

use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Controller\Admin\ShipmentController;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\ShipmentLine;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Exercises `ShipmentController`'s actions directly (fetched as a real, container-wired service, not
 * `new`'d by hand — `AbstractController::render()`/`addFlash()`/`redirectToRoute()` all need the
 * container this gives it) rather than through the full HTTP stack, since `/admin` sits behind
 * authentication this suite has no reason to simulate. This is real wiring, not a mock: real Twig
 * rendering against real templates, real route generation, real Doctrine queries.
 */
final class ShipmentControllerTest extends DoctrineIntegrationTestCase
{
    private ShipmentController $controller;
    private StockMovementService $movements;
    private RequestStack $requestStack;
    private Company $company;
    private Warehouse $warehouse;
    private string $regionName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = self::getContainer()->get(ShipmentController::class);
        $this->movements = self::getContainer()->get(StockMovementService::class);
        $this->requestStack = self::getContainer()->get('request_stack');

        $region = (new FulfillmentRegion())->setName('Controller Region');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');
        $this->regionName = $this->warehouse->getName();

        $this->company = (new Company())->setName('Buyer Ltd');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        while ($this->requestStack->pop() !== null) {
        }

        parent::tearDown();
    }

    public function testIndexRendersAnEmptyList(): void
    {
        $response = $this->controller->index($this->request('GET', '/admin/bundles/inventory-depth/shipments'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('No shipments match.', (string) $response->getContent());
    }

    public function testAddingAnInvoiceByNumberThenShowingItsUnshippedLines(): void
    {
        $invoiceLine = $this->invoiceLine('CTRL-SKU-1', '5.00');
        $invoiceNumber = $invoiceLine->getInvoice()->getDocumentNumber();

        $redirect = $this->controller->new($this->request('GET', '/admin/bundles/inventory-depth/shipments/new', [
            'invoice_number' => $invoiceNumber,
        ]));
        self::assertSame(302, $redirect->getStatusCode());
        self::assertStringContainsString('invoice%5B0%5D=' . $invoiceLine->getInvoice()->getId(), (string) $redirect->getTargetUrl());

        $form = $this->controller->new($this->request('GET', '/admin/bundles/inventory-depth/shipments/new', [
            'invoice' => [$invoiceLine->getInvoice()->getId()],
        ]));
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('CTRL-SKU-1', (string) $form->getContent());
        self::assertStringContainsString($invoiceNumber, (string) $form->getContent());
    }

    public function testRecordingAShipmentThroughTheFormAndVoidingIt(): void
    {
        $invoiceLine = $this->invoiceLine('CTRL-SKU-2', '5.00');
        $invoice = $invoiceLine->getInvoice();

        $create = $this->controller->create($this->request('POST', '/admin/bundles/inventory-depth/shipments/new', [
            'invoice' => [$invoice->getId()],
            'lines' => [(string) $invoiceLine->getId() => '3'],
        ]));
        self::assertSame(302, $create->getStatusCode());
        self::assertStringContainsString('/shipments/', (string) $create->getTargetUrl());

        $shipmentId = (int) substr((string) $create->getTargetUrl(), (int) strrpos((string) $create->getTargetUrl(), '/') + 1);
        self::assertGreaterThan(0, $shipmentId);

        $show = $this->controller->show($shipmentId);
        self::assertSame(200, $show->getStatusCode());
        self::assertStringContainsString('CTRL-SKU-2', (string) $show->getContent());
        self::assertStringContainsString('Paperwork only', (string) $show->getContent());

        $void = $this->controller->void($shipmentId, $this->request('POST', "/admin/bundles/inventory-depth/shipments/{$shipmentId}/void", [
            'reason' => 'Wrong customer',
        ]));
        self::assertSame(302, $void->getStatusCode());

        $showAfterVoid = $this->controller->show($shipmentId);
        self::assertStringContainsString('Voided', (string) $showAfterVoid->getContent());
    }

    /**
     * The form has to be able to POST a fraction, and the box has to let a browser type one.
     *
     * `step="1"` made the plain quantity input refuse 0.4 before the server ever saw it, so the
     * decimal fix would have stopped at the edge of the screen. The remaining figure is rendered at
     * the column's own precision for the same reason: `max` is compared by the browser against what
     * is typed.
     */
    public function testTheQuantityBoxAcceptsAFractionAndShowsWhatIsActuallyLeft(): void
    {
        $invoiceLine = $this->invoiceLine('CTRL-FRACTION', '1.0000');

        $form = $this->controller->new($this->request('GET', '/admin/bundles/inventory-depth/shipments/new', [
            'invoice' => [$invoiceLine->getInvoice()->getId()],
        ]));

        $html = (string) $form->getContent();
        self::assertStringContainsString('step="0.0001"', $html);
        self::assertStringContainsString('max="1.0000"', $html);

        $create = $this->controller->create($this->request('POST', '/admin/bundles/inventory-depth/shipments/new', [
            'invoice' => [$invoiceLine->getInvoice()->getId()],
            'lines' => [(string) $invoiceLine->getId() => '0.4'],
        ]));
        self::assertSame(302, $create->getStatusCode());
        self::assertStringContainsString('/shipments/', (string) $create->getTargetUrl(), 'a refusal would redirect back to the form');

        $lines = $this->em->getRepository(ShipmentLine::class)->findBy(['invoiceLine' => $invoiceLine]);
        self::assertCount(1, $lines);
        self::assertSame('0.4000', $lines[0]->getQuantity(), 'stored at the configured scale, fraction intact');

        // And the form now offers the rest, rather than the whole line again or nothing at all.
        $again = $this->controller->new($this->request('GET', '/admin/bundles/inventory-depth/shipments/new', [
            'invoice' => [$invoiceLine->getInvoice()->getId()],
        ]));
        self::assertStringContainsString('0.6000 remaining', (string) $again->getContent());
    }

    public function testNewFormOffersAnEditableFefoBreakdownForALotTrackedLine(): void
    {
        [$invoiceLine, $lotA, $lotB] = $this->lotTrackedInvoiceLineWithTwoLots();

        $form = $this->controller->new($this->request('GET', '/admin/bundles/inventory-depth/shipments/new', [
            'invoice' => [$invoiceLine->getInvoice()->getId()],
        ]));

        $html = (string) $form->getContent();
        self::assertStringContainsString($lotA->getLabel(), $html);
        self::assertStringContainsString($lotB->getLabel(), $html);
        self::assertStringContainsString('allocations[' . $invoiceLine->getId() . '][lot][' . $lotA->getId() . ']', $html);
    }

    public function testRecordingAShipmentWithAnExplicitLotAllocationAcrossTwoLots(): void
    {
        [$invoiceLine, $lotA, $lotB] = $this->lotTrackedInvoiceLineWithTwoLots();

        // No `lines[...]` entry — a lot-tracked row's form never renders that box (see
        // shipment_new.html.twig); the total is derived from the lot quantities alone, exactly the
        // payload shape the real form actually submits.
        $create = $this->controller->create($this->request('POST', '/admin/bundles/inventory-depth/shipments/new', [
            'invoice' => [$invoiceLine->getInvoice()->getId()],
            'allocations' => [
                (string) $invoiceLine->getId() => [
                    'lot' => [(string) $lotA->getId() => '6', (string) $lotB->getId() => '4'],
                ],
            ],
        ]));
        self::assertSame(302, $create->getStatusCode());

        $shipmentId = (int) substr((string) $create->getTargetUrl(), (int) strrpos((string) $create->getTargetUrl(), '/') + 1);
        $lines = $this->em->getRepository(ShipmentLine::class)->findBy(['invoiceLine' => $invoiceLine]);
        self::assertCount(2, $lines);

        $show = $this->controller->show($shipmentId);
        self::assertStringContainsString($lotA->getLabel(), (string) $show->getContent());
        self::assertStringContainsString($lotB->getLabel(), (string) $show->getContent());
    }

    public function testRecordingAShipmentWithSerialAllocationsNeedsNoSeparateQuantity(): void
    {
        $policy = (new TrackingPolicy())->setName('Serial Controller')->setMode(TrackingPolicy::MODE_SERIAL)->setTrackOut(true);
        $this->em->persist($policy);

        $product = (new ProductCore())
            ->setSku('CTRL-SERIAL-1')
            ->setName('Serialised Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
            ->setTrackingPolicy($policy);
        $this->em->persist($product);
        $this->em->flush();

        foreach (['SN-X', 'SN-Y'] as $serial) {
            $this->movements->apply(
                MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed-' . $serial)
                    ->receive($product, new DetailKey($this->warehouse, null, null, $serial, InventoryDetail::STATUS_AVAILABLE), 1),
            );
        }
        $this->em->flush();

        // Captures SN-X alone to satisfy MandatoryCaptureGuard at issue — the allocation posted at
        // ship time still names both serials.
        $invoiceLine = $this->invoiceLineForProduct($product, '2.00', null, 'SN-X');

        $create = $this->controller->create($this->request('POST', '/admin/bundles/inventory-depth/shipments/new', [
            'invoice' => [$invoiceLine->getInvoice()->getId()],
            'allocations' => [
                (string) $invoiceLine->getId() => ['serial' => ['SN-X', 'SN-Y']],
            ],
        ]));
        self::assertSame(302, $create->getStatusCode());

        $lines = $this->em->getRepository(ShipmentLine::class)->findBy(['invoiceLine' => $invoiceLine]);
        self::assertCount(2, $lines);
        $serials = array_map(static fn (ShipmentLine $line): ?string => $line->getSerial(), $lines);
        sort($serials);
        self::assertSame(['SN-X', 'SN-Y'], $serials);
    }

    /** @return array{0: InvoiceLine, 1: InventoryLot, 2: InventoryLot} */
    private function lotTrackedInvoiceLineWithTwoLots(): array
    {
        $policy = (new TrackingPolicy())->setName('Lot Controller')->setMode(TrackingPolicy::MODE_LOT)->setTrackOut(true);
        $this->em->persist($policy);

        $product = (new ProductCore())
            ->setSku('CTRL-LOT-1')
            ->setName('Lot Tracked Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
            ->setTrackingPolicy($policy);
        $this->em->persist($product);
        $this->em->flush();

        $lotA = (new InventoryLot())->setProduct($product)->setCode('CTRL-A')->setExpiry(new \DateTimeImmutable('2027-01-01'));
        $lotB = (new InventoryLot())->setProduct($product)->setCode('CTRL-B')->setExpiry(new \DateTimeImmutable('2027-06-01'));
        $this->em->persist($lotA);
        $this->em->persist($lotB);
        $this->em->flush();

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed-ctrl-a')
                ->receive($product, new DetailKey($this->warehouse, null, $lotA, null, InventoryDetail::STATUS_AVAILABLE), 6),
        );
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed-ctrl-b')
                ->receive($product, new DetailKey($this->warehouse, null, $lotB, null, InventoryDetail::STATUS_AVAILABLE), 4),
        );
        $this->em->flush();

        // Captures lotA alone to satisfy MandatoryCaptureGuard at issue — the allocation posted at
        // ship time is still what's authoritative, splitting across both lots.
        $invoiceLine = $this->invoiceLineForProduct($product, '10.00', $lotA->getId());

        return [$invoiceLine, $lotA, $lotB];
    }

    /** @param array<string, mixed> $params */
    private function request(string $method, string $uri, array $params = []): Request
    {
        $request = Request::create($uri, $method, $params);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        return $request;
    }

    private function invoiceLine(string $sku, string $quantity): InvoiceLine
    {
        $product = (new ProductCore())->setSku($sku)->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        return $this->invoiceLineForProduct($product, $quantity);
    }

    private function invoiceLineForProduct(ProductCore $product, string $quantity, ?int $lotId = null, ?string $serial = null): InvoiceLine
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('CTRL-ORD-' . uniqid())
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
            ->setDocumentNumber('CTRL-INV-' . uniqid())
            ->setDocumentDate('2026-09-15')
            ->setFulfillmentRegion($this->regionName);
        $order->addInvoice($invoice);

        $invoiceLine = (new InvoiceLine())
            ->setSalesOrderLine($orderLine)
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku($product->getSku())
            ->setQuantity($quantity)
            ->setLotId($lotId)
            ->setSerial($serial);
        $invoice->addLine($invoiceLine);
        $this->em->persist($invoice);
        $this->em->flush();

        // A draft holds nothing shippable (#784) — issued so create()'s new draft guard doesn't
        // refuse these fixtures the way it now refuses a real draft.
        $invoice->issue(DocumentActor::system());
        $this->em->flush();

        return $invoiceLine;
    }
}
