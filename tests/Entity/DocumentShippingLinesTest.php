<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Contract\Tax\TaxCalculatorInterface;
use App\Contract\Tax\TaxContext;
use App\Contract\Tax\TaxLine;
use App\Entity\Cart;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Repository\BundleStatusRepository;
use App\Service\OrderTaxBreakdownService;
use App\Service\SalesDocumentChargeLines;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TaxBundle\Tax\TaxCalculatorResolver;

/**
 * Shipping is rows, not a number (issue #165 step 7).
 *
 * The scalar could hold one figure, so an invoice needing a carrier charge and a fuel surcharge had
 * nowhere to put the second. These pin what replaced it: any number of rows, a figure derived from
 * them and nowhere else (step 8 dropped the cache column that step 7 kept), and a tax figure that
 * has not moved.
 */
final class DocumentShippingLinesTest extends TestCase
{
    public function testADocumentCarriesAsManyShippingRowsAsItWasGiven(): void
    {
        $order = (new SalesOrder())->setFeeLines(FeeLineSnapshot::encode([
            new FeeLine(null, 'handling', 'Handling', 'G', 4.0),
            new FeeLine(null, 'carrier', 'Shipping (Canada Post)', 'G', 22.5, 'main_line', FeeLine::TYPE_SHIPPING, FeeLine::SOURCE_MANUAL),
            new FeeLine(null, 'fuel', 'Fuel surcharge', 'G', 6.25, 'main_line', FeeLine::TYPE_SHIPPING, FeeLine::SOURCE_MANUAL),
            new FeeLine(null, 'tailgate', 'Tailgate delivery', 'G', 15.0, 'main_line', FeeLine::TYPE_SHIPPING, FeeLine::SOURCE_MANUAL),
        ]));

        self::assertSame(
            ['Shipping (Canada Post)', 'Fuel surcharge', 'Tailgate delivery'],
            array_map(static fn (FeeLine $l): string => $l->label, $order->getShippingLines()),
            'the rows come back in the order they were written, labelled exactly as entered',
        );
        self::assertSame(43.75, $order->getShippingTotal());
    }

    /**
     * The rows are the only shipping figure a document has (issue #165 step 8).
     *
     * Step 7 kept a `shipping` column beside them as a cache, restated by setFeeLines(). It is gone:
     * the admin quote list's SQL sort was its only reader, and a second copy of a figure is a second
     * answer waiting to disagree. Asserted by reflection as well as by behaviour, because a
     * re-added getter would be the thing that quietly brings the second answer back.
     */
    public function testTheRowsAreTheOnlyShippingFigureADocumentHas(): void
    {
        $order = (new SalesOrder())->setFeeLines(FeeLineSnapshot::encode([
            new FeeLine(null, 'carrier', 'Shipping (Ground)', 'G', 30.0, 'main_line', FeeLine::TYPE_SHIPPING),
        ]));

        self::assertSame(30.0, $order->getShippingTotal());

        $order->setFeeLines(FeeLineSnapshot::encode([
            new FeeLine(null, 'carrier', 'Shipping (Ground)', 'G', 30.0, 'main_line', FeeLine::TYPE_SHIPPING),
            new FeeLine(null, 'fuel', 'Fuel surcharge', 'G', 5.0, 'main_line', FeeLine::TYPE_SHIPPING),
        ]));

        self::assertSame(35.0, $order->getShippingTotal());

        foreach ([SalesOrder::class, Estimate::class, Cart::class] as $document) {
            $reflection = new \ReflectionClass($document);
            self::assertFalse($reflection->hasProperty('shipping'), $document . ' still has a $shipping column');
            self::assertFalse($reflection->hasProperty('extraCharges'), $document . ' still has an $extraCharges column');
            self::assertFalse($reflection->hasMethod('getShipping'), $document . '::getShipping() is back');
            self::assertFalse($reflection->hasMethod('setShipping'), $document . '::setShipping() is back');
            self::assertFalse($reflection->hasMethod('getExtraCharges'), $document . '::getExtraCharges() is back');
            self::assertFalse($reflection->hasMethod('setExtraCharges'), $document . '::setExtraCharges() is back');
        }
    }

    /** "No shipping rows" is unstated on every document type, not $0 on some of them. */
    public function testADocumentWithNoShippingRowsStatesNoShippingFigureAtAll(): void
    {
        $order = (new SalesOrder())->setFeeLines(FeeLineSnapshot::encode([
            new FeeLine(null, 'handling', 'Handling', 'G', 4.0),
        ]));

        self::assertNull($order->getShippingTotal());
        self::assertSame([], $order->getShippingLines());
    }

