<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Contract\Fee\FeeContext;
use App\Entity\AbstractDocumentAddress;
use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Service\CompanyFulfillmentRegionService;
use App\Service\Pricing\CustomerPricingResolver;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * The calculators' inputs are built from the document now, in one place, instead of by sixteen
 * callers by hand (issue #165 step 6).
 *
 * The claim worth testing is the one the issue makes: a calculator must not be able to tell a cart
 * from an order from an estimate. So the same two rows are put on each of the three document types
 * and the answers are compared field for field.
 *
 * Fee calculators are still handed a FeeContext built here. Shipping calculators are handed the
 * document itself since step 9, so the shipping half of this file asks the document the five
 * questions ShippingContext used to carry, and ShippingCalculatorsTakeTheDocumentTest runs the real
 * calculators against one.
 */
final class DocumentContextFactoriesTest extends DoctrineIntegrationTestCase
{
    private CustomerPricingResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new CustomerPricingResolver($this->em, new CompanyFulfillmentRegionService($this->em));
    }

    // --- one answer, whatever the document is ----------------------------------------------------

    /**
     * The five questions ShippingContext used to carry, asked of the document that replaced it.
     */
    public function testTheSameRowsOnACartAnOrderAndAnEstimateAnswerShippingIdentically(): void
    {
        [$cart, $order, $estimate] = $this->threeDocumentsWithTheSameTwoRows();

        foreach (['cart' => $cart, 'order' => $order, 'estimate' => $estimate] as $kind => $document) {
            self::assertSame('BC', $document->getProvince(), $kind);
            self::assertSame('G', $document->getHighestTaxClass(), $kind . ': G beats E across the two rows');
            self::assertSame($this->companyId, $document->getCompany()?->getId(), $kind);
            self::assertSame(
                $this->bookAddressId,
                $document->getEffectiveShippingAddress()?->getSourceAddress()?->getId(),
                $kind,
            );
            self::assertSame(
                [['WIDGET', 3], ['GADGET', 2]],
                self::skuAndQty($document->getCartItems()),
                $kind . ': same products, same quantities, same order',
            );
        }
    }

    public function testTheSameRowsGiveTheSameFeeContext(): void
    {
        [$cart, $order, $estimate] = $this->threeDocumentsWithTheSameTwoRows();

        foreach (['cart' => $cart, 'order' => $order, 'estimate' => $estimate] as $kind => $document) {
            $context = FeeContext::fromDocument($document, 'stripe');

            self::assertSame('BC', $context->province, $kind);
            self::assertSame($this->companyId, $context->companyId, $kind);
            self::assertSame('stripe', $context->paymentMethod, $kind);
            self::assertSame([], $context->couponCodes, $kind);
            self::assertEqualsWithDelta(70.0, $context->subtotal, 0.001, $kind . ': the header figure');
            self::assertSame([['WIDGET', 3], ['GADGET', 2]], self::skuAndQty($context->cartItems), $kind);
        }
    }

    /**
     * The one that costs real money if it regresses: an address holding a legacy display name has to
     * reach a calculator as a code, or the calculator matches nothing and the customer is charged $0
     * tax on a taxable order.
     */
    public function testALegacyProvinceDisplayNameStillReachesTheCalculatorsAsACode(): void
    {
        [$cart, $order, $estimate] = $this->threeDocumentsWithTheSameTwoRows(province: 'British Columbia');

        foreach (['cart' => $cart, 'order' => $order, 'estimate' => $estimate] as $kind => $document) {
            self::assertSame(
                'British Columbia',
                $document->getEffectiveShippingAddress()?->getProvince(),
                $kind . ': the address really is holding a legacy display name',
            );
            self::assertSame('BC', $document->getProvince(), $kind . ': normalised by the document');
            self::assertSame('BC', FeeContext::fromDocument($document)->province, $kind);
        }
    }

    public function testAnAmericanProvinceNormalisesToo(): void
    {
        [$cart] = $this->threeDocumentsWithTheSameTwoRows(province: 'Washington');

        self::assertSame('WA', $cart->getProvince());
    }

    // --- which rows count ------------------------------------------------------------------------

    public function testShippingCountsEveryRowAndAFeeContextOnlyThePricedOnes(): void
    {
        // Fees and shipping ask different questions of the same rows: an estimate's TBD line is
        // still goods that have to be delivered, but there is nothing settled to charge a fee on.
        $company = $this->company();
        $estimate = (new Estimate())->setCompany($company)->setDocumentNumber('EST-TBD');
        $estimate->setShippingAddressFrom($this->bookAddress());
        $estimate->addLine($this->estimateLine($this->product('PRICED'), 2, '10.00'));
        $estimate->addLine($this->estimateLine($this->product('TBD'), 5, null));
        $this->em->persist($estimate);
        $this->em->flush();

        self::assertSame([['PRICED', 2], ['TBD', 5]], self::skuAndQty($estimate->getCartItems()));
        self::assertSame([['PRICED', 2]], self::skuAndQty(FeeContext::fromDocument($estimate)->cartItems));
    }

    public function testRowsWithNoProductOrNoQuantityAreLeftOutOfBothContexts(): void
    {
        $order = (new SalesOrder())->setCompany($this->company())->setOrderNumber('ORD-BLANK');
        $order->setShippingAddressFrom($this->bookAddress());
        // An admin's typed blank line: a label and an amount, no catalog product behind it.
        $order->addLine((new SalesOrderLine())->setName('Rush handling')->setQuantity('1.00')->setSubtotal('20.00'));
        $order->addLine($this->orderLine($this->product('ZERO'), 0, '10.00'));
        $order->addLine($this->orderLine($this->product('REAL'), 4, '10.00'));
        $this->em->persist($order);
        $this->em->flush();

        self::assertSame([['REAL', 4]], self::skuAndQty($order->getCartItems()));
        self::assertSame([['REAL', 4]], self::skuAndQty(FeeContext::fromDocument($order)->cartItems));
    }

    /**
     * The shipping address id a calculator gets is the address-BOOK row's, not the document's own
     * snapshot row. ArrangementShippingCalculator looks it up as a `company_address` custom-field
     * owner, so the snapshot's id could only ever match by coincidence.
     */
    public function testTheShippingAddressIdIsTheAddressBookRowNotTheSnapshot(): void
    {
        $book = $this->bookAddress();
        $order = (new SalesOrder())->setCompany($this->company())->setOrderNumber('ORD-ADDR');
        $order->setShippingAddressFrom($book);
        $this->em->persist($order);
        $this->em->flush();

        self::assertNotNull($order->getShippingAddress()?->getId(), 'the snapshot is a row of its own');
        self::assertSame($book->getId(), $order->getEffectiveShippingAddress()?->getSourceAddress()?->getId());
    }

    public function testAGuestCartWithNoBuyerAndNoAddressStillAnswersEveryQuestion(): void
    {
        $cart = (new Cart())->setSessionId('guest-' . uniqid());
        $this->em->persist($cart);
        $this->em->flush();

        self::assertNull($cart->getCompany(), 'a guest has no company, and a calculator has to expect that');
        self::assertNull($cart->getEffectiveShippingAddress()?->getSourceAddress()?->getId());
        self::assertSame('', $cart->getProvince());
        self::assertSame('E', $cart->getHighestTaxClass(), 'no rows means nothing taxable');
        self::assertSame([], $cart->getCartItems());
    }

    public function testCouponCodesRideOnTheFeeContextBecauseTheyArePerDocumentState(): void
    {
        $order = (new SalesOrder())->setCompany($this->company())->setOrderNumber('ORD-COUPON');
        $order->setShippingAddressFrom($this->bookAddress())->setCouponCodes(['save10', 'FREESHIP']);
        $this->em->persist($order);
        $this->em->flush();

        self::assertSame(['SAVE10', 'FREESHIP'], FeeContext::fromDocument($order)->couponCodes);
    }

    // --- fixtures ---------------------------------------------------------------------------------

    private ?int $companyId = null;
    private ?int $bookAddressId = null;
    private ?Company $companyEntity = null;
    private ?CompanyAddress $bookAddressEntity = null;
    private ?FulfillmentRegion $regionEntity = null;

    /**
     * The same two rows — 3 × WIDGET at $10 (taxable) and 2 × GADGET at $20 (exempt) — on each of
     * the three document types, all shipping to the same address-book entry.
     *
     * @return array{0: Cart, 1: SalesOrder, 2: Estimate}
     */
    private function threeDocumentsWithTheSameTwoRows(string $province = 'BC'): array
    {
        $company = $this->company();
        $book = $this->bookAddress($province);
        $widget = $this->product('WIDGET', '10.00', 'G');
        $gadget = $this->product('GADGET', '20.00', 'E');

        $cart = (new Cart())->setSessionId('sess-' . uniqid());
        $cart->setCompany($company)->linkAddress(AbstractDocumentAddress::TYPE_SHIPPING, $book);
        $cart->addItem((new CartItem())->setProduct($widget)->setQuantity(3)->setFulfillmentRegion($this->region()));
        $cart->addItem((new CartItem())->setProduct($gadget)->setQuantity(2)->setFulfillmentRegion($this->region()));
        $this->em->persist($cart);

        $order = (new SalesOrder())->setCompany($company)->setOrderNumber('ORD-1');
        $order->setShippingAddressFrom($book);
        $order->addLine($this->orderLine($widget, 3, '10.00'));
        $order->addLine($this->orderLine($gadget, 2, '20.00'));
        $order->setSubtotal('70.00');
        $this->em->persist($order);

        $estimate = (new Estimate())->setCompany($company)->setDocumentNumber('EST-1');
        $estimate->setShippingAddressFrom($book);
        $estimate->addLine($this->estimateLine($widget, 3, '10.00'));
        $estimate->addLine($this->estimateLine($gadget, 2, '20.00'));
        $estimate->setSubtotal('70.00');
        $this->em->persist($estimate);

        $this->em->flush();

        // A cart's money is live, never stored on the item, so it is resolved here the way every
        // cart consumer resolves it — and the header figure written back the way the cart page does.
        $cart->setSubtotal(number_format($cart->priceItems($this->resolver->for($company, 'West')), 2, '.', ''));

        $this->companyId = $company->getId();
        $this->bookAddressId = $book->getId();

        return [$cart, $order, $estimate];
    }

    /**
     * @param array<int, array{product: ProductCore, qty: int}> $cartItems
     * @return list<array{0: string, 1: int}>
     */
    private static function skuAndQty(array $cartItems): array
    {
        return array_map(static fn (array $i) => [$i['product']->getSku(), $i['qty']], array_values($cartItems));
    }

    private function orderLine(ProductCore $product, int $qty, string $price): SalesOrderLine
    {
        return (new SalesOrderLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku($product->getSku())
            ->setQuantity(number_format($qty, 2, '.', ''))
            ->setPrice($price)
            ->setSubtotal(number_format($qty * (float) $price, 2, '.', ''))
            ->setTaxCode($product->getSalesTaxCode());
    }

    private function estimateLine(ProductCore $product, int $qty, ?string $price): EstimateLine
    {
        $line = (new EstimateLine())
            ->setProduct($product)
            ->setName($product->getName())
            ->setSku($product->getSku())
            ->setQuantity(number_format($qty, 2, '.', ''))
            ->setTaxCode($product->getSalesTaxCode());

        return $price === null
            ? $line
            : $line->setPrice($price)->setSubtotal(number_format($qty * (float) $price, 2, '.', ''));
    }

    private function company(): Company
    {
        if ($this->companyEntity === null) {
            $this->companyEntity = (new Company())->setName('Acme Supplies')->setCode('ACME');
            $this->em->persist($this->companyEntity);

            $priceList = (new PriceList())->setName('West list');
            $this->em->persist($priceList);
            $this->em->persist(
                (new CompanyFulfillmentRegion())
                    ->setCompany($this->companyEntity)
                    ->setFulfillmentRegion($this->region())
                    ->setPriceList($priceList)
                    ->setStatus('Active')
            );
            $this->priceList = $priceList;
        }

        return $this->companyEntity;
    }

    private ?PriceList $priceList = null;

    private function region(): FulfillmentRegion
    {
        if ($this->regionEntity === null) {
            $this->regionEntity = (new FulfillmentRegion())->setName('West');
            $this->em->persist($this->regionEntity);
        }

        return $this->regionEntity;
    }

    private function bookAddress(string $province = 'BC'): CompanyAddress
    {
        if ($this->bookAddressEntity === null) {
            $this->bookAddressEntity = (new CompanyAddress())
                ->setCompany($this->company())
                ->setLabel('Warehouse')
                ->setFirstName('Ada')
                ->setLastName('Lovelace')
                ->setAddressLine1('1 Dock Road')
                ->setCity('Vancouver')
                ->setCountry('CA')
                ->setPostalCode('V5K0A1');
            $this->em->persist($this->bookAddressEntity);
        }

        return $this->bookAddressEntity->setProvince($province);
    }

    private function product(string $sku, string $price = '10.00', ?string $taxCode = 'G'): ProductCore
    {
        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => $sku]);
        if ($product instanceof ProductCore) {
            return $product;
        }

        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Product ' . $sku)
            ->setDefaultPrice($price)
            ->setSalesTaxCode($taxCode)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);

        // The cart prices against the price list, not the catalog default, so the two agree only if
        // the list says the same thing — which is what makes the three documents comparable.
        $this->company();
        $this->em->persist(
            (new ProductPricing())
                ->setProduct($product)
                ->setPriceList($this->priceList)
                ->setRuleType('Number')
                ->setRuleValue($price)
                ->setPrice($price)
        );

        return $product;
    }
}
