<?php

declare(strict_types=1);

namespace App\Tests\Service\Pricing;

use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use App\Service\CompanyFulfillmentRegionService;
use App\Service\DocumentActor;
use App\Service\Pricing\CustomerPrice;
use App\Service\Pricing\CustomerPricingResolver;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * The pricing engine used to live as protected methods on AbstractCustomerController, reachable
 * only through a booted controller with a logged-in customer — so none of these cases had a test.
 */
final class CustomerPricingResolverTest extends DoctrineIntegrationTestCase
{
    private CustomerPricingResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new CustomerPricingResolver(
            $this->em,
            new CompanyFulfillmentRegionService($this->em),
        );
    }

    // --- company + region pricing --------------------------------------------------------------

    public function testCompanyPricesAgainstThePriceListActiveForTheRegionItIsBuyingIn(): void
    {
        $west = $this->newPriceList('West list');
        $east = $this->newPriceList('East list');
        $company = $this->newCompany('ACME');
        $this->activate($company, $this->newRegion('West'), $west);
        $this->activate($company, $this->newRegion('East'), $east);

        $product = $this->newProduct('SKU-1', '100.00');
        $this->newPricing($product, $west, 'Number', '80');
        $this->newPricing($product, $east, 'Number', '95');
        $this->em->flush();

        self::assertSame('80.00', $this->resolver->for($company, 'West')->priceFor($product)->amount);
        self::assertSame('95.00', $this->resolver->for($company, 'East')->priceFor($product)->amount);
    }

    public function testRegionNameMatchingIsCaseInsensitive(): void
    {
        $priceList = $this->newPriceList('West list');
        $company = $this->newCompany('ACME');
        $this->activate($company, $this->newRegion('West'), $priceList);

        $product = $this->newProduct('SKU-1', '100.00');
        $this->newPricing($product, $priceList, 'Number', '80');
        $this->em->flush();

        self::assertSame('80.00', $this->resolver->for($company, 'wEsT')->priceFor($product)->amount);
    }

    public function testNoRegionNameResolvesOnlyWhenTheCompanyHasExactlyOneActiveRegion(): void
    {
        $priceList = $this->newPriceList('Only list');
        $single = $this->newCompany('SINGLE');
        $this->activate($single, $this->newRegion('West'), $priceList);

        $ambiguous = $this->newCompany('AMBIG');
        $this->activate($ambiguous, $this->newRegion('North'), $priceList);
        $this->activate($ambiguous, $this->newRegion('South'), $this->newPriceList('Other list'));

        $product = $this->newProduct('SKU-1', '100.00');
        $this->newPricing($product, $priceList, 'Number', '80');
        $this->em->flush();

        self::assertSame('80.00', $this->resolver->for($single, null)->priceFor($product)->amount);
        // Two candidates is ambiguous, so no price list resolves and the product falls back to its
        // own base price rather than to whichever region happened to be listed first.
        self::assertSame('100.00', $this->resolver->for($ambiguous, null)->priceFor($product)->amount);
    }

    /**
     * The rule ladder in one place: whichever rung applies first wins, and the rungs below it are
     * never consulted.
     */
    public function testRuleLadder(): void
    {
        $priceList = $this->newPriceList('List');
        $company = $this->newCompany('ACME');
        $this->activate($company, $this->newRegion('West'), $priceList);

        $flat = $this->newProduct('FLAT', '100.00');
        $this->newPricing($flat, $priceList, 'Number', '42.5');

        $percent = $this->newProduct('PCT', '200.00');
        $this->newPricing($percent, $priceList, 'Discount%', '25');

        $dollars = $this->newProduct('DLR', '200.00');
        $this->newPricing($dollars, $priceList, 'Discount$', '30');

        $rowPrice = $this->newProduct('ROW', '200.00');
        $this->newPricing($rowPrice, $priceList, null, null, '17.00');

        // A rule row with no usable rule and no row price leaves the price-group discount to apply.
        $impliedDiscount = $this->newProduct('IMPLIED', '200.00');

        // A discount deeper than the price goes below zero and stays there (#462).
        $overDiscounted = $this->newProduct('OVER', '10.00');
        $this->newPricing($overDiscounted, $priceList, 'Discount$', '25');

        // originalPrice is the base only when defaultPrice is absent.
        $fallbackBase = $this->newProduct('ORIG', null, '50.00');

        $this->em->flush();

        $scope = $this->resolver->for($company, 'West');
        self::assertSame('42.50', $scope->priceFor($flat)->amount);
        self::assertSame('150.00', $scope->priceFor($percent)->amount);
        self::assertSame('170.00', $scope->priceFor($dollars)->amount);
        self::assertSame('17.00', $scope->priceFor($rowPrice)->amount);
        self::assertSame('180.00', $scope->priceFor($impliedDiscount)->amount);
        self::assertSame('-15.00', $scope->priceFor($overDiscounted)->amount);
        self::assertSame('45.00', $scope->priceFor($fallbackBase)->amount);
    }

    /**
     * A negative discount is a premium: the customer pays MORE than the base price, not less.
     * Nothing about the sign is rewritten in either direction — neither the discount value nor the
     * price it computes to.
     */
    public function testANegativeDiscountIsAPremiumThatIncreasesThePrice(): void
    {
        $priceList = $this->newPriceList('Premium list');
        $company = $this->newCompany('ACME');
        $this->activate($company, $this->newRegion('West'), $priceList);

        $percentPremium = $this->newProduct('PCT-PREMIUM', '200.00');
        $this->newPricing($percentPremium, $priceList, 'Discount%', '-10');

        $dollarPremium = $this->newProduct('DLR-PREMIUM', '200.00');
        $this->newPricing($dollarPremium, $priceList, 'Discount$', '-15');

        $this->em->flush();

        $scope = $this->resolver->for($company, 'West');
        // -10% "discount" means the customer pays 10% MORE: 200 * (1 - (-10/100)) = 220.
        self::assertSame('220.00', $scope->priceFor($percentPremium)->amount);
        // -$15 "discount" means the customer pays $15 MORE: 200 - (-15) = 215.
        self::assertSame('215.00', $scope->priceFor($dollarPremium)->amount);
    }

    /**
     * #462. The storefront used to floor every customer-facing price at zero, so a product the
     * admin grid showed as -10.00 reached the customer as 0.00 and the two screens disagreed with
     * nothing explaining why. The sign is settled at the admin write path (#458); this side only
     * formats it. Every rung of the ladder that can go below zero is checked, because the floor
     * sat in pricedAt() and so applied to all of them at once.
     */
    public function testANegativePriceReachesTheCustomerRatherThanBeingFlooredAtZero(): void
    {
        $priceList = $this->newPriceList('Below zero list');
        $company = $this->newCompany('ACME');
        $this->activate($company, $this->newRegion('West'), $priceList);

        // The headline case: product_pricing.price is literally -10.00, stored by #458.
        $storedNegative = $this->newProduct('STORED-NEG', '100.00');
        $this->newPricing($storedNegative, $priceList, null, null, '-10.00');

        // A flat Number rule set below zero.
        $flatNegative = $this->newProduct('FLAT-NEG', '100.00');
        $this->newPricing($flatNegative, $priceList, 'Number', '-5');

        // A dollar discount deeper than the base price: 10 - 25 = -15.
        $overDiscountedDollars = $this->newProduct('OVER-DLR', '10.00');
        $this->newPricing($overDiscountedDollars, $priceList, 'Discount$', '25');

        // A percentage discount over 100%: 10 * (1 - 150/100) = -5.
        $overDiscountedPercent = $this->newProduct('OVER-PCT', '10.00');
        $this->newPricing($overDiscountedPercent, $priceList, 'Discount%', '150');

        $this->em->flush();

        $scope = $this->resolver->for($company, 'West');

        $stored = $scope->priceFor($storedNegative);
        self::assertSame('-10.00', $stored->amount, 'a -10.00 stored price must reach the customer as -10.00');
        self::assertSame(CustomerPrice::STATUS_PRICED, $stored->status, 'below zero is still a price, not an absence');
        self::assertSame(-10.0, $stored->toFloat());

        self::assertSame('-5.00', $scope->priceFor($flatNegative)->amount);
        self::assertSame('-15.00', $scope->priceFor($overDiscountedDollars)->amount);
        self::assertSame('-5.00', $scope->priceFor($overDiscountedPercent)->amount);
    }

    public function testProductWithNoPriceListAndNoBasePriceIsUnpriced(): void
    {
        $company = $this->newCompany('ACME');
        $product = $this->newProduct('SKU-1');
        $this->em->flush();

        $price = $this->resolver->for($company, 'Nowhere')->priceFor($product);
        self::assertSame(CustomerPrice::STATUS_UNPRICED, $price->status);
        self::assertNull($price->amount);
        self::assertNull($price->toFloat());
    }

    // --- guest pricing -------------------------------------------------------------------------

    public function testGuestPricesAgainstTheNamedRegionsGuestPriceList(): void
    {
        $westGuest = $this->newPriceList('West guest');
        $eastGuest = $this->newPriceList('East guest');
        $this->newRegion('West', guestVisible: true, guestPriceList: $westGuest);
        $this->newRegion('East', guestVisible: true, guestPriceList: $eastGuest);

        $product = $this->newProduct('SKU-1', '100.00');
        $this->newPricing($product, $westGuest, 'Number', '70');
        $this->newPricing($product, $eastGuest, 'Number', '75');
        $this->em->flush();

        self::assertSame('70.00', $this->resolver->for(null, 'West')->priceFor($product)->amount);
        self::assertSame('75.00', $this->resolver->for(null, 'East')->priceFor($product)->amount);
    }

    public function testGuestGetsNoPriceListForARegionThatIsNotGuestVisible(): void
    {
        $hiddenList = $this->newPriceList('Staff only');
        $this->newRegion('Private', guestVisible: false, guestPriceList: $hiddenList);

        $product = $this->newProduct('SKU-1', '100.00');
        $this->newPricing($product, $hiddenList, 'Number', '10');
        $this->em->flush();

        $scope = $this->resolver->for(null, 'Private');
        self::assertNull($scope->priceList());
        // The region's own list is not applied, so the guest sees the undiscounted base price.
        self::assertSame('100.00', $scope->priceFor($product)->amount);
    }

    public function testGuestWithNoRegionNameResolvesOnlyWhenExactlyOneRegionIsGuestVisible(): void
    {
        $guestList = $this->newPriceList('Guest list');
        $this->newRegion('West', guestVisible: true, guestPriceList: $guestList);
        $this->newRegion('Staff', guestVisible: false);

        $product = $this->newProduct('SKU-1', '100.00');
        $this->newPricing($product, $guestList, 'Number', '70');
        $this->em->flush();

        self::assertSame('70.00', $this->resolver->for(null, null)->priceFor($product)->amount);

        $this->newRegion('East', guestVisible: true, guestPriceList: $this->newPriceList('East guest'));
        $this->em->flush();

        // A second guest-visible region makes "which price does a visitor see" a guess, so no list
        // resolves at all and the product falls back to its base price.
        self::assertSame('100.00', $this->resolver->for(null, null)->priceFor($product)->amount);
    }

    public function testGuestVisibleFulfillmentRegionsAreReturnedByName(): void
    {
        $this->newRegion('West', guestVisible: true);
        $this->newRegion('East', guestVisible: true);
        $this->newRegion('Staff', guestVisible: false);
        $this->em->flush();

        self::assertSame(
            ['East', 'West'],
            array_map(static fn (FulfillmentRegion $r): string => $r->getName(), $this->resolver->guestVisibleFulfillmentRegions()),
        );
    }

    // --- "No Price" ----------------------------------------------------------------------------

    public function testNoPriceRuleIsReportedAsItsOwnStatusRatherThanAsAMissingPrice(): void
    {
        $priceList = $this->newPriceList('List');
        $company = $this->newCompany('ACME');
        $this->activate($company, $this->newRegion('West'), $priceList);

        // A base price the rule deliberately overrides — "No Price" must beat it, not fall through.
        $noPrice = $this->newProduct('NOPRICE', '100.00');
        $this->newPricing($noPrice, $priceList, 'No Price');

        $unconfigured = $this->newProduct('UNCONF');
        $this->em->flush();

        $scope = $this->resolver->for($company, 'West');

        $deliberate = $scope->priceFor($noPrice);
        self::assertSame(CustomerPrice::STATUS_NO_PRICE, $deliberate->status);
        self::assertTrue($deliberate->isNoPrice());
        self::assertFalse($deliberate->isPriced());
        self::assertNull($deliberate->amount);
        self::assertTrue($scope->isNoPrice($noPrice));

        // Both read as a blank price, but only one of them routes a cart to an Estimate — which is
        // exactly the distinction a nullable amount could not carry.
        $nothingConfigured = $scope->priceFor($unconfigured);
        self::assertSame(CustomerPrice::STATUS_UNPRICED, $nothingConfigured->status);
        self::assertFalse($nothingConfigured->isNoPrice());
        self::assertNull($nothingConfigured->amount);
        self::assertFalse($scope->isNoPrice($unconfigured));
    }

    public function testGuestCanAlsoBeGivenNoPrice(): void
    {
        $guestList = $this->newPriceList('Guest list');
        $this->newRegion('West', guestVisible: true, guestPriceList: $guestList);

        $product = $this->newProduct('SKU-1', '100.00');
        $this->newPricing($product, $guestList, 'No Price');
        $this->em->flush();

        self::assertTrue($this->resolver->for(null, 'West')->priceFor($product)->isNoPrice());
    }

    // --- scope memoization ---------------------------------------------------------------------

    /**
     * The reason the scope exists: a document is priced against one price list. Repointing the
     * company's region at a different list mid-scope must not change what the scope prices against,
     * because it must not have gone back for it — while a scope built afterwards sees the new list.
     */
    public function testPriceListIsResolvedOncePerScopeNotOncePerProduct(): void
    {
        $original = $this->newPriceList('Original list');
        $replacement = $this->newPriceList('Replacement list');
        $company = $this->newCompany('ACME');
        $row = $this->activate($company, $this->newRegion('West'), $original);

        $first = $this->newProduct('FIRST', '100.00');
        $second = $this->newProduct('SECOND', '100.00');
        foreach ([$first, $second] as $product) {
            $this->newPricing($product, $original, 'Number', '80');
            $this->newPricing($product, $replacement, 'Number', '20');
        }
        $this->em->flush();

        $scope = $this->resolver->for($company, 'West');
        self::assertSame('80.00', $scope->priceFor($first)->amount);

        $row->setPriceList($replacement);
        $this->em->flush();

        self::assertSame('80.00', $scope->priceFor($second)->amount, 'second line re-resolved the price list');
        self::assertSame($original, $scope->priceList());

        // Not merely stale: the next scope picks the change up, so the memoization is scoped to the
        // document rather than cached on the service.
        self::assertSame($replacement, $this->resolver->for($company, 'West')->priceList());
    }

    public function testScopeRemembersThatNoPriceListResolved(): void
    {
        $company = $this->newCompany('ACME');
        $region = $this->newRegion('West');
        $this->em->flush();

        $scope = $this->resolver->for($company, 'West');
        self::assertNull($scope->priceList());

        $this->activate($company, $region, $this->newPriceList('Late list'));
        $this->em->flush();

        // The null answer is memoized too — otherwise the second half of a document would price
        // against a list the first half never saw.
        self::assertNull($scope->priceList());
        self::assertNotNull($this->resolver->for($company, 'West')->priceList());
    }

    // --- purchasable product lookup ------------------------------------------------------------

    public function testFindPurchasableProductAppliesCatalogVisibilityAndTheHideRule(): void
    {
        $priceList = $this->newPriceList('List');
        $company = $this->newCompany('ACME');
        $other = $this->newCompany('OTHER');
        $this->activate($company, $this->newRegion('West'), $priceList);

        $normal = $this->newProduct('NORMAL', '100.00');
        $hidden = $this->newProduct('HIDDEN', '100.00');
        $this->newPricing($hidden, $priceList, 'Hide');
        $inactive = $this->newProduct('INACTIVE', '100.00')->deactivate();
        // Draft is the third status (queue item 9) and the reason this lookup asks `= 'Active'`
        // rather than `<> 'Inactive'`: a product whose base unit nobody resolved must not be
        // buyable, and "not Inactive" stopped meaning "Active" the moment Draft existed.
        $draft = $this->newProduct('DRAFT', '100.00')->holdAsDraft();
        $stray = $this->newProduct('STRAY', '100.00');
        $invisible = $this->newProduct('INVISIBLE', '100.00')->setVisible(false);
        $deleted = $this->newProduct('DELETED', '100.00')->setDeleted(true);
        $privateToOther = $this->newProduct('PRIVATE', '100.00');
        $privateToOther->addPrivateCompany($other);
        $this->em->flush();

        // Written straight into the column, which is the only way a value the enum does not know
        // can arrive now, and how the real ones arrived before it existed.
        $this->em->getConnection()->executeStatement(
            'UPDATE product_core SET status = ? WHERE sku = ?',
            ['Waiting for Stock', 'STRAY'],
        );
        $this->em->clear();

        $scope = $this->resolver->for($company, 'West');
        self::assertSame('NORMAL', $scope->findPurchasableProduct($company, 'NORMAL')?->getSku());
        self::assertNull($scope->findPurchasableProduct($company, 'HIDDEN'));
        self::assertNull($scope->findPurchasableProduct($company, 'INACTIVE'));
        self::assertNull($scope->findPurchasableProduct($company, 'DRAFT'));
        self::assertNull($scope->findPurchasableProduct($company, 'STRAY'), 'a status the enum does not know is not purchasable either');
        self::assertNull($scope->findPurchasableProduct($company, 'INVISIBLE'));
        self::assertNull($scope->findPurchasableProduct($company, 'DELETED'));
        self::assertNull($scope->findPurchasableProduct($company, 'PRIVATE'));
        self::assertSame('PRIVATE', $scope->findPurchasableProduct($other, 'PRIVATE')?->getSku());

        // A guest sees nothing that is private to anyone.
        self::assertNull($this->resolver->for(null, 'West')->findPurchasableProduct(null, 'PRIVATE'));
    }

    // --- company for pricing -------------------------------------------------------------------

    public function testCompanyForPricingReadsTheUsersCompanyFromTheDatabase(): void
    {
        $company = $this->newCompany('ACME');
        $user = $this->newCustomerUser('buyer@example.com', $company);
        $this->em->flush();

        self::assertSame($company, $this->resolver->companyForPricing($user));
    }

    public function testCompanyForPricingFallsBackToAnActiveCompanyMatchedByPrimaryEmail(): void
    {
        $company = $this->newCompany('ACME', 'Buyer@Example.com');
        $unlinked = $this->newCustomerUser('buyer@example.com', null);
        $this->em->flush();

        self::assertSame($company, $this->resolver->companyForPricing($unlinked));
    }

    public function testCompanyForPricingIgnoresAnInactiveEmailMatchAndNonCustomerUsers(): void
    {
        $this->newCompany('ACME', 'buyer@example.com')->setStatus('Inactive', DocumentActor::system());
        $unlinked = $this->newCustomerUser('buyer@example.com', null);
        $this->em->flush();

        self::assertNull($this->resolver->companyForPricing($unlinked));
        self::assertNull($this->resolver->companyForPricing(null));
        self::assertNull($this->resolver->companyForPricing(new \stdClass()));
    }

    // --- region entity lookup ------------------------------------------------------------------

    public function testResolveFulfillmentRegionEntityMatchesCaseInsensitivelyAndRejectsBlanks(): void
    {
        $west = $this->newRegion('West');
        $this->em->flush();

        self::assertSame($west, $this->resolver->resolveFulfillmentRegionEntity('wEsT'));
        self::assertNull($this->resolver->resolveFulfillmentRegionEntity('Nowhere'));
        self::assertNull($this->resolver->resolveFulfillmentRegionEntity('  '));
        self::assertNull($this->resolver->resolveFulfillmentRegionEntity(null));
    }

    // --- helpers -------------------------------------------------------------------------------

    private function newPriceList(string $name): PriceList
    {
        $priceList = (new PriceList())->setName($name);
        $this->em->persist($priceList);

        return $priceList;
    }

    private function newRegion(string $name, bool $guestVisible = false, ?PriceList $guestPriceList = null): FulfillmentRegion
    {
        $region = (new FulfillmentRegion())
            ->setName($name)
            ->setGuestVisible($guestVisible)
            ->setGuestPriceList($guestPriceList);
        $this->em->persist($region);

        return $region;
    }

    private function newCompany(string $code, ?string $primaryEmail = null): Company
    {
        $company = (new Company())->setName('Company ' . $code)->setCode($code)->setPrimaryEmail($primaryEmail);
        $this->em->persist($company);

        return $company;
    }

    private function newCustomerUser(string $email, ?Company $company): CustomerUser
    {
        $user = (new CustomerUser())->setEmail($email)->setCompany($company);
        $this->em->persist($user);

        return $user;
    }

    private function activate(Company $company, FulfillmentRegion $region, ?PriceList $priceList): CompanyFulfillmentRegion
    {
        $row = (new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($region)
            ->setPriceList($priceList)
            ->setStatus('Active');
        $this->em->persist($row);

        return $row;
    }

    private function newProduct(string $sku, ?string $defaultPrice = null, ?string $originalPrice = null): ProductCore
    {
        $product = (new ProductCore())
            ->setSku($sku)
            ->setName('Product ' . $sku)
            ->setDefaultPrice($defaultPrice)
            ->setOriginalPrice($originalPrice)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($product);

        return $product;
    }

    private function newPricing(ProductCore $product, PriceList $priceList, ?string $ruleType = null, ?string $ruleValue = null, string $price = '0.00'): ProductPricing
    {
        $pricing = (new ProductPricing())
            ->setProduct($product)
            ->setPriceList($priceList)
            ->setRuleType($ruleType)
            ->setRuleValue($ruleValue)
            ->setPrice($price);
        $this->em->persist($pricing);

        return $pricing;
    }
}
