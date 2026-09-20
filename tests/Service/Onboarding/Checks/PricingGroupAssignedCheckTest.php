<?php

declare(strict_types=1);

namespace App\Tests\Service\Onboarding\Checks;

use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Service\Onboarding\Checks\PricingGroupAssignedCheck;
use App\Tests\DoctrineIntegrationTestCase;

/** #426: "check at least 1 pricing group, assigned to 1 location". */
final class PricingGroupAssignedCheckTest extends DoctrineIntegrationTestCase
{
    private function makeCompany(string $name): Company
    {
        $company = (new Company())->setName($name);
        $this->em->persist($company);

        return $company;
    }

    private function makeRegion(string $name): FulfillmentRegion
    {
        $region = (new FulfillmentRegion())->setName($name);
        $this->em->persist($region);

        return $region;
    }

    private function makePriceList(string $name): PriceList
    {
        $priceList = (new PriceList())->setName($name);
        $this->em->persist($priceList);

        return $priceList;
    }

    public function testNothingConfiguredAtAllFails(): void
    {
        self::assertFalse((new PricingGroupAssignedCheck($this->em))->run()->passed);
    }

    public function testAPriceListThatIsNeverAssignedToAnyLocationStillFails(): void
    {
        // A Pricing Group existing in isolation is not enough — the issue asks for one ASSIGNED
        // to a location. Nothing joins them yet, so this must fail.
        $this->makePriceList('Wholesale Tier 1');
        $this->em->flush();

        self::assertFalse((new PricingGroupAssignedCheck($this->em))->run()->passed);
    }

    public function testACompanyRegionMappingWithNoPriceListAssignedFails(): void
    {
        $company = $this->makeCompany('Acme Co');
        $region = $this->makeRegion('West Coast');
        $this->em->flush();

        $mapping = (new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($region)
            ->setPriceList(null);
        $this->em->persist($mapping);
        $this->em->flush();

        self::assertFalse((new PricingGroupAssignedCheck($this->em))->run()->passed);
    }

    public function testAPriceListActuallyAssignedToACompanyLocationPasses(): void
    {
        $company = $this->makeCompany('Acme Co');
        $region = $this->makeRegion('West Coast');
        $priceList = $this->makePriceList('Wholesale Tier 1');
        $this->em->flush();

        $mapping = (new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($region)
            ->setPriceList($priceList);
        $this->em->persist($mapping);
        $this->em->flush();

        self::assertTrue((new PricingGroupAssignedCheck($this->em))->run()->passed);
    }
}
