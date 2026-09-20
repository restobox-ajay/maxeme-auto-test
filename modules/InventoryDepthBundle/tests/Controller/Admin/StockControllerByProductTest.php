<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Controller\Admin;

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
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\DocumentActor;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Controller\Admin\StockController;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\ProductReorderRule;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Shipment\ShipmentRequest;
use InventoryDepthBundle\Shipment\ShipmentService;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Product Inventory Hub (docs/plans/2026-09-15-product-inventory-hub.md), build order steps 2-7 all
 * landed in `StockController::byProduct()` and its template — this is the test plan's own list:
 * a lot-tracked product, a serial-tracked one, a simple product with no detail rows at all, a product
 * with no reorder rule, a product with an empty activity feed, and the type filter narrowing the
 * merged Recent Activity feed.
 */
final class StockControllerByProductTest extends DoctrineIntegrationTestCase
{
    private StockController $controller;
    private StockMovementService $movements;
    private ShipmentService $shipments;
    private RequestStack $requestStack;
    private Company $company;
    private Warehouse $warehouse;
    private string $regionName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = self::getContainer()->get(StockController::class);
        $this->movements = self::getContainer()->get(StockMovementService::class);
        $this->shipments = self::getContainer()->get(ShipmentService::class);
        $this->requestStack = self::getContainer()->get('request_stack');

        $region = (new FulfillmentRegion())->setName('Hub Region');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');
        $this->regionName = $this->warehouse->getName();

        $this->company = (new Company())->setName('Hub Buyer Ltd');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        while ($this->requestStack->pop() !== null) {
        }

