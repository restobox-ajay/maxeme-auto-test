<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Company;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\Document\SellSideLineReconciler;
use App\Service\SalesDocumentLineWarnings;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * Direct coverage of SellSideLineReconciler, extracted from OrderController::edit()'s own line
 * loop — this is the class the plan's Phase 0 moves Order, then Estimate, then Invoice onto, so it
 * has to prove the exact behaviors those controllers' own Cests only prove indirectly through a
 * full HTTP save.
 */
final class SellSideLineReconcilerTest extends DoctrineIntegrationTestCase
{
    private function reconciler(): SellSideLineReconciler
    {
        return self::getContainer()->get(SellSideLineReconciler::class);
    }

    private function makeProduct(string $sku, float $costPrice = 10.0): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Reconciler Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $product->setCostPrice(number_format($costPrice, 4, '.', ''));
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    /** SalesOrderLine::$order must be initialized before flush (DocumentLockFlushGuard reads it). */
    private function attachToAMinimalOrder(SalesOrderLine $line): void
    {
        $company = new Company();
        $this->em->persist($company);

        $order = (new SalesOrder())->setCompany($company)->setOrderNumber('RECON-TEST-' . uniqid());
        $order->addLine($line);
        $this->em->persist($order);
        $this->em->persist($line);
    }

    public function testANewRowWithNoIdBecomesANewLine(): void
    {
        $product = $this->makeProduct('RECON-NEW-1');

        $result = $this->reconciler()->reconcile(
            [],
            [['product_id' => (string) $product->getId(), 'qty' => '5', 'price' => '12.50']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertCount(1, $result['lines']);
        $line = $result['lines'][0];
        self::assertNull($line->existingId, 'no id in the row means a brand new line');
        self::assertSame($product->getId(), $line->product?->getId());
        self::assertEqualsWithDelta(5.0, $line->baseQuantity, 0.0001);
        self::assertEqualsWithDelta(12.50, (float) $line->price, 0.0001);
    }

    public function testARowNamingAnExistingIdIsMatchedNotDuplicated(): void
    {
        $product = $this->makeProduct('RECON-MATCH-1');
        $existing = (new SalesOrderLine())->setProduct($product)->setName('Existing')->setQuantity('3.0000')->setPrice('9.0000');
        $this->attachToAMinimalOrder($existing);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $product->getId(), 'qty' => '3', 'qty_rendered' => '3', 'price' => '9.0000', 'price_rendered' => '9.0000']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertCount(1, $result['lines']);
        self::assertSame($existing->getId(), $result['lines'][0]->existingId);
        self::assertArrayHasKey((int) $existing->getId(), $result['keptIds']);
    }

    public function testAnUntouchedQuantityBoxKeepsTheStoredFigureNotTheRenderedOne(): void
    {
        $product = $this->makeProduct('RECON-BOXUNTOUCHED-QTY');
        $existing = (new SalesOrderLine())->setProduct($product)->setName('Existing')->setQuantity('7.0000')->setPrice('1.0000');
        $this->attachToAMinimalOrder($existing);
        $this->em->flush();

        // qty === qty_rendered: the box came back exactly as rendered, i.e. untouched.
        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $product->getId(), 'qty' => '7', 'qty_rendered' => '7', 'price' => '1.0000', 'price_rendered' => '1.0000']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertEqualsWithDelta(7.0, $result['lines'][0]->baseQuantity, 0.0001, 'boxUntouched must read the STORED quantity back, not reinterpret the posted figure');
    }

    public function testABlankCostFallsBackToTheCatalogCostPrice(): void
    {
        $product = $this->makeProduct('RECON-COST-DEFAULT', costPrice: 42.5);

        $result = $this->reconciler()->reconcile(
            [],
            [['product_id' => (string) $product->getId(), 'qty' => '1', 'cost' => '']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertEqualsWithDelta(42.5, (float) $result['lines'][0]->cost, 0.0001);
    }

    public function testATypedCostWinsOverTheCatalogDefault(): void
    {
        $product = $this->makeProduct('RECON-COST-TYPED', costPrice: 42.5);

        $result = $this->reconciler()->reconcile(
            [],
            [['product_id' => (string) $product->getId(), 'qty' => '1', 'cost' => '5.00']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertEqualsWithDelta(5.0, (float) $result['lines'][0]->cost, 0.0001);
    }

    public function testANewLinesBlankLocationFallsBackToTheDocumentsFulfillmentRegion(): void
    {
        $product = $this->makeProduct('RECON-LOCATION-NEW');

        $result = $this->reconciler()->reconcile(
            [],
            [['product_id' => (string) $product->getId(), 'qty' => '1', 'location' => '']],
            'West Region',
            new SalesDocumentLineWarnings(),
        );

        self::assertSame('West Region', $result['lines'][0]->location);
    }

    public function testAnExistingLinesBlankLocationStaysNullRatherThanBackfilling(): void
    {
        $product = $this->makeProduct('RECON-LOCATION-EXISTING');
        $existing = (new SalesOrderLine())->setProduct($product)->setName('Existing')->setQuantity('1.0000')->setPrice('1.0000')->setLocation(null);
        $this->attachToAMinimalOrder($existing);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $product->getId(), 'qty' => '1', 'location' => '']],
            'West Region',
            new SalesDocumentLineWarnings(),
        );

        self::assertNull($result['lines'][0]->location, 'a deliberate blank on an EXISTING line must not be quietly backfilled with the document region');
    }

    public function testABlankOrderLineIsSkippedEntirely(): void
    {
        $result = $this->reconciler()->reconcile(
            [],
            [['product_id' => '', 'product_id_manual' => '', 'name' => '', 'qty' => '5']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertCount(0, $result['lines'], 'a row naming neither a product nor a name is a spare row, not a line');
    }

    public function testABlankRowNamingAnExistingLineIsStillSkippedForOrder(): void
    {
        $product = $this->makeProduct('RECON-BLANK-EXISTING');
        $existing = (new SalesOrderLine())->setProduct($product)->setName('Widget')->setQuantity('2.0000')->setPrice('50.0000');
        $this->attachToAMinimalOrder($existing);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => '', 'product_id_manual' => '', 'name' => '', 'qty' => '2']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertCount(0, $result['lines'], 'Order has no existing-line carve-out — a blank row is blank whether or not it names an id (see EstimateLineReconciler for the one document that differs)');
    }

    public function testARowWhoseIdBelongsToNoExistingLineBecomesNewRatherThanTamperable(): void
    {
        $product = $this->makeProduct('RECON-TAMPERED-ID');

        // id=999999 names no line in the (empty) existing-lines map handed in.
        $result = $this->reconciler()->reconcile(
            [],
            [['id' => '999999', 'product_id' => (string) $product->getId(), 'qty' => '1']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertNull($result['lines'][0]->existingId, 'an id this caller did not hand in as an existing line must not be trusted');
    }
}
