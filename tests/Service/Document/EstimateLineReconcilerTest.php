<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\ProductCore;
use App\Service\Document\EstimateLineReconciler;
use App\Service\SalesDocumentLineWarnings;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * Direct coverage of the seams {@see EstimateLineReconciler} overrides — the behaviors that made
 * Estimate's original controller code diverge from Order/Invoice on purpose, which the shared
 * {@see \App\Service\Document\SellSideLineReconciler} base class must still reproduce exactly.
 */
final class EstimateLineReconcilerTest extends DoctrineIntegrationTestCase
{
    private function reconciler(): EstimateLineReconciler
    {
        return self::getContainer()->get(EstimateLineReconciler::class);
    }

    private function makeProduct(string $sku, float $costPrice = 10.0, float $price = 20.0): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Reconciler Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $product->setCostPrice(number_format($costPrice, 4, '.', ''));
        $product->setOriginalPrice(number_format($price, 4, '.', ''));
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function attachToAMinimalEstimate(EstimateLine $line): void
    {
        $company = new Company();
        $this->em->persist($company);

        $estimate = (new Estimate())->setCompany($company)->setDocumentNumber('RECON-TEST-' . uniqid());
        $estimate->addLine($line);
        $this->em->persist($estimate);
        $this->em->persist($line);
    }

    public function testClearingThePriceOnAnUnchangedProductLineIsTbdNotZero(): void
    {
        $product = $this->makeProduct('RECON-EST-TBD');
        $existing = (new EstimateLine())->setProduct($product)->setName('Widget')->setQuantity('1.0000')->setPrice('20.0000')->setCost('10.00');
        $this->attachToAMinimalEstimate($existing);
        $this->em->flush();

        // Same product as already stored (not "just attached"), price actively cleared: typed ''
        // against a DIFFERENT rendered figure, so boxUntouched does not preserve the stored rate.
        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $product->getId(), 'qty' => '1', 'price' => '', 'price_rendered' => '20.00']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertFalse($result['allPriced']);
        self::assertNull($result['lines'][0]->price, 'clearing the price on a line that already has its product still means no pricing, not $0.00');
    }

    public function testABlankRowNamingAnExistingLineSurvivesUnlikeOrder(): void
    {
        $product = $this->makeProduct('RECON-EST-BLANK-EXISTING');
        $existing = (new EstimateLine())->setProduct(null)->setName('Widget')->setQuantity('2.0000')->setPrice('50.0000')->setCost('0.00');
        $this->attachToAMinimalEstimate($existing);
        $this->em->flush();

        // Names the existing line's id but carries no product_id and no name — the exact shape a
        // spare/never-touched row in the middle of the grid posts back as.
        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => '', 'qty' => '2', 'price' => '50.00']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertCount(1, $result['lines'], 'a row naming a real existing line must never be dropped as blank, even with no product/name of its own');
        self::assertSame($existing->getId(), $result['lines'][0]->existingId);
        self::assertArrayHasKey((int) $existing->getId(), $result['keptIds']);
    }

    public function testAnExistingLinesCostSurvivesUntouchedWhenTheProductDoesNotChange(): void
    {
        $product = $this->makeProduct('RECON-EST-COST-KEEP', costPrice: 42.5);
        $existing = (new EstimateLine())->setProduct($product)->setName('Widget')->setQuantity('1.0000')->setPrice('20.0000')->setCost('7.77');
        $this->attachToAMinimalEstimate($existing);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $product->getId(), 'qty' => '1', 'cost' => '']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertSame('7.77', $result['lines'][0]->cost, "a quote's line is not silently re-costed to today's catalog on an unrelated save");
    }

    public function testReattachingADifferentProductReSnapshotsCostAndSku(): void
    {
        $oldProduct = $this->makeProduct('RECON-EST-OLD', costPrice: 7.77);
        $newProduct = $this->makeProduct('RECON-EST-NEW', costPrice: 42.5);
        $existing = (new EstimateLine())->setProduct($oldProduct)->setName('Widget')->setQuantity('1.0000')->setPrice('20.0000')->setCost('7.77')->setSku($oldProduct->getSku());
        $this->attachToAMinimalEstimate($existing);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $newProduct->getId(), 'qty' => '1', 'cost' => '']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertSame('42.50', $result['lines'][0]->cost, 'a just-attached product re-snapshots cost from todays catalog');
        self::assertSame('RECON-EST-NEW', $result['lines'][0]->sku);
    }
}
