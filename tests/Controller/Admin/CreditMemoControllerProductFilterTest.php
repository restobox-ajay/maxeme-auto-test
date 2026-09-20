<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\CreditMemoController;
use App\Entity\Company;
use App\Entity\CreditMemo;
use App\Entity\CreditMemoLine;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Product Inventory Hub build order step 1: `admin_credit_memo_index` never joined `CreditMemoLine`
 * before this, so there was no product filter of any kind — not even a name/SKU LIKE the way Orders
 * had. Straight addition, exact id match, same discipline as the sibling filters.
 */
final class CreditMemoControllerProductFilterTest extends DoctrineIntegrationTestCase
{
    private CreditMemoController $controller;
    private RequestStack $requestStack;
    private Company $company;
    private string $regionName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = self::getContainer()->get(CreditMemoController::class);
        $this->requestStack = self::getContainer()->get('request_stack');

        $region = (new FulfillmentRegion())->setName('Credit Filter Region');
        $this->em->persist($region);
        $this->regionName = $region->getName();

        $this->company = (new Company())->setName('Credit Filter Co');
        $this->em->persist($this->company);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        while ($this->requestStack->pop() !== null) {
        }

        parent::tearDown();
    }

    public function testFiltersCreditMemosDownToTheOneProduct(): void
    {
        $matching = $this->standaloneMemoFor($this->product('CM-MATCH-SKU'));
        $this->standaloneMemoFor($this->product('CM-OTHER-SKU'));

        $productId = (string) $matching->getLines()->first()->getProduct()->getId();

        $response = $this->controller->index($this->request(['product' => $productId]), $this->em);

        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(1, $payload['total']);
        self::assertStringContainsString($matching->getDocumentNumber(), $payload['html']);
    }

    public function testANonNumericProductFilterIsIgnored(): void
    {
        $this->standaloneMemoFor($this->product('CM-IGNORE-SKU'));

        $response = $this->controller->index($this->request(['product' => 'not-a-number']), $this->em);

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

    private function standaloneMemoFor(ProductCore $product): CreditMemo
    {
        $memo = (new CreditMemo())
            ->setCompany($this->company)
            ->setDocumentNumber('CM-FILTER-' . uniqid())
            ->setDocumentDate('2026-09-15')
            ->setFulfillmentRegion($this->regionName)
            ->setSubtotal('25.00')
            ->setTax('0.00')
            ->setTotal('25.00');

        $memo->addLine(
            (new CreditMemoLine())
                ->setProduct($product)
                ->setName($product->getName())
                ->setLocation($this->regionName)
                ->setQuantity('5.00')
                ->setPrice('5.00')
                ->setSubtotal('25.00'),
        );

        $this->em->persist($memo);
        $memo->issue();
        $this->em->flush();

        return $memo;
    }

    private function request(array $query): Request
    {
        $request = Request::create('/credit-memo/index', 'GET', $query);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $this->requestStack->push($request);

        return $request;
    }
}
