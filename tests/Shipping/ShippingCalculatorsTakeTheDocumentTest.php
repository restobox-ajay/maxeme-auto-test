<?php

declare(strict_types=1);

namespace App\Tests\Shipping;

use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Entity\AbstractDocumentAddress;
use App\Entity\AbstractSalesDocument;
use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomFieldDefinition;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Repository\CustomFieldValueRepository;
use App\Tests\DoctrineIntegrationTestCase;
use ShippingAmazonFBABundle\Shipping\AmazonFBAShippingCalculator;
use ShippingArrangementBundle\Shipping\ArrangementShippingCalculator;
use ShippingBulkBundle\Shipping\BulkShippingCalculator;
use ShippingCanadaPostBundle\Shipping\CanadaPostShippingCalculator;
use ShippingFreeBundle\Shipping\FreeShippingCalculator;
use ShippingNeedQuoteBundle\Shipping\NeedQuoteShippingCalculator;
use ShippingPickupBundle\Shipping\PickupShippingCalculator;

/**
 * Shipping calculators are handed the document being shipped, not a ShippingContext (issue #165
 * step 9). The context is deleted, so what used to be five flattened fields is now five questions
 * asked of a Cart, a SalesOrder or an Estimate.
 *
 * The claim the issue makes is that a calculator must not be able to tell those three apart, so
 * every calculator is run against all three carrying the same rows and the answers are compared.
 * These are the real bundle calculators, not stand-ins: what regresses if this breaks is the amount
 * a customer is charged for delivery.
 */
