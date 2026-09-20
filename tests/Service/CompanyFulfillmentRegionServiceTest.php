<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Service\CompanyFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;

final class CompanyFulfillmentRegionServiceTest extends DoctrineIntegrationTestCase
{
    private CompanyFulfillmentRegionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CompanyFulfillmentRegionService($this->em);
    }

    private function newCompany(string $code): Company
    {
        $company = (new Company())->setName('Company ' . $code)->setCode($code);
        $this->em->persist($company);

        return $company;
    }

    private function newRegion(string $name): FulfillmentRegion
    {
        $region = (new FulfillmentRegion())->setName($name);
        $this->em->persist($region);

        return $region;
    }

    public function testBackfillForNewCompanyCreatesInactiveRowForEveryExistingRegion(): void
    {
        $regionA = $this->newRegion('West');
        $regionB = $this->newRegion('East');
        $this->em->flush();

        $company = $this->newCompany('ACME');
        $this->em->flush();

        $this->service->backfillForNewCompany($company);
        $this->em->flush();

        $rows = $this->em->getRepository(CompanyFulfillmentRegion::class)->findBy(['company' => $company]);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('Inactive', $row->getStatus());
            self::assertContains($row->getFulfillmentRegion(), [$regionA, $regionB]);
        }
    }

    public function testBackfillForNewCompanyDoesNotDuplicateExistingRow(): void
    {
        $region = $this->newRegion('West');
        $company = $this->newCompany('ACME');
        $this->em->flush();

        $existing = (new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($region)
            ->setStatus('Active');
        $this->em->persist($existing);
        $this->em->flush();

        $this->service->backfillForNewCompany($company);
        $this->em->flush();

        $rows = $this->em->getRepository(CompanyFulfillmentRegion::class)->findBy(['company' => $company]);
        self::assertCount(1, $rows);
        self::assertSame('Active', $rows[0]->getStatus());
    }

    public function testBackfillForNewRegionCreatesInactiveRowForEveryExistingCompany(): void
    {
        $companyA = $this->newCompany('ACME');
        $companyB = $this->newCompany('WIDGETCO');
        $this->em->flush();

        $region = $this->newRegion('West');
        $this->em->flush();

        $this->service->backfillForNewRegion($region);
        $this->em->flush();

        $rows = $this->em->getRepository(CompanyFulfillmentRegion::class)->findBy(['fulfillmentRegion' => $region]);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('Inactive', $row->getStatus());
            self::assertContains($row->getCompany(), [$companyA, $companyB]);
        }
    }

    public function testBackfillForNewRegionDoesNotDuplicateExistingRow(): void
    {
        $company = $this->newCompany('ACME');
        $region = $this->newRegion('West');
        $this->em->flush();

        $existing = (new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($region)
            ->setStatus('Active');
        $this->em->persist($existing);
        $this->em->flush();

        $this->service->backfillForNewRegion($region);
        $this->em->flush();

        $rows = $this->em->getRepository(CompanyFulfillmentRegion::class)->findBy(['fulfillmentRegion' => $region]);
        self::assertCount(1, $rows);
        self::assertSame('Active', $rows[0]->getStatus());
    }

    public function testValidateActivationRejectsActiveWithoutPriceList(): void
    {
        $errors = $this->service->validateActivation(true, false, 'West');

        self::assertSame(['Select a price list before activating "West".'], $errors);
    }

    public function testValidateActivationAllowsActiveWithPriceList(): void
    {
        self::assertSame([], $this->service->validateActivation(true, true, 'West'));
    }

    public function testValidateActivationAllowsInactiveWithoutPriceList(): void
    {
        self::assertSame([], $this->service->validateActivation(false, false, 'West'));
    }

    public function testValidateDelegatesToValidateActivationUsingRowState(): void
    {
        $company = $this->newCompany('ACME');
        $region = $this->newRegion('West');
        $this->em->flush();

        $row = (new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($region)
            ->setStatus('Active');

        self::assertSame(['Select a price list before activating "West".'], $this->service->validate($row));

        $priceList = (new PriceList())->setName('Standard');
        $this->em->persist($priceList);
        $this->em->flush();

        $row->setPriceList($priceList);
        self::assertSame([], $this->service->validate($row));
    }
}
