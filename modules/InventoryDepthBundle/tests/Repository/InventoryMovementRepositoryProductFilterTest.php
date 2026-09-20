<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Repository;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryMovementRepository;

/**
 * Product Inventory Hub build order step 1: `filteredQueryBuilder()`'s existing `product` key is the
 * Movement History screen's own human-typed "SKU or name" search box (movements.html.twig) — a LIKE
 * match, which is exactly the ambiguity the Company screen's name-LIKE gap has for a different
 * entity (one SKU can be a substring of another). `productId` is the new, separate, exact-match key
 * the hub's "View more" link uses instead of repurposing that box.
 */
final class InventoryMovementRepositoryProductFilterTest extends DoctrineIntegrationTestCase
{
    private StockMovementService $movements;
    private InventoryMovementRepository $repository;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->movements = self::getContainer()->get(StockMovementService::class);
        $this->repository = self::getContainer()->get(InventoryMovementRepository::class);

        $region = (new FulfillmentRegion())->setName('Movement Filter Region');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');
    }

    public function testProductIdFiltersToExactlyThatProductsMovements(): void
    {
        $matching = $this->product('MOVE-1', 'Widget One');
        $other = $this->product('MOVE-10', 'Widget Ten');

        $this->receive($matching, 5);
        $this->receive($other, 7);

        $rows = $this->repository->filteredQueryBuilder(['productId' => (string) $matching->getId()])
            ->getQuery()->getResult();

        self::assertCount(1, $rows);
        self::assertSame($matching->getId(), $rows[0]->getProduct()->getId());
    }

    /**
     * The precision the id filter exists for: a LIKE match on "MOVE-1" also matches "MOVE-10", which
     * is the trap the exact-id filter must not reproduce.
     */
    public function testProductIdDoesNotFallIntoTheSubstringTrapTheTextFilterHas(): void
    {
        $short = $this->product('MOVE-1', 'Widget One');
        $long = $this->product('MOVE-10', 'Widget Ten');

        $this->receive($short, 5);
        $this->receive($long, 7);

        $textRows = $this->repository->filteredQueryBuilder(['product' => 'MOVE-1'])->getQuery()->getResult();
        self::assertCount(2, $textRows, 'the pre-existing text filter is a LIKE match and is expected to catch both');

        $idRows = $this->repository->filteredQueryBuilder(['productId' => (string) $short->getId()])->getQuery()->getResult();
        self::assertCount(1, $idRows, 'the new id filter must not have the same substring ambiguity');
        self::assertSame($short->getId(), $idRows[0]->getProduct()->getId());
    }

    public function testANonNumericProductIdIsIgnored(): void
    {
        $product = $this->product('MOVE-IGNORE', 'Ignored Widget');
        $this->receive($product, 3);

        $rows = $this->repository->filteredQueryBuilder(['productId' => 'not-a-number'])->getQuery()->getResult();

        self::assertCount(1, $rows);
    }

    private function product(string $sku, string $name): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku)
            ->setName($name)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function receive(ProductCore $product, int $quantity): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'filter-test-' . uniqid())
                ->receive($product, new DetailKey($this->warehouse), $quantity),
        );
    }
}