    /**
     * An estimate is the one document allowed to leave shipping unstated, and no rows is how it
     * says so. A quote that ships for nothing says that with a $0 row instead.
     *
     * This distinction used to need a nullable column and an Estimate-only shippingCacheValue()
     * override to survive. Asking the rows, it is simply what the rows say — which is why both
     * halves are pinned here now that both are gone.
     */
    public function testAnEstimateWithNoShippingRowsIsTbdAndAZeroRowIsNot(): void
    {
        $tbd = (new Estimate())->setFeeLines(null);
        self::assertNull($tbd->getShippingTotal(), 'no rows is TBD');
        self::assertFalse($tbd->isFullyPriced());

        $free = (new Estimate())->setFeeLines(FeeLineSnapshot::encode([
            new FeeLine(null, 'shipping', 'Shipping (Pickup)', 'E', 0.0, 'main_line', FeeLine::TYPE_SHIPPING),
        ]));
        self::assertSame(0.0, $free->getShippingTotal(), 'a $0 row is a stated $0, not TBD');
        self::assertNotSame(null, $free->getShippingTotal());
        self::assertCount(1, $free->getShippingLines());
    }

    /** Cart, estimate and order answer the same way — a calculator cannot tell them apart. */
    public function testEveryDocumentTypeAnswersTheSameWay(): void
    {
        $json = FeeLineSnapshot::encode([
            new FeeLine(null, 'shipping', 'Shipping (Ground)', 'G', 12.0, 'main_line', FeeLine::TYPE_SHIPPING),
        ]);

        foreach ([new SalesOrder(), new Estimate(), (new Cart())->setSessionId('sess-1')] as $document) {
            $document->setFeeLines($json);
            self::assertSame(12.0, $document->getShippingTotal(), $document::class);
            self::assertCount(1, $document->getShippingLines(), $document::class);
        }
    }

    /**
     * Tax on shipping is unchanged by the move. It used to come from a branch of its own that taxed
     * the scalar at the highest class across the document's lines; it now comes from the rows,
     * which SalesDocumentChargeLines::toShippingLines() gives that same class. Both routes are
     * computed here off one document and asserted equal.
     */
    public function testTaxOnShippingIsWhatTheScalarBranchUsedToProduce(): void
    {
        $service = new OrderTaxBreakdownService($this->resolver(new OnlyForClassFlatTax('S', 'PST', 0.07)), new NullLogger());

        $order = (new SalesOrder())->setCompany(new Company());
        $order->addressForWriting('shipping')->setProvince('BC');
        $order->addLine((new SalesOrderLine())->setName('Exempt')->setSubtotal('100.00')->setTaxCode('E'));
        $order->addLine((new SalesOrderLine())->setName('Taxable')->setSubtotal('100.00')->setTaxCode('S'));

        // What the retired branch did: one TaxContext over the shipping amount at the document's
        // highest tax class.
        $scalarRoute = 50.0 * 0.07;

        $order->setFeeLines(FeeLineSnapshot::encode(
            SalesDocumentChargeLines::toShippingLines(
                [['label' => 'Shipping (Ground)', 'amount' => 50.0, 'type' => 'shipping', 'slug' => '']],
                $order->getHighestTaxClass(),
            ),
        ));

        $withShipping = $service->buildForOrder($order)['total'];
        $withoutShipping = $service->computeBreakdownFor($order, [])['total'];

        self::assertSame('S', $order->getHighestTaxClass());
        self::assertEqualsWithDelta($scalarRoute, $withShipping - $withoutShipping, 0.001);
    }

    private function resolver(TaxCalculatorInterface $calculator): TaxCalculatorResolver
    {
        $bundleStatus = $this->createStub(BundleStatusRepository::class);
        $bundleStatus->method('isActiveForInstance')->willReturn(true);

        return new TaxCalculatorResolver([$calculator], [], $bundleStatus);
    }
}

/** Taxes only one class, so a wrong class silently produces no line rather than a wrong figure. */
final class OnlyForClassFlatTax implements TaxCalculatorInterface
{
    public function __construct(
        private readonly string $taxClass,
        private readonly string $label,
        private readonly float $rate,
    ) {}

    public function supports(TaxContext $context): bool
    {
        return $context->taxClass === $this->taxClass;
    }

    public function calculate(TaxContext $context): array
    {
        return [new TaxLine($this->label, $this->rate, round($context->subtotal * $this->rate, 2))];
    }
}
