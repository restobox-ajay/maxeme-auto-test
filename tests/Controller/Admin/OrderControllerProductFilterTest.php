<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\OrderController;
use App\Entity\Company;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\OrderPaymentRollup;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Product Inventory Hub build order step 1: `admin_order_index` had no product filter at all before
 * this — the "View more" link the hub's Recent Activity feed wants for its Orders row needs one, an
 * exact id match rather than a name/SKU LIKE, per the Company-screen discipline documented in
 * docs/plans/2026-09-15-product-inventory-hub.md (a LIKE match can silently widen to a different
 * product the same way it can widen to a different company).
 */
final class OrderControllerProductFilterTest extends DoctrineIntegrationTestCase
{
    private OrderController $controller;
    private OrderPaymentRollup $paymentRollup;
    private RequestStack $requestStack;
    private Company $company;
    private string $regionName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = self::getContainer()->get(OrderController::class);
        $this->paymentRollup = self::getContainer()->get(OrderPaymentRollup::class);
        $this->requestStack = self::getContainer()->get('request_stack');

        $region = (new FulfillmentRegion())->setName('Filter Region');
        $this->em->persist($region);
        $this->regionName = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA')->getName();

        $this->company = (new Company())->setName('Filter Co');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        while ($this->requestStack->pop() !== null) {
        }

        parent::tearDown();
    }

    public function testFiltersOrdersDownToTheOneProduct(): void
    {
        $matching = $this->orderFor($this->product('MATCH-SKU'));
        $this->orderFor($this->product('OTHER-SKU'));

        $productId = (string) $matching->getLines()->first()->getProduct()->getId();

        $response = $this->controller->orders(
            $this->request(['filters' => ['product' => $productId]]),
            $this->em,
            $this->paymentRollup,
        );

        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(1, $payload['total']);
        self::assertStringContainsString($matching->getOrderNumber(), $payload['html']);
    }

    /** A filter value nobody typed a real id into is not a scope — same rule as every id filter here. */
    public function testANonNumericProductFilterIsIgnored(): void
    {
        $this->orderFor($this->product('IGNORE-SKU'));

        $response = $this->controller->orders(
            $this->request(['filters' => ['product' => 'not-a-number']]),
            $this->em,
            $this->paymentRollup,
        );

        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(1, $payload['total']);
    }

    private function product(string $sku): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function orderFor(ProductCore $product): SalesOrder
    {
        $order = (new SalesOrder())
            ->setCompany($this->company)
            ->setOrderNumber('FILTER-ORD-' . uniqid())
            ->setDocumentDate('2026-09-15')
            ->setFulfillmentRegion($this->regionName);
        $this->em->persist($order);

        $line = (new SalesOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setQuantity('1.00')
            ->setLocation($this->regionName);
        $order->addLine($line);
        $this->em->flush();

        return $order;
    }

    private function request(array $query): Request
    {
        $request = Request::create('/order', 'GET', $query);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $this->requestStack->push($request);

        return $request;
    }
}
