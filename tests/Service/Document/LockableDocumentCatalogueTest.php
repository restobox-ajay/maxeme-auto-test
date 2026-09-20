<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\CustomFieldValueOrder;
use App\Entity\EstimateAddress;
use App\Entity\EstimateLine;
use App\Entity\InvoiceAddress;
use App\Entity\InvoiceLine;
use App\Entity\InvoiceLog;
use App\Entity\InvoicePaymentApplication;
use App\Entity\SalesOrderAddress;
use App\Entity\SalesOrderLine;
use App\Service\Document\LockableDocumentCatalogue;
use ProcurementBundle\Entity\PurchaseOrderAddress;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\VendorBillAddress;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Entity\VendorBillPaymentApplication;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `LockableDocumentCatalogue` resolves every registered document and every owner class (#759).
 *
 * Complements the conducted `ProcurementDocumentLockCest`, which proves the header and ONE line
 * class per document are frozen through the real routes and a real flush. This covers the mapping
 * directly, cheaply, for every owner class — including `VendorBillPaymentApplication` and
 * `InvoicePaymentApplication`, which would need a full payment pool fixture to exercise end to end
 * and add nothing a direct assertion on the declared mapping does not already prove.
 */
final class LockableDocumentCatalogueTest extends KernelTestCase
{
    private function catalogue(): LockableDocumentCatalogue
    {
        self::bootKernel();

        return self::getContainer()->get(LockableDocumentCatalogue::class);
    }

    public function testCoreAndBundleDocumentsAreBothRegistered(): void
    {
        $keys = array_map(static fn ($doc) => $doc->typeKey, $this->catalogue()->all());

        foreach (['estimate', 'sales_order', 'invoice', 'purchase_order', 'vendor_bill'] as $expected) {
            self::assertContains($expected, $keys, sprintf('"%s" is not a registered lockable document type.', $expected));
        }
    }

    public function testPurchaseOrderAndVendorBillResolveToTheRightClasses(): void
    {
        $catalogue = $this->catalogue();

        $po = $catalogue->forType('purchase_order');
        self::assertNotNull($po);
        self::assertSame('ProcurementBundle\Entity\PurchaseOrder', $po->documentClass);
        self::assertSame('getDocumentNumber', $po->numberAccessor);

        $bill = $catalogue->forType('vendor_bill');
        self::assertNotNull($bill);
        self::assertSame('ProcurementBundle\Entity\VendorBill', $bill->documentClass);
    }

    /**
     * Every child entity that must be frozen alongside its document — the full `$owners` set per
     * document, not just the one line class the conducted Cest drives through a real flush.
     */
    public function testEveryOwnerClassIsGuarded(): void
    {
        $catalogue = $this->catalogue();

        $owned = [
            EstimateLine::class,
            EstimateAddress::class,
            SalesOrderLine::class,
            SalesOrderAddress::class,
            CustomFieldValueOrder::class,
            InvoiceLine::class,
            InvoiceAddress::class,
            InvoicePaymentApplication::class,
            PurchaseOrderLine::class,
            PurchaseOrderAddress::class,
            VendorBillLine::class,
            VendorBillAddress::class,
            VendorBillPaymentApplication::class,
        ];

        foreach ($owned as $class) {
            self::assertTrue($catalogue->guardsCollectionOf($class), sprintf('%s is not guarded by any lockable document.', $class));
        }
    }

    /** A log/audit entity must NOT be guarded — a locked document must still record its own timeline. */
    public function testALogEntityIsNotGuarded(): void
    {
        self::assertFalse($this->catalogue()->guardsCollectionOf(InvoiceLog::class));
    }
}
