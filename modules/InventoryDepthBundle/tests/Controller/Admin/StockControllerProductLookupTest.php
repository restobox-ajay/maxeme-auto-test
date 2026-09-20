<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Controller\Admin;

use App\Entity\ProductCore;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Controller\Admin\StockController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The Product Inventory Hub's own front door (docs/plans/2026-09-15-product-inventory-hub.md's
 * stated gap: "I wanted to lookup a SKU and don't have a place to go") — a bare SKU/name search
 * that either lands straight on the hub for an exact SKU, or lists what matched.
 */
final class StockControllerProductLookupTest extends DoctrineIntegrationTestCase
{
    private StockController $controller;
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = self::getContainer()->get(StockController::class);
        $this->requestStack = self::getContainer()->get('request_stack');
    }

    protected function tearDown(): void
    {
        while ($this->requestStack->pop() !== null) {
        }

        parent::tearDown();
    }

    public function testBlankQueryShowsTheSearchFormWithNoResults(): void
    {
        $response = $this->controller->productLookup($this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('No product matches', (string) $response->getContent());
    }

    public function testAnExactSkuMatchGoesStraightToItsHub(): void
    {
        $product = $this->product('LOOKUP-EXACT-1');

        $response = $this->controller->productLookup($this->request(['q' => 'LOOKUP-EXACT-1']));

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/stock/product/' . $product->getId(), (string) $response->getTargetUrl());
    }

    public function testAnExactSkuMatchIsCaseInsensitive(): void
    {
        $product = $this->product('LOOKUP-CASE-1');

        $response = $this->controller->productLookup($this->request(['q' => 'lookup-case-1']));

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/stock/product/' . $product->getId(), (string) $response->getTargetUrl());
    }

    public function testAPartialMatchListsCandidatesInsteadOfGuessing(): void
    {
        $a = $this->product('LOOKUP-PARTIAL-A');
        $b = $this->product('LOOKUP-PARTIAL-B');

        $html = (string) $this->controller->productLookup($this->request(['q' => 'LOOKUP-PARTIAL']))->getContent();

        self::assertStringContainsString($a->getSku(), $html);
        self::assertStringContainsString($b->getSku(), $html);
    }

    /**
     * A query that is an exact SKU for one product AND a partial match for another still redirects —
     * a real, unambiguous exact match should not be second-guessed just because it also happens to be
     * a substring of a different SKU.
     */
    public function testAnExactMatchWinsEvenWhenItIsAlsoASubstringOfAnotherSku(): void
    {
        $exact = $this->product('LOOKUP-PARTIAL-1');
        $this->product('LOOKUP-PARTIAL-10');

        $response = $this->controller->productLookup($this->request(['q' => 'LOOKUP-PARTIAL-1']));

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/stock/product/' . $exact->getId(), (string) $response->getTargetUrl());
    }

    public function testNoMatchSaysSoRatherThanRenderingAnEmptyHub(): void
    {
        $html = (string) $this->controller->productLookup($this->request(['q' => 'NO-SUCH-SKU-AT-ALL']))->getContent();

        self::assertStringContainsString('No product matches', $html);
    }

    public function testSuggestBelowTheCharacterFloorReturnsNoResultsWithoutSearching(): void
    {
        $this->product('SUGGEST-FLOOR-1');

        $response = $this->controller->productLookupSuggest($this->request(['q' => 'S']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['results' => []], json_decode((string) $response->getContent(), true));
    }

    public function testSuggestMatchesBySkuOrName(): void
    {
        $product = $this->product('SUGGEST-MATCH-1');

        $data = json_decode((string) $this->controller->productLookupSuggest($this->request(['q' => 'SUGGEST-MATCH']))->getContent(), true);

        self::assertCount(1, $data['results']);
        self::assertSame($product->getSku(), $data['results'][0]['sku']);
        self::assertStringEndsWith('/stock/product/' . $product->getId(), $data['results'][0]['url']);
    }

    public function testSuggestReturnsNoMoreThanEightRows(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->product('SUGGEST-CAP-' . $i);
        }

        $data = json_decode((string) $this->controller->productLookupSuggest($this->request(['q' => 'SUGGEST-CAP']))->getContent(), true);

        self::assertCount(8, $data['results']);
    }

    private function product(string $sku): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Lookup Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    /** @param array<string, mixed> $query */
    private function request(array $query = []): Request
    {
        $request = Request::create('/admin/bundles/inventory-depth/stock/product', 'GET', $query);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        return $request;
    }
}
