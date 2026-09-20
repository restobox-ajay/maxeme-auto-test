<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Company;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Service\Document\InvoiceLineReconciler;
use App\Service\SalesDocumentLineWarnings;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * Direct coverage of the seams {@see InvoiceLineReconciler} overrides — the handful of rules
 * `InvoiceController::applyLineRows()` had that the base {@see \App\Service\Document\SellSideLineReconciler}
 * (lifted from Order, which always had a persisted line's identity to protect) does not share,
 * because Invoice's own rebuild-from-scratch code never had one either until this conversion.
 */
final class InvoiceLineReconcilerTest extends DoctrineIntegrationTestCase
{
    private function reconciler(): InvoiceLineReconciler
    {
        return self::getContainer()->get(InvoiceLineReconciler::class);
    }

    private function makeProduct(string $sku, float $costPrice = 10.0, float $price = 20.0, ?string $taxCode = null): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Reconciler Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $product->setCostPrice(number_format($costPrice, 4, '.', ''));
        $product->setOriginalPrice(number_format($price, 4, '.', ''));
        $product->setSalesTaxCode($taxCode);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    private function attachToAMinimalInvoice(InvoiceLine $line): void
    {
        $company = new Company();
        $this->em->persist($company);

        $invoice = (new Invoice())->setCompany($company)->setDocumentNumber('RECON-TEST-' . uniqid());
        $invoice->addLine($line);
        $this->em->persist($invoice);
        $this->em->persist($line);
    }

    public function testAProductRowAlwaysTakesTheCatalogNameIgnoringAnyTypedOne(): void
    {
        $product = $this->makeProduct('RECON-INV-NAME');

        $result = $this->reconciler()->reconcile(
            [],
            [['product_id' => (string) $product->getId(), 'name' => 'Something typed', 'qty' => '1']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertSame('Reconciler Test Product', $result['lines'][0]->name, 'a typed name is never read once a product is attached');
    }

    public function testAnExistingLinesBlankLocationStillBacksOntoTheDocumentRegion(): void
    {
        $product = $this->makeProduct('RECON-INV-LOCATION');
        $existing = (new InvoiceLine())->setProduct($product)->setName('Widget')->setQuantity('1.0000')->setPrice('20.0000')->setLocation('Old Warehouse');
        $this->attachToAMinimalInvoice($existing);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $product->getId(), 'qty' => '1', 'location' => '']],
            'East Region',
            new SalesDocumentLineWarnings(),
        );

        self::assertSame('East Region', $result['lines'][0]->location, 'Invoice backs a blank location onto the document region regardless of new/existing, unlike Order/Estimate');
    }

    public function testANonNumericTypedCostFallsBackToTheCatalogRatherThanBeingCoercedToZero(): void
    {
        $product = $this->makeProduct('RECON-INV-COST', costPrice: 42.5);

        $result = $this->reconciler()->reconcile(
            [],
            [['product_id' => (string) $product->getId(), 'qty' => '1', 'cost' => 'not-a-number']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertSame('42.50', $result['lines'][0]->cost, 'garbage input falls back to the catalog cost, not a silent 0');
    }

    public function testABlankTaxCodeUsesTheProductsRawSalesTaxCodeNotTheMappedDefault(): void
    {
        $product = $this->makeProduct('RECON-INV-TAX', taxCode: null);

        $result = $this->reconciler()->reconcile(
            [],
            [['product_id' => (string) $product->getId(), 'qty' => '1']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertNull($result['lines'][0]->taxCode, "a null getSalesTaxCode() is stored raw, not TaxContext::mapTaxCode()'d to 'E'");
    }

    public function testABlankPriceUsesTheProductsOriginalPriceNotTheProductPricingRepositoryLookup(): void
    {
        $product = $this->makeProduct('RECON-INV-PRICE', price: 99.99);

        $result = $this->reconciler()->reconcile(
            [],
            [['product_id' => (string) $product->getId(), 'qty' => '1', 'price' => '']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertEqualsWithDelta(99.99, (float) $result['lines'][0]->price, 0.0001);
    }

    public function testAnExistingLineIsUpsertedNotDuplicated(): void
    {
        $product = $this->makeProduct('RECON-INV-UPSERT');
        $existing = (new InvoiceLine())->setProduct($product)->setName('Widget')->setQuantity('1.0000')->setPrice('20.0000');
        $this->attachToAMinimalInvoice($existing);
        $this->em->flush();

        $result = $this->reconciler()->reconcile(
            [(int) $existing->getId() => $existing],
            [['id' => (string) $existing->getId(), 'product_id' => (string) $product->getId(), 'qty' => '1', 'price' => '20.00']],
            null,
            new SalesDocumentLineWarnings(),
        );

        self::assertCount(1, $result['lines']);
        self::assertSame($existing->getId(), $result['lines'][0]->existingId);
        self::assertArrayHasKey((int) $existing->getId(), $result['keptIds']);
    }
}