final class ShippingCalculatorsTakeTheDocumentTest extends DoctrineIntegrationTestCase
{
    // --- one answer, whatever the document is ----------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function documentKinds(): iterable
    {
        yield 'cart' => ['cart'];
        yield 'order' => ['order'];
        yield 'estimate' => ['estimate'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('documentKinds')]
    public function testEveryCalculatorAnswersTheSameForACartAnOrderAndAnEstimate(string $kind): void
    {
        // 3 x WIDGET (taxable, ordinary shipping class) + 2 x GADGET (exempt) — 5 units in total,
        // which is what the per-unit carriers price off.
        $document = $this->documentWithTwoRows($kind);

        $canadaPost = new CanadaPostShippingCalculator();
        self::assertTrue($canadaPost->supports($document), $kind);
        $options = $canadaPost->getOptions($document);
        self::assertCount(2, $options, $kind);
        self::assertEqualsWithDelta(10.0 + 2.5 * 5, $options[0]->amount, 0.001, $kind . ': ground, 5 units');
        self::assertEqualsWithDelta(21.84 + 4.5 * 5, $options[1]->amount, 0.001, $kind . ': express, 5 units');
        self::assertSame('G', $options[0]->taxClass, $kind . ': G beats E across the two rows');

        // No bulk row, so bulk does not offer; Canada Post does. That pair is the whole routing rule.
        self::assertFalse((new BulkShippingCalculator())->supports($document), $kind);

        foreach ([new FreeShippingCalculator(), new NeedQuoteShippingCalculator(), new PickupShippingCalculator()] as $calculator) {
            self::assertTrue($calculator->supports($document), $kind . ': ' . $calculator::class);
            self::assertSame('G', $calculator->getOptions($document)[0]->taxClass, $kind . ': ' . $calculator::class);
        }
    }

    public function testBulkShippingReadsTheDocumentsNormalisedProvince(): void
    {
        // The rate table is keyed on codes. A document whose address still holds a legacy display
        // name has to reach it as 'BC', or the order is quietly billed the $1000 catch-all rate.
        $order = $this->documentWithTwoRows('order', province: 'British Columbia');
        $this->addBulkRow($order);

        $calculator = new BulkShippingCalculator();

        self::assertTrue($calculator->supports($order));
        self::assertEqualsWithDelta(250.0, $calculator->getOptions($order)[0]->amount, 0.001);
    }

    public function testBulkShippingFallsBackToTheDefaultRateForAProvinceItHasNoRateFor(): void
    {
        $order = $this->documentWithTwoRows('order', province: 'Washington');
        $this->addBulkRow($order);

        self::assertEqualsWithDelta(1000.0, (new BulkShippingCalculator())->getOptions($order)[0]->amount, 0.001);
    }

    public function testCanadaPostStandsDownWhenAnyRowIsBulk(): void
    {
        $order = $this->documentWithTwoRows('order');
        $this->addBulkRow($order);

        self::assertFalse((new CanadaPostShippingCalculator())->supports($order));
        self::assertTrue((new BulkShippingCalculator())->supports($order));
    }

    public function testAnEmptyDocumentIsNotShippable(): void
    {
        // The admin create page resolves options against an order with no lines yet. A per-unit
        // carrier must decline rather than quote its base rate for nothing.
        $empty = (new SalesOrder())->setCompany($this->company())->setOrderNumber('ORD-EMPTY');
        $empty->setShippingAddressFrom($this->bookAddress());

        self::assertFalse((new CanadaPostShippingCalculator())->supports($empty));
        self::assertFalse((new BulkShippingCalculator())->supports($empty));
        self::assertTrue((new PickupShippingCalculator())->supports($empty), 'pickup needs no goods to be possible');
    }

    // --- the buyer, read off the document --------------------------------------------------------

    /**
     * ArrangementShippingCalculator is the one that reads who is buying rather than what is in the
     * cart, and the id it matches on is the address-BOOK row's — resolved through the snapshot's
     * source link. A cart links to the book without freezing it and an order froze a copy and kept
     * the link, so both have to answer with the same id or the eligible destination stops matching
     * the moment a cart becomes an order.
     */
    public function testArrangementMatchesTheAddressBookRowOnACartAndOnAnOrderAlike(): void
    {
        $book = $this->bookAddress();
        $this->flagAsArrangementEligible('company_address', (int) $book->getId());

        $calculator = new ArrangementShippingCalculator(
            self::getContainer()->get(CustomFieldValueRepository::class)
        );

        foreach (['cart', 'order', 'estimate'] as $kind) {
            $document = $this->documentWithTwoRows($kind);

            self::assertNotSame(
                $book->getId(),
                $document->getShippingAddress()?->getId(),
                $kind . ': the two ids must differ, or this test would pass reading either',
            );
            self::assertTrue($calculator->supports($document), $kind);
        }
    }

    public function testArrangementMatchesTheCompanyToo(): void
    {
        $company = $this->company();
        $this->flagAsArrangementEligible('company', (int) $company->getId());

        $calculator = new ArrangementShippingCalculator(
            self::getContainer()->get(CustomFieldValueRepository::class)
        );

        self::assertTrue($calculator->supports($this->documentWithTwoRows('order')));
    }

    public function testArrangementDeclinesForAGuestCartWithNoBuyerAndNoAddress(): void
    {
        // getCompany() is nullable now where ?int $companyId used to make that obvious, so the
        // null-buyer path is worth stating rather than assuming.
        $calculator = new ArrangementShippingCalculator(
            self::getContainer()->get(CustomFieldValueRepository::class)
        );

        self::assertFalse($calculator->supports(new Cart()));
    }

    // --- what a calculator can see about money ---------------------------------------------------

    /**
     * A document states its LINE subtotal. Discounts are rows, not a smaller subtotal.
     *
     * This is the figure the free-shipping-threshold case in the issue wanted and could not have,
     * and it is pre-discount: a rule that wants the post-discount figure sums the `type=discount`
     * rows itself. The controller-level waiver (CheckoutController::checkoutEffectiveShipping())
     * has always applied its own post-coupon comparison after the calculator has answered, and
     * still does — nothing here moves that decision into a calculator.
     */
    public function testTheSubtotalACalculatorSeesIsTheLineSubtotalAndDiscountsAreSeparateRows(): void
    {
        $order = $this->documentWithTwoRows('order');
        $order->setFeeLines(FeeLineSnapshot::encode([
            new FeeLine(null, 'save10', 'Coupon SAVE10', 'E', -7.0, 'main_line', FeeLine::TYPE_DISCOUNT, FeeLine::SOURCE_AUTO_CALC),
            new FeeLine(null, 'handling', 'Handling', 'G', 4.0, 'main_line', FeeLine::TYPE_FEE, FeeLine::SOURCE_MANUAL),
        ]));

        self::assertEqualsWithDelta(70.0, (float) $order->getSubtotal(), 0.001, 'the line subtotal, undiscounted');

        $discounts = array_sum(array_map(
            static fn (FeeLine $l): float => $l->type === FeeLine::TYPE_DISCOUNT ? $l->amount : 0.0,
            $order->getFeeLineRows(),
        ));
        self::assertEqualsWithDelta(-7.0, $discounts, 0.001, 'the discount is a row a rule can read');
        self::assertEqualsWithDelta(63.0, (float) $order->getSubtotal() + $discounts, 0.001);
    }

    /**
     * Pins the behaviour this step keeps: none of the seven calculators reads the document's money,
     * so a discount on the document does not move a quoted shipping amount. Widening what they CAN
     * see must not have widened what they DO see.
     */
    public function testADiscountRowOnTheDocumentDoesNotMoveAnyQuotedAmount(): void
    {
        $plain = $this->documentWithTwoRows('order');
        $discounted = $this->documentWithTwoRows('order', number: 2);
        $discounted->setFeeLines(FeeLineSnapshot::encode([
            new FeeLine(null, 'save10', 'Coupon SAVE10', 'E', -50.0, 'main_line', FeeLine::TYPE_DISCOUNT, FeeLine::SOURCE_AUTO_CALC),
        ]));
        $discounted->setSubtotal('20.00');

        $calculator = new CanadaPostShippingCalculator();

        self::assertEqualsWithDelta(
            $calculator->getOptions($plain)[0]->amount,
            $calculator->getOptions($discounted)[0]->amount,
            0.001,
        );
    }

    /**
     * A saved document already carries the shipping rows a previous save wrote, and handing the
     * document over means a calculator can now see them. Nothing reads them, but a rule that did
     * would be reading its own last answer, so the state is worth stating.
     */
    public function testASavedDocumentCarriesItsOwnPreviousShippingRowsIntoTheNextResolution(): void
    {
        $order = $this->documentWithTwoRows('order');
        $order->setFeeLines(FeeLineSnapshot::encode([
            new FeeLine(null, 'shipping', 'Shipping (typed by an admin)', 'G', 999.0, 'main_line', FeeLine::TYPE_SHIPPING, FeeLine::SOURCE_MANUAL),
        ]));

        self::assertEqualsWithDelta(999.0, (float) $order->getShippingTotal(), 0.001, 'the row a calculator can now see');
        self::assertEqualsWithDelta(
            10.0 + 2.5 * 5,
            (new CanadaPostShippingCalculator())->getOptions($order)[0]->amount,
            0.001,
            'the quote is computed from the goods, not echoed back from the stored row',
        );
    }

    // --- fixtures ---------------------------------------------------------------------------------

    private ?Company $companyEntity = null;
    private ?CompanyAddress $bookAddressEntity = null;
    private ?FulfillmentRegion $regionEntity = null;

    private function documentWithTwoRows(string $kind, string $province = 'BC', int $number = 1): AbstractSalesDocument
    {
        $company = $this->company();
        $book = $this->bookAddress($province);
        $widget = $this->product('WIDGET', 'G');
        $gadget = $this->product('GADGET', 'E');

        $document = match ($kind) {
            'cart' => (function () use ($company, $book, $widget, $gadget, $number): Cart {
                $cart = (new Cart())->setSessionId('sess-' . $number . '-' . uniqid());
                $cart->setCompany($company)->linkAddress(AbstractDocumentAddress::TYPE_SHIPPING, $book);
                $cart->addItem((new CartItem())->setProduct($widget)->setQuantity(3)->setFulfillmentRegion($this->region()));
                $cart->addItem((new CartItem())->setProduct($gadget)->setQuantity(2)->setFulfillmentRegion($this->region()));

                return $cart;
            })(),
            'order' => (function () use ($company, $book, $widget, $gadget, $number): SalesOrder {
                $order = (new SalesOrder())->setCompany($company)->setOrderNumber('ORD-' . $number);
                $order->setShippingAddressFrom($book);
                $order->addLine($this->orderLine($widget, 3, '10.00'));
                $order->addLine($this->orderLine($gadget, 2, '20.00'));

                return $order;
            })(),
            'estimate' => (function () use ($company, $book, $widget, $gadget, $number): Estimate {
                $estimate = (new Estimate())->setCompany($company)->setDocumentNumber('EST-' . $number);
                $estimate->setShippingAddressFrom($book);
                $estimate->addLine($this->estimateLine($widget, 3, '10.00'));
                $estimate->addLine($this->estimateLine($gadget, 2, '20.00'));

                return $estimate;
            })(),
            default => throw new \InvalidArgumentException($kind),
        };

        $document->setSubtotal('70.00');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    /** A row whose product ships as bulk, which is what routes a document away from Canada Post. */
    private function addBulkRow(AbstractSalesDocument $document): void
    {
        $pallet = $this->product('PALLET', 'G')->setShippingClass('bulk');
        $this->em->persist($pallet);

        if ($document instanceof SalesOrder) {
            $document->addLine($this->orderLine($pallet, 1, '500.00'));
        } elseif ($document instanceof Estimate) {
            $document->addLine($this->estimateLine($pallet, 1, '500.00'));
        } else {
            throw new \InvalidArgumentException('bulk rows are only set up for saved documents here');
        }

        $this->em->flush();
    }

    private function flagAsArrangementEligible(string $objectType, int $objectId): void
    {
        $definition = (new CustomFieldDefinition())
            ->setObjectType($objectType)
            ->setSlug('arrangement_shipping_eligible')
            ->setLabel('Arrangement shipping eligible')
            ->setFieldType('text');
        $this->em->persist($definition);
        self::getContainer()->get(CustomFieldValueRepository::class)->setValue($definition, $objectId, '1');
        $this->em->flush();
    }

    private function orderLine(ProductCore $product, int $qty, string $price): SalesOrderLine
    {
        return (new SalesOrderLine())
            ->setProduct($product)
            ->setName((string) $product->getName())
            ->setSku((string) $product->getSku())
            ->setQuantity(number_format($qty, 2, '.', ''))
            ->setPrice($price)
            ->setSubtotal(number_format($qty * (float) $price, 2, '.', ''))
            ->setTaxCode($product->getSalesTaxCode());
    }

    private function estimateLine(ProductCore $product, int $qty, string $price): EstimateLine
    {
        return (new EstimateLine())
            ->setProduct($product)
            ->setName((string) $product->getName())
            ->setSku((string) $product->getSku())
            ->setQuantity(number_format($qty, 2, '.', ''))
            ->setPrice($price)
            ->setSubtotal(number_format($qty * (float) $price, 2, '.', ''))
            ->setTaxCode($product->getSalesTaxCode());
    }

    private function company(): Company
    {
        if ($this->companyEntity === null) {
            $this->companyEntity = (new Company())->setName('Acme Supplies')->setCode('ACME');
            $this->em->persist($this->companyEntity);
            $this->em->flush();
        }

        return $this->companyEntity;
    }

    private function region(): FulfillmentRegion
    {
        if ($this->regionEntity === null) {
            $this->regionEntity = (new FulfillmentRegion())->setName('West');
            $this->em->persist($this->regionEntity);
            $this->em->flush();
        }

        return $this->regionEntity;
    }

    private function bookAddress(string $province = 'BC'): CompanyAddress
    {
        if ($this->bookAddressEntity === null) {
            // Decoys, so the book row's id cannot coincide with the document's own address row's.
            // Both tables start at 1, and an assertion that passes either way proves nothing about
            // which id a calculator actually matched on.
            for ($i = 0; $i < 3; $i++) {
                $this->em->persist(
                    (new CompanyAddress())->setCompany($this->company())->setLabel('Decoy ' . $i)->setProvince('AB')
                );
            }
            $this->em->flush();

            $this->bookAddressEntity = (new CompanyAddress())
                ->setCompany($this->company())
                ->setLabel('Warehouse')
                ->setAddressLine1('1 Dock Road')
                ->setCity('Vancouver')
                ->setCountry('CA')
                ->setPostalCode('V5K0A1');
            $this->em->persist($this->bookAddressEntity);
        }

        $this->bookAddressEntity->setProvince($province);
        $this->em->flush();

        return $this->bookAddressEntity;
    }

    private function product(string $sku, ?string $taxCode): ProductCore
    {
        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => $sku]);
        if ($product instanceof ProductCore) {
            return $product;
        }

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Product ' . $sku)
            ->setDefaultPrice('10.00')
            ->setSalesTaxCode($taxCode)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
