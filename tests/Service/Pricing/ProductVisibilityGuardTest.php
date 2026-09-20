<?php

declare(strict_types=1);

namespace App\Tests\Service\Pricing;

use App\Entity\Company;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use App\Service\Pricing\ProductVisibilityGuard;
use App\Tests\DoctrineIntegrationTestCase;

final class ProductVisibilityGuardTest extends DoctrineIntegrationTestCase
{
    private ProductVisibilityGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new ProductVisibilityGuard($this->em);
    }

    private function newProduct(): ProductCore
    {
        $product = (new ProductCore())->setSku('SKU-' . uniqid())->setName('Product');
        $this->em->persist($product);

        return $product;
    }

    public function testAPlainProductIsEligible(): void
    {
        $product = $this->newProduct();
        $this->em->flush();

        self::assertTrue($this->guard->isEligible($product));
    }

    public function testADeactivatedProductIsNotEligible(): void
    {
        $product = $this->newProduct()->deactivate();
        $this->em->flush();

        self::assertFalse($this->guard->isEligible($product));
    }

    public function testANotVisibleProductIsNotEligible(): void
    {
        $product = $this->newProduct()->setVisible(false);
        $this->em->flush();

        self::assertFalse($this->guard->isEligible($product));
    }

    public function testADeletedProductIsNotEligible(): void
    {
        $product = $this->newProduct()->setDeleted(true);
        $this->em->flush();

        self::assertFalse($this->guard->isEligible($product));
    }

    public function testAPrivateProductIsNotEligibleWithNoCompanyContext(): void
    {
        $company = (new Company())->setName('Only Them')->setCode('OT-' . uniqid());
        $this->em->persist($company);
        $product = $this->newProduct();
        $product->addPrivateCompany($company);
        $this->em->flush();

        self::assertFalse($this->guard->isEligible($product));
    }

    public function testAPrivateProductIsEligibleForTheCompanyItIsPrivateTo(): void
    {
        $company = (new Company())->setName('Only Them')->setCode('OT-' . uniqid());
        $this->em->persist($company);
        $product = $this->newProduct();
        $product->addPrivateCompany($company);
        $this->em->flush();

        self::assertTrue($this->guard->isEligible($product, null, $company));
    }

    public function testAPrivateProductIsNotEligibleForADifferentCompany(): void
    {
        $owner = (new Company())->setName('Owner')->setCode('OWN-' . uniqid());
        $other = (new Company())->setName('Other')->setCode('OTH-' . uniqid());
        $this->em->persist($owner);
        $this->em->persist($other);
        $product = $this->newProduct();
        $product->addPrivateCompany($owner);
        $this->em->flush();

        self::assertFalse($this->guard->isEligible($product, null, $other));
    }

    public function testAProductHiddenOnThePriceListIsNotEligible(): void
    {
        $priceList = (new PriceList())->setName('List-' . uniqid());
        $this->em->persist($priceList);
        $product = $this->newProduct();
        $this->em->persist((new ProductPricing())->setProduct($product)->setPriceList($priceList)->setRuleType('Hide'));
        $this->em->flush();

        self::assertFalse($this->guard->isEligible($product, $priceList));
    }

    public function testAProductWithANoPriceRuleIsStillEligible(): void
    {
        $priceList = (new PriceList())->setName('List-' . uniqid());
        $this->em->persist($priceList);
        $product = $this->newProduct();
        $this->em->persist((new ProductPricing())->setProduct($product)->setPriceList($priceList)->setRuleType('No Price'));
        $this->em->flush();

        self::assertTrue($this->guard->isEligible($product, $priceList), '"No Price" is not "Hide" — the product still shows, just with no price');
    }

    public function testHideOnADifferentPriceListDoesNotAffectThisOne(): void
    {
        $hiddenOn = (new PriceList())->setName('Hidden-' . uniqid());
        $other = (new PriceList())->setName('Other-' . uniqid());
        $this->em->persist($hiddenOn);
        $this->em->persist($other);
        $product = $this->newProduct();
        $this->em->persist((new ProductPricing())->setProduct($product)->setPriceList($hiddenOn)->setRuleType('Hide'));
        $this->em->flush();

        self::assertTrue($this->guard->isEligible($product, $other));
    }
}
