<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Contract\Fee\FeeLine;
use App\Contract\Fee\FeeLineSnapshot;
use App\Entity\AbstractDocumentAddress;
use App\Entity\AbstractSalesDocument;
use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\DocumentLine;
use App\Entity\Estimate;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use App\Entity\SalesOrder;
use App\Service\CompanyFulfillmentRegionService;
use App\Service\Pricing\CustomerPricingResolver;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * A cart is a pre-document, and issue #165 makes it one, so that a fee, tax or shipping calculator
 * cannot tell a cart from an order from an estimate.
 *
 * These tests are about the seams that had to move for that: the two fields the base widened, the
 * money a cart computes but must never store, and the two derived accessors calculators will read
 * instead of re-deriving for themselves.
 */
final class CartAsSalesDocumentTest extends DoctrineIntegrationTestCase
{
    private CustomerPricingResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new CustomerPricingResolver($this->em, new CompanyFulfillmentRegionService($this->em));
    }

    // --- the document contract -----------------------------------------------------------------

    public function testACartIsASalesDocument(): void
    {
        $cart = $this->newCart();
        $this->em->flush();

        self::assertInstanceOf(AbstractSalesDocument::class, $cart);
        self::assertInstanceOf(DocumentLine::class, $this->addItem($cart, $this->newProduct('SKU-1', '10.00')));
        self::assertCount(1, $cart->getLines(), 'getLines() is the document view of getItems()');
        self::assertSame($cart->getItems()->toArray(), $cart->getLines()->toArray());
    }

    public function testTheHeaderColumnsAreOnTheCartTableAndSurviveAReload(): void
    {
        $cart = $this->newCart();
        $cart->setSubtotal('120.00')->setTax('16.80')->setTotal('151.80')
            ->setFeeLines(FeeLineSnapshot::encode([
                new FeeLine(null, 'shipping', 'Shipping (Ground)', 'G', 15.0, 'main_line', FeeLine::TYPE_SHIPPING),
            ]))
            ->setShippingMethod('Ground')->setFulfillmentRegion('West');
        $this->em->flush();

        $id = $cart->getId();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Cart::class)->find($id);

        // Compared numerically: SQLite stores NUMERIC as a number, so '120.00' comes back as '120'.
        self::assertEquals(120.0, (float) $reloaded->getSubtotal());
        // Shipping is a fee row, not a column of its own — it reloads with the rest of fee_lines.
        self::assertSame(15.0, $reloaded->getShippingTotal());
        self::assertEquals(16.8, (float) $reloaded->getTax());
        self::assertEquals(151.8, (float) $reloaded->getTotal());
        self::assertSame('Ground', $reloaded->getShippingMethod());
        self::assertSame('West', $reloaded->getFulfillmentRegion());
    }

    /**
     * The three fields that record how a document came to exist. A cart has not come to exist yet,
     * so they stay empty until conversion fills them.
     */
    public function testACartLeavesTheConversionTimeFieldsEmpty(): void
    {
        $cart = $this->newCart();
        $cart->setCompany($this->newCompany('ACME'));
        $this->em->flush();

        self::assertSame('', $cart->getSource());
        self::assertNull($cart->getCompanySnapshot(), 'attaching a company to a cart must not freeze its identity');
        self::assertNull($cart->getPoNumber());
        self::assertNull($cart->getDocumentDate(), 'nobody raises a purchase order against a basket');
    }

    // --- the two fields the base widened -------------------------------------------------------

    public function testAGuestCartHasNoCompanyAndIsStampedLater(): void
    {
        $cart = $this->newCart();
        $this->em->flush();

        self::assertNull($cart->getCompany());

        $cart->setCompany($this->newCompany('ACME'));
        $this->em->flush();

        self::assertSame('ACME', $cart->getCompany()?->getCode());
    }

    public function testASalesOrderRefusesANullCompany(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SalesOrder())->setCompany(null);
    }

    public function testAnEstimateRefusesANullCompany(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Estimate())->setCompany(null);
    }

    /** The subclasses keep the constraint in the mapping too, not only in the setter. */
    public function testTheDocumentTablesStillRequireACompanyAndADocumentDate(): void
    {
        foreach ([SalesOrder::class, Estimate::class] as $documentClass) {
            $metadata = $this->em->getClassMetadata($documentClass);

            self::assertFalse(
                $metadata->getAssociationMapping('company')->joinColumns[0]->nullable ?? true,
                $documentClass . '.company_id must stay NOT NULL',
            );
            self::assertFalse($metadata->getFieldMapping('documentDate')->nullable ?? true, $documentClass . '.document_date must stay NOT NULL');
        }

        $cartMetadata = $this->em->getClassMetadata(Cart::class);
        self::assertTrue($cartMetadata->getAssociationMapping('company')->joinColumns[0]->nullable ?? false);
        self::assertTrue($cartMetadata->getFieldMapping('documentDate')->nullable ?? false);
    }

    // --- cart money is live ---------------------------------------------------------------------

    public function testItemMoneyIsComputedFromTheScopeAndNeverStored(): void
    {
        [$company, $product] = $this->pricedCatalog('SKU-1', base: '100.00', listPrice: '80.00');
        $cart = $this->newCart($company);
        $item = $this->addItem($cart, $product, 3);
        $this->em->flush();

        self::assertNull($item->getPrice(), 'nothing has priced this line yet');

        $subtotal = $cart->priceItems($this->resolver->for($company, 'West'));

        self::assertSame('80.00', $item->getPrice());
        self::assertSame('240.00', $item->getSubtotal());
        self::assertSame(240.0, $subtotal);

        // The proof that it is not persisted: flush everything, drop the identity map, read it back.
        $this->em->flush();
        $itemId = $item->getId();
        $this->em->clear();

        $reloaded = $this->em->getRepository(CartItem::class)->find($itemId);
        self::assertNull($reloaded->getPrice(), 'price must not be a column — it moves with company and region');
        self::assertNull($reloaded->getSubtotal());
    }

    public function testCartItemHasNoMoneyColumns(): void
    {
        // Prices move with the buyer, the region and the price list, so a stored one is wrong the
        // moment any of the three changes. Asserted against the mapping rather than the getters,
        // because a column added later would still pass the round-trip test above on a fresh row.
        $fields = $this->em->getClassMetadata(CartItem::class)->getFieldNames();

        self::assertNotContains('price', $fields);
        self::assertNotContains('subtotal', $fields);
        self::assertNotContains('taxCode', $fields);
    }

    public function testAnUnpricedLineKeepsNullMoneyRatherThanZero(): void
    {
        // Zero and "no price" are the same number and completely different facts: one is a free
        // item, the other is what routes a checkout to an estimate.
        [$company, $product] = $this->pricedCatalog('SKU-1', base: '100.00', listPrice: null, rule: 'No Price');
        $cart = $this->newCart($company);
        $item = $this->addItem($cart, $product, 2);
        $this->em->flush();

        $subtotal = $cart->priceItems($this->resolver->for($company, 'West'));

        self::assertNull($item->getPrice());
        self::assertNull($item->getSubtotal());
        self::assertSame(0.0, $subtotal, 'an unpriced line contributes nothing to a total it cannot be part of');
    }

    // --- derived accessors ----------------------------------------------------------------------

    public function testHighestTaxClassIsTakenFromTheLines(): void
    {
        $cart = $this->newCart();
        $this->addItem($cart, $this->newProduct('EXEMPT', '10.00', taxCode: 'E'));
        self::assertSame('E', $cart->getHighestTaxClass());

        $this->addItem($cart, $this->newProduct('GOODS', '10.00', taxCode: 'G'));
        self::assertSame('G', $cart->getHighestTaxClass());

        $this->addItem($cart, $this->newProduct('SERVICE', '10.00', taxCode: 'S'));
        self::assertSame('S', $cart->getHighestTaxClass(), 'S beats G beats E regardless of line order');

        // A blank code is exempt, not an error — and must not drag the answer back down.
        $this->addItem($cart, $this->newProduct('BLANK', '10.00', taxCode: null));
        self::assertSame('S', $cart->getHighestTaxClass());
    }

    public function testAnOrderAnswersTheSameQuestionTheSameWay(): void
    {
        // The point of the accessor being on the base: a calculator asking a document for its tax
        // class must not need to know which kind of document it is holding.
        $order = (new SalesOrder())->setCompany($this->newCompany('ACME'))->setOrderNumber('ORD-1');
        $order->addLine((new \App\Entity\SalesOrderLine())->setName('Exempt thing')->setTaxCode('E'));
        $order->addLine((new \App\Entity\SalesOrderLine())->setName('Taxable thing')->setTaxCode('G'));
        $this->em->persist($order);
        $this->em->flush();

        self::assertSame('G', $order->getHighestTaxClass());
    }

    public function testProvinceIsNormalisedRatherThanReadRaw(): void
    {
        // A legacy display name that reaches a calculator unnormalised matches no rule and produces
        // a $0-tax order — silently. This is the one place that now has to get it right.
        $company = $this->newCompany('ACME');
        $book = $this->bookAddress($company, province: 'British Columbia');
        $cart = $this->newCart($company);
        $cart->linkAddress(AbstractDocumentAddress::TYPE_SHIPPING, $book);
        $this->em->flush();

        self::assertSame('BC', $cart->getProvince());

        $book->setProvince('BC');
        self::assertSame('BC', $cart->getProvince(), 'a code passes straight through');
    }

    public function testProvinceIsBlankWhenTheDocumentHasNoAddressAtAll(): void
    {
        self::assertSame('', $this->newCart()->getProvince());
    }

    // --- frozen-else-live addresses --------------------------------------------------------------

    public function testALinkOnlyRowResolvesLiveAndFollowsTheAddressBook(): void
    {
        $company = $this->newCompany('ACME');
        $book = $this->bookAddress($company);
        $cart = $this->newCart($company);
        $cart->linkAddress(AbstractDocumentAddress::TYPE_SHIPPING, $book);
        $this->em->flush();

        self::assertTrue($cart->getShippingAddress()?->isLinkOnly());
        self::assertSame('Vancouver', $cart->getEffectiveShippingAddress()?->getCity());

        // A cart is not a record of anything yet, so it moves with the customer.
        $book->setCity('Burnaby');
        self::assertSame('Burnaby', $cart->getEffectiveShippingAddress()?->getCity());

        // Resolving live must not quietly freeze the row — that is conversion's job.
        self::assertTrue($cart->getShippingAddress()?->isLinkOnly());
        self::assertNull($cart->getShippingAddress()?->getCity());
    }

    public function testAFrozenRowAnswersWithItsOwnValuesAndIgnoresTheLink(): void
    {
        $company = $this->newCompany('ACME');
        $book = $this->bookAddress($company);
        $order = (new SalesOrder())->setCompany($company)->setOrderNumber('ORD-1');
        $order->setShippingAddressFrom($book);
        $this->em->persist($order);
        $this->em->flush();

        self::assertFalse($order->getShippingAddress()?->isLinkOnly());

        $book->setCity('Toronto');

        self::assertSame(
            'Vancouver',
            $order->getEffectiveShippingAddress()?->getCity(),
            'an order records where the goods went; the address book moving must not rewrite it',
        );
    }

    public function testBillingResolvesTheSameWayAsShipping(): void
    {
        $company = $this->newCompany('ACME');
        $book = $this->bookAddress($company, city: 'Victoria');
        $cart = $this->newCart($company);
        $cart->linkAddress(AbstractDocumentAddress::TYPE_BILLING, $book);
        $this->em->flush();

        self::assertSame('Victoria', $cart->getEffectiveBillingAddress()?->getCity());
        self::assertNull($cart->getEffectiveShippingAddress(), 'only the row that exists resolves');
    }

    public function testALinkOnlyRowWithNoSourceLeftResolvesToNothingRatherThanGuessing(): void
    {
        // ON DELETE SET NULL empties the link when the book entry goes. Falling back to the
        // company's current default here is the exact bug the address snapshot removed.
        $company = $this->newCompany('ACME');
        $cart = $this->newCart($company);
        $cart->addressForWriting(AbstractDocumentAddress::TYPE_SHIPPING);
        $this->em->flush();

        self::assertNull($cart->getEffectiveShippingAddress()?->getCity());
        self::assertSame('', $cart->getProvince());
    }

    // --- fixtures -------------------------------------------------------------------------------

    private function newCart(?Company $company = null): Cart
    {
        $cart = (new Cart())->setSessionId('sess-' . uniqid());
        $cart->setCompany($company);
        $this->em->persist($cart);

        return $cart;
    }

    private function addItem(Cart $cart, ProductCore $product, int $quantity = 1): CartItem
    {
        $item = (new CartItem())
            ->setProduct($product)
            ->setQuantity($quantity)
            ->setFulfillmentRegion($this->region());
        $cart->addItem($item);
        $this->em->persist($item);

        return $item;
    }

    private ?FulfillmentRegion $region = null;

    private function region(): FulfillmentRegion
    {
        if ($this->region === null) {
            $this->region = (new FulfillmentRegion())->setName('West');
            $this->em->persist($this->region);
        }

        return $this->region;
    }

    private function newCompany(string $code): Company
    {
        $company = (new Company())->setName('Company ' . $code)->setCode($code);
        $this->em->persist($company);

        return $company;
    }

    private function bookAddress(Company $company, string $city = 'Vancouver', string $province = 'BC'): CompanyAddress
    {
        $address = (new CompanyAddress())
            ->setCompany($company)
            ->setLabel('Main')
            ->setFirstName('Ada')
            ->setLastName('Lovelace')
            ->setAddressLine1('1 Dock Road')
            ->setCity($city)
            ->setProvince($province)
            ->setCountry('CA')
            ->setPostalCode('V5K0A1');
        $this->em->persist($address);

        return $address;
    }

    private function newProduct(string $sku, ?string $price = null, ?string $taxCode = 'G'): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Product ' . $sku)->setDefaultPrice($price)->setSalesTaxCode($taxCode)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);

        return $product;
    }

    /** @return array{0: Company, 1: ProductCore} a company on a West price list, plus one product on it */
    private function pricedCatalog(string $sku, ?string $base, ?string $listPrice, ?string $rule = null): array
    {
        $priceList = (new PriceList())->setName('West list');
        $this->em->persist($priceList);

        $company = $this->newCompany('ACME');
        $this->em->persist(
            (new CompanyFulfillmentRegion())
                ->setCompany($company)
                ->setFulfillmentRegion($this->region())
                ->setPriceList($priceList)
                ->setStatus('Active')
        );

        $product = $this->newProduct($sku, $base);
        $this->em->persist(
            (new ProductPricing())
                ->setProduct($product)
                ->setPriceList($priceList)
                ->setRuleType($rule ?? ($listPrice !== null ? 'Number' : null))
                ->setRuleValue($listPrice)
                ->setPrice('0.00')
        );

        return [$company, $product];
    }
}
