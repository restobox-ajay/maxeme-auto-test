<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\SalesOrderLine;
use App\Entity\TrackingPolicy;
use App\Service\DocumentActor;
use App\Status\CoreStatusVocabularyProvider;
use App\Status\StatusVocabularyLoader;
use App\Status\StatusVocabularyRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Section 5 of the 2026-09-14 lot/serial/expiry plan: a non-draft Invoice must error if a line
 * whose product requires lot/serial capture on the way out does not have it — unless the line is
 * still exempt as backordered.
 *
 * In-memory, like InvoiceTransitionsTest: MandatoryCaptureGuard reads only the invoice's own line
 * graph, so there is nothing here that needs a database.
 */
final class InvoiceMandatoryCaptureTest extends TestCase
{
    protected function setUp(): void
    {
        StatusVocabularyRegistry::use(new StatusVocabularyLoader([new CoreStatusVocabularyProvider()]));
    }

    protected function tearDown(): void
    {
        StatusVocabularyRegistry::reset();
    }

    private static function actor(): DocumentActor
    {
        return DocumentActor::system();
    }

    private function lotTrackedProduct(): ProductCore
    {
        $policy = (new TrackingPolicy())->setName('Lot')->setMode(TrackingPolicy::MODE_LOT)->setTrackOut(true);

        return (new ProductCore())->setSku('LOT-1')->setName('Lot Tracked Widget')->setTrackingPolicy($policy);
    }

    public function testIssuingRefusesALineMissingItsRequiredLot(): void
    {
        $invoice = new Invoice();
        $invoice->addLine((new InvoiceLine())->setProduct($this->lotTrackedProduct())->setName('Lot Tracked Widget')->setQuantity('5'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/requires a lot to be recorded/');

        $invoice->issue(self::actor());
    }

    public function testIssuingSucceedsOnceTheLotIsRecorded(): void
    {
        $invoice = new Invoice();
        $invoice->addLine(
            (new InvoiceLine())->setProduct($this->lotTrackedProduct())->setName('Lot Tracked Widget')->setQuantity('5')->setLotId(42),
        );

        self::assertSame('Pending', $invoice->issue(self::actor())->getStatus());
    }

    /**
     * The backorder carve-out: a line still held against its order line's backordered units has no
     * real stock to pick a lot from, so it is exempt — read straight off the order line, with no new
     * coupling into BackorderReleaseService.
     */
    public function testABackorderedLineIsExemptFromTheCaptureCheck(): void
    {
        $product = $this->lotTrackedProduct();
        $orderLine = (new SalesOrderLine())->setProduct($product)->setName($product->getName())->setQuantity('5')->setBackorderedQuantity('5.00');

        $invoice = new Invoice();
        $invoice->addLine(
            (new InvoiceLine())->setProduct($product)->setName($product->getName())->setQuantity('5')->setSalesOrderLine($orderLine),
        );

        self::assertSame('Pending', $invoice->issue(self::actor())->getStatus());
    }

    /**
     * The moment BackorderReleaseService does what it already does today — shrinking the order
     * line's backordered units to zero — the exemption disappears: the same invoice, still
     * unissued, now needs the data before it can leave Draft.
     */
    public function testTheExemptionDisappearsTheMomentTheLineIsReleasedFromBackorder(): void
    {
        $product = $this->lotTrackedProduct();
        $orderLine = (new SalesOrderLine())->setProduct($product)->setName($product->getName())->setQuantity('5')->setBackorderedQuantity('5.00');

        $invoice = new Invoice();
        $invoice->addLine(
            (new InvoiceLine())->setProduct($product)->setName($product->getName())->setQuantity('5')->setSalesOrderLine($orderLine),
        );

        // BackorderReleaseService::applyRelease()'s own effect: the order line's backordered units
        // shrink to zero. Nothing about the invoice or this guard is touched to make that happen.
        $orderLine->setBackorderedQuantity('0.00');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/requires a lot to be recorded/');

        $invoice->issue(self::actor());
    }

    public function testAProductNobodyHasOptedIntoTrackingIsNeverRefused(): void
    {
        $invoice = new Invoice();
        // The default 'None' TrackingPolicy — no policy at all here, which reads the same way.
        $invoice->addLine((new InvoiceLine())->setProduct(new ProductCore())->setName('Untracked Widget')->setQuantity('5'));

        self::assertSame('Pending', $invoice->issue(self::actor())->getStatus());
    }
}