        parent::tearDown();
    }

    public function testASimpleProductWithNoDetailRowsShowsEveryPanelsEmptyState(): void
    {
        $product = $this->simpleProduct('HUB-SIMPLE-1');

        $response = $this->controller->byProduct($product->getId(), $this->request());
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Nothing matches.', $html);
        self::assertStringContainsString('No recent activity.', $html);
        self::assertStringContainsString('No stock rows for this product yet.', $html);
        // No tracking policy on a simple product ⇒ no lot/serial panel and no Reorder Status card
        // (no rule exists for it either). Checked against the card heading tags specifically — the
        // bundle's own nav sidebar has a plain "Lots" link (a different screen entirely) that a bare
        // substring check would collide with.
        self::assertStringNotContainsString('<h2>Lots</h2>', $html);
        self::assertStringNotContainsString('<h2>Serials</h2>', $html);
        self::assertStringNotContainsString('Reorder Status', $html);
    }

    public function testALotTrackedProductListsFefoOrderedLotsAndFlagsTheExpiredOne(): void
    {
        $product = $this->lotTrackedProduct('HUB-LOT-1');

        $expired = (new InventoryLot())->setProduct($product)->setCode('HUB-EXPIRED')->setExpiry(new \DateTimeImmutable('2020-01-01'));
        $fresh = (new InventoryLot())->setProduct($product)->setCode('HUB-FRESH')->setExpiry(new \DateTimeImmutable('2030-01-01'));
        $this->em->persist($expired);
        $this->em->persist($fresh);
        $this->em->flush();

        $this->receive($product, $expired, 5);
        $this->receive($product, $fresh, 7);

        $html = (string) $this->controller->byProduct($product->getId(), $this->request())->getContent();

        self::assertStringContainsString($expired->getLabel(), $html);
        self::assertStringContainsString($fresh->getLabel(), $html);
        // The expired lot's row carries the same "is-oversold" flag the Lots screen already uses.
        self::assertMatchesRegularExpression('/data-item-row is-oversold">\s*<td data-label="Lot">' . preg_quote($expired->getLabel(), '/') . '/', $html);
    }

    /**
     * A lot carrying the tracking-worklist's own placeholder identity (TrackingPolicy::DEFAULT_SENTINEL,
     * '[PENDING]') is not a real identity somebody captured — it is a substitute a writer stamped
     * with a promise to come back later. The hub must say so, not present it as an ordinary lot.
     */
    public function testALotTrackedProductFlagsASentinelLotAndLinksToTheTrackingWorklist(): void
    {
        $product = $this->lotTrackedProduct('HUB-SENTINEL-LOT-1');

        $pending = (new InventoryLot())->setProduct($product)->setCode('[PENDING]');
        $this->em->persist($pending);
        $this->em->flush();

        $this->receive($product, $pending, 4);

        $html = (string) $this->controller->byProduct($product->getId(), $this->request())->getContent();

        self::assertStringContainsString('Needs ID', $html);
        self::assertStringContainsString('still need', $html);
        self::assertStringContainsString($this->urlGenerator()->generate('admin_bundle_inventory_depth_tracking_worklist', ['filters' => ['product' => $product->getSku()]]), $html);
    }

    /**
     * The pending count must also catch a NULL-identity placeholder — a policy with a blank
     * sentinel writes NULL rather than a string, and `availableSerialsFor()` excludes a NULL
     * serial outright, so no row for it could ever appear in the serial table. The count is the
     * only way this panel can say "some of this stock still needs identifying" for that case.
     */
    public function testPendingCountCatchesANullIdentityRowTheSerialListCannotShow(): void
    {
        $product = $this->serialTrackedProduct('HUB-SENTINEL-SERIAL-1');

        $unresolved = (new InventoryDetail())
            ->setProduct($product)
            ->setWarehouse($this->warehouse)
            ->setStatus(InventoryDetail::STATUS_AVAILABLE)
            ->setExpectResolution(true)
            ->setQuantity(1);
        $this->em->persist($unresolved);
        $this->em->flush();

        $html = (string) $this->controller->byProduct($product->getId(), $this->request())->getContent();

        self::assertStringContainsString('1 unit still needs identification', $html);
        self::assertStringContainsString('No serials with stock.', $html, 'the row itself has no serial to show — the count is the only signal');
    }

    public function testASerialTrackedProductListsItsAvailableSerials(): void
    {
        $product = $this->serialTrackedProduct('HUB-SERIAL-1');

        foreach (['HUB-SN-1', 'HUB-SN-2'] as $serial) {
            $this->movements->apply(
                MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'hub-seed-' . $serial)
                    ->receive($product, new DetailKey($this->warehouse, null, null, $serial), 1),
            );
        }
        $this->em->flush();

        $html = (string) $this->controller->byProduct($product->getId(), $this->request())->getContent();

        self::assertStringContainsString('HUB-SN-1', $html);
        self::assertStringContainsString('HUB-SN-2', $html);
    }

    public function testAvailabilityAndValueReflectsOnHandQuantityAndCostPrice(): void
    {
        $product = $this->simpleProduct('HUB-VALUE-1');
        $product->setCostPrice('4.50');
        $this->em->flush();

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'hub-value-seed')
                ->receive($product, new DetailKey($this->warehouse), 10),
        );
        $this->em->flush();

        $html = (string) $this->controller->byProduct($product->getId(), $this->request())->getContent();

        // 10 units at $4.50 = $45.00.
        self::assertStringContainsString('$45.00', $html);
    }

    public function testReorderStatusOnlyAppearsForAWarehouseThatActuallyHasARule(): void
    {
        $withRule = $this->simpleProduct('HUB-REORDER-1');
        $rule = (new ProductReorderRule())->setProduct($withRule)->setWarehouse($this->warehouse)->setReorderPoint(10);
        $this->em->persist($rule);
        $this->em->flush();

        $withoutRule = $this->simpleProduct('HUB-REORDER-2');

        $htmlWithRule = (string) $this->controller->byProduct($withRule->getId(), $this->request())->getContent();
        $htmlWithoutRule = (string) $this->controller->byProduct($withoutRule->getId(), $this->request())->getContent();

        self::assertStringContainsString('Reorder Status', $htmlWithRule);
        self::assertStringNotContainsString('Reorder Status', $htmlWithoutRule);
    }

    public function testRecentActivityMergesEverySourceAndTheTypeFilterNarrowsIt(): void
    {
        $product = $this->simpleProduct('HUB-ACTIVITY-1');

        $orderLine = $this->orderLineFor($product);
        $shipment = $this->shipments->ship(
            ShipmentRequest::for($this->company)->add($this->invoiceLineFromOrderLine($orderLine), '1.00'),
        );
        $creditMemo = $this->creditMemoFor($product);
        $purchaseOrder = $this->purchaseOrderFor($product);
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_ADJUSTMENT, 'hub-activity-adj')
                ->receive($product, new DetailKey($this->warehouse), 3),
        );
        $this->em->flush();

        $orderLabel = 'Order ' . $orderLine->getOrder()->getOrderNumber();
        $shipmentLabel = 'Shipment ' . $shipment->getShipmentNumber();
        $creditMemoLabel = 'Credit Memo ' . $creditMemo->getDocumentNumber();
        $purchaseLabel = 'PO ' . $purchaseOrder->getPoNumber();

        $all = (string) $this->controller->byProduct($product->getId(), $this->request())->getContent();
        self::assertStringContainsString($orderLabel, $all);
        self::assertStringContainsString($shipmentLabel, $all);
        self::assertStringContainsString($creditMemoLabel, $all);
        self::assertStringContainsString($purchaseLabel, $all);

        // The type-filter nav always lists all five type names as filter buttons regardless of the
        // active filter — checked against each row's own unique label, not the bare type word, since
        // "Credit Memo"/"PO " etc. legitimately appear in that nav on every request.
        $shipmentOnly = (string) $this->controller->byProduct($product->getId(), $this->request(['activity' => 'shipment']))->getContent();
        self::assertStringContainsString($shipmentLabel, $shipmentOnly);
        self::assertStringNotContainsString($orderLabel, $shipmentOnly);
        self::assertStringNotContainsString($creditMemoLabel, $shipmentOnly);
        self::assertStringNotContainsString($purchaseLabel, $shipmentOnly);
    }

    public function testIdentityCardListsVendorsThisProductHasActuallyBeenBoughtFromAndNoneWhenThereAreNone(): void
    {
        $bought = $this->simpleProduct('HUB-VENDOR-1');
        $order = $this->purchaseOrderFor($bought);

        $htmlBought = (string) $this->controller->byProduct($bought->getId(), $this->request())->getContent();
        self::assertStringContainsString($order->getVendorName(), $htmlBought);

        $neverBought = $this->simpleProduct('HUB-VENDOR-2');
        $htmlNeverBought = (string) $this->controller->byProduct($neverBought->getId(), $this->request())->getContent();
        self::assertMatchesRegularExpression('/<strong>Vendors:<\/strong>\s*—/', $htmlNeverBought);
    }

    public function testSalesSnapshotCountsOnlyInvoicesWithinTheWindow(): void
    {
        $product = $this->simpleProduct('HUB-SALES-1');

        $recentOrderLine = $this->orderLineFor($product, '4.00');
        $this->invoiceLineFromOrderLine($recentOrderLine, (new \DateTimeImmutable())->format('Y-m-d'));

        $oldOrderLine = $this->orderLineFor($product, '9.00');
        $this->invoiceLineFromOrderLine($oldOrderLine, '2020-01-01');

        $html = (string) $this->controller->byProduct($product->getId(), $this->request())->getContent();

        self::assertStringContainsString('Units sold:</strong> 4', $html);
    }

    /**
     * "Units sold" means invoiced, and a draft or a cancellation is neither.
     *
     * The snapshot query constrained only the product and the document date, so an abandoned draft
     * and a withdrawn order both read as demand on the one figure this page exists to be reordered
     * from. The rule applied is Invoice::countsTowardInvoicedQuantity()'s, asked of many rows.
     */
    public function testSalesSnapshotIgnoresDraftAndCancelledInvoices(): void
    {
        $product = $this->simpleProduct('HUB-SALES-2');
        $today = (new \DateTimeImmutable())->format('Y-m-d');

        // The only line that is a sale.
        $this->invoiceLineFromOrderLine($this->orderLineFor($product, '4.00'), $today);
        // Neither of these is, and both are inside the window.
        $this->invoiceLineFromOrderLine($this->orderLineFor($product, '500.00'), $today, 'Draft');
        $this->invoiceLineFromOrderLine($this->orderLineFor($product, '700.00'), $today, 'Cancelled');

        $html = (string) $this->controller->byProduct($product->getId(), $this->request())->getContent();

        // Anchored on the label, not on the bare figures: 57KB of page carries ids, prices and an
        // asset version string, and a loose '1204' matches one of them.
        self::assertStringContainsString('Units sold:</strong> 4', $html);
        self::assertStringNotContainsString('Units sold:</strong> 504', $html);
        self::assertStringNotContainsString('Units sold:</strong> 1204', $html);
    }

    public function testAddingANoteRendersItInTheLog(): void
    {
        $product = $this->simpleProduct('HUB-NOTES-1');

        $redirect = $this->controller->addNote($product->getId(), $this->request(['note' => 'Fragile — handle with care.'], 'POST'));
        self::assertSame(302, $redirect->getStatusCode());

        $html = (string) $this->controller->byProduct($product->getId(), $this->request())->getContent();
        self::assertStringContainsString('Fragile — handle with care.', $html);
    }

    public function testAddingANoteViaXhrReturnsItsIdAndText(): void
    {
        $product = $this->simpleProduct('HUB-NOTES-XHR-1');

        $response = $this->controller->addNote($product->getId(), $this->xhrRequest(['note' => 'Ships on a pallet.']));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertSame('Ships on a pallet.', $data['note']);
        self::assertNotEmpty($data['id']);
    }

    public function testABlankNoteIsRejected(): void
    {
        $product = $this->simpleProduct('HUB-NOTES-BLANK-1');

        $response = $this->controller->addNote($product->getId(), $this->xhrRequest(['note' => '   ']));

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse(json_decode((string) $response->getContent(), true)['ok']);
    }

    public function testEditingANoteKeepsItsOriginalTimestampButChangesItsText(): void
    {
        $product = $this->simpleProduct('HUB-NOTES-EDIT-1');
        $added = json_decode((string) $this->controller->addNote($product->getId(), $this->xhrRequest(['note' => 'First wording.']))->getContent(), true);

        $response = $this->controller->updateNote($product->getId(), $this->xhrRequest(['id' => $added['id'], 'note' => 'Corrected wording.']));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('Corrected wording.', $data['note']);
        self::assertSame($added['date'], $data['date'], 'an edit corrects the wording, not the timestamp');
    }

    public function testDeletingANoteRemovesExactlyThatOne(): void
    {
        $product = $this->simpleProduct('HUB-NOTES-DEL-1');
        $kept = json_decode((string) $this->controller->addNote($product->getId(), $this->xhrRequest(['note' => 'Keep me.']))->getContent(), true);
        $removed = json_decode((string) $this->controller->addNote($product->getId(), $this->xhrRequest(['note' => 'Remove me.']))->getContent(), true);

        $response = $this->controller->deleteNote($product->getId(), $this->xhrRequest(['id' => $removed['id']]));

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $this->controller->byProduct($product->getId(), $this->request())->getContent();
        self::assertStringContainsString('Keep me.', $html);
        self::assertStringNotContainsString('Remove me.', $html);
    }

    /** A note addressed by id must belong to the product in the URL, the way CompanyController guards its own. */
    public function testANoteBelongingToAnotherProductCannotBeDeletedOrEditedThroughThisOne(): void
    {
        $owner = $this->simpleProduct('HUB-NOTES-OWNER-1');
        $other = $this->simpleProduct('HUB-NOTES-OTHER-1');
        $note = json_decode((string) $this->controller->addNote($owner->getId(), $this->xhrRequest(['note' => 'Belongs to owner.']))->getContent(), true);

        $deleteResponse = $this->controller->deleteNote($other->getId(), $this->xhrRequest(['id' => $note['id']]));
        self::assertSame(404, $deleteResponse->getStatusCode());

        $updateResponse = $this->controller->updateNote($other->getId(), $this->xhrRequest(['id' => $note['id'], 'note' => 'Hijacked.']));
        self::assertSame(400, $updateResponse->getStatusCode());

        $html = (string) $this->controller->byProduct($owner->getId(), $this->request())->getContent();
        self::assertStringContainsString('Belongs to owner.', $html);
    }

    public function testNewestNoteListsFirst(): void
    {
        $product = $this->simpleProduct('HUB-NOTES-ORDER-1');
        $this->controller->addNote($product->getId(), $this->xhrRequest(['note' => 'Older note.']));
        $this->controller->addNote($product->getId(), $this->xhrRequest(['note' => 'Newer note.']));

        $html = (string) $this->controller->byProduct($product->getId(), $this->request())->getContent();

        self::assertGreaterThan(
            strpos($html, 'Newer note.'),
            strpos($html, 'Older note.'),
            'the newest note must render above the older one',
        );
    }

    /** ProductCore::$remarks is a separate, untouched field — adding a note must not disturb it. */
    public function testAddingANoteDoesNotTouchTheLegacyRemarksField(): void
    {
        $product = $this->simpleProduct('HUB-NOTES-REMARKS-1');
        $product->setRemarks('Original grid remark.');
        $this->em->flush();

        $this->controller->addNote($product->getId(), $this->xhrRequest(['note' => 'A dated note.']));

        self::assertSame('Original grid remark.', $product->getRemarks());
    }

    private function simpleProduct(string $sku): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Hub Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function lotTrackedProduct(string $sku): ProductCore
    {
        $policy = (new TrackingPolicy())->setName('Hub Lot Policy')->setMode(TrackingPolicy::MODE_LOT)->setTrackIn(true)->setTrackOut(true);
        $this->em->persist($policy);

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Hub Lot Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
            ->setTrackingPolicy($policy);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function serialTrackedProduct(string $sku): ProductCore
    {
        $policy = (new TrackingPolicy())->setName('Hub Serial Policy')->setMode(TrackingPolicy::MODE_SERIAL)->setTrackIn(true)->setTrackOut(true);
        $this->em->persist($policy);

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Hub Serial Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
            ->setTrackingPolicy($policy);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function receive(ProductCore $product, InventoryLot $lot, int $quantity): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'hub-lot-seed-' . $lot->getCode())
                ->receive($product, new DetailKey($this->warehouse, null, $lot), $quantity),
        );
        $this->em->flush();
    }

    private function orderLineFor(ProductCore $product, string $quantity = '5.00'): SalesOrderLine
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('HUB-ORD-' . uniqid())
            ->setDocumentDate('2026-09-15')
            ->setFulfillmentRegion($this->regionName);
        $this->em->persist($order);

        $line = (new SalesOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setQuantity($quantity)
            ->setLocation($this->regionName);
        $order->addLine($line);
        $this->em->flush();

        $order->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $this->em->flush();

        return $line;
    }

    /**
     * $status defaults to Pending — an ISSUED invoice — because these fixtures exist to exercise the
     * sales snapshot, and a Draft is deliberately not counted by it. Leaving the entity on its own
     * 'Draft' default was how the windowing test below came to assert a figure the panel should
     * never have shown; pass 'Draft' or 'Cancelled' explicitly to exercise the exclusion.
     */
    private function invoiceLineFromOrderLine(SalesOrderLine $orderLine, ?string $documentDate = null, string $status = 'Pending'): InvoiceLine
    {
        $order = $orderLine->getOrder();

        $invoice = (new Invoice())
            ->setCompany($this->company)
            ->setDocumentNumber('HUB-INV-' . uniqid())
            ->setDocumentDate($documentDate ?? '2026-09-15')
            ->setFulfillmentRegion($this->regionName);
        $order->addInvoice($invoice);

        if ($status !== 'Draft') {
            $invoice->setStatus($status, DocumentActor::system());
        }

        $invoiceLine = (new InvoiceLine())
            ->setSalesOrderLine($orderLine)
            ->setProduct($orderLine->getProduct())
            ->setName($orderLine->getName())
            ->setSku($orderLine->getProduct()->getSku())
            ->setQuantity($orderLine->getQuantity());
        $invoice->addLine($invoiceLine);
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoiceLine;
    }

    private function creditMemoFor(ProductCore $product): CreditMemo
    {
        $memo = (new CreditMemo())
            ->setCompany($this->company)
            ->setDocumentNumber('HUB-CM-' . uniqid())
            ->setDocumentDate('2026-09-15')
            ->setFulfillmentRegion($this->regionName)
            ->setSubtotal('10.00')
            ->setTax('0.00')
            ->setTotal('10.00');

        $memo->addLine(
            (new CreditMemoLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setLocation($this->regionName)
                ->setQuantity('2.00')
                ->setPrice('5.00')
                ->setSubtotal('10.00'),
        );

        $this->em->persist($memo);
        $memo->issue();
        $this->em->flush();

        return $memo;
    }

    private function purchaseOrderFor(ProductCore $product): PurchaseOrder
    {
        $vendor = (new Vendor())->setName('Hub Vendor ' . uniqid())->setCurrency('CAD');
        $this->em->persist($vendor);

        $order = (new PurchaseOrder())
            ->setPoNumber('HUB-PO-' . uniqid())
            ->setVendor($vendor)
            ->setWarehouse($this->warehouse)
            ->setCurrency('CAD');
        $this->em->persist($order);

        $line = (new PurchaseOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setQuantityOrdered('6.00')
            ->setUnitCost('1.00')
            ->setSubtotal('6.00');
        $order->addLine($line);
        $this->em->persist($line);
        $this->em->flush();

        return $order;
    }

    private function urlGenerator(): \Symfony\Component\Routing\Generator\UrlGeneratorInterface
    {
        return self::getContainer()->get(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
    }

    /** @param array<string, mixed> $query */
    private function request(array $query = [], string $method = 'GET'): Request
    {
        $request = $method === 'GET'
            ? Request::create('/stock/product', 'GET', $query)
            : Request::create('/stock/product', 'POST', $query);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        return $request;
    }

    /** A POST the controller's note actions recognise as XHR, so they answer JSON instead of a redirect. */
    private function xhrRequest(array $parameters): Request
    {
        $request = Request::create('/stock/product', 'POST', $parameters, [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        return $request;
    }
}
